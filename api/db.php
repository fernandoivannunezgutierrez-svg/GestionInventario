<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'gestion_inventario';
const DB_USER = 'root';
const DB_PASS = '';

function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        ensureSchemaUpgrades($pdo);
        return $pdo;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1049) {
            $serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
            $bootstrapPdo = new PDO($serverDsn, DB_USER, DB_PASS, $options);
            $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
            if ($schemaSql === false) {
                throw new RuntimeException('No se pudo leer schema.sql');
            }
            $bootstrapPdo->exec($schemaSql);
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            ensureSchemaUpgrades($pdo);
            return $pdo;
        }
        throw $e;
    }
}

function ensureSchemaUpgrades(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $hasStores = $pdo->query("SHOW TABLES LIKE 'stores'")->fetchColumn();
    if (!$hasStores) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `stores` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `name` VARCHAR(120) NOT NULL,
              `address` VARCHAR(200) NULL,
              `phone` VARCHAR(40) NULL,
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("INSERT IGNORE INTO `stores` (`id`, `name`, `address`) VALUES (1, 'Tienda 1 (Principal)', 'Casa Matriz')");
    }

    $hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if (!$hasUsers) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `store_id` INT NULL,
              `username` VARCHAR(60) NOT NULL UNIQUE,
              `full_name` VARCHAR(120) NOT NULL,
              `password_hash` VARCHAR(255) NOT NULL,
              `card_code` VARCHAR(64) NULL UNIQUE,
              `role` ENUM('admin', 'seller') NOT NULL DEFAULT 'seller',
              `is_active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT `fk_users_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    $hasStoreStock = $pdo->query("SHOW TABLES LIKE 'store_stock'")->fetchColumn();
    if (!$hasStoreStock) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `store_stock` (
              `store_id` INT NOT NULL,
              `product_id` INT NOT NULL,
              `stock` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
              `min_stock` DECIMAL(12,3) NOT NULL DEFAULT 5.000,
              PRIMARY KEY (`store_id`, `product_id`),
              CONSTRAINT `fk_store_stock_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_store_stock_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("INSERT IGNORE INTO `store_stock` (`store_id`, `product_id`, `stock`, `min_stock`)
                    SELECT 1, `id`, `stock`, `min_stock` FROM `products`");
    }

    // Upgrade sales columns if upgrading from pre-Fase 1
    $salesCols = $pdo->query("SHOW COLUMNS FROM `sales`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('store_id', $salesCols, true)) {
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `store_id` INT NOT NULL DEFAULT 1 AFTER `id`");
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `user_id` INT NULL AFTER `store_id`");
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `payment_method` ENUM('cash', 'card', 'transfer', 'mixed') NOT NULL DEFAULT 'cash' AFTER `customer_name`");
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `cash_received` DECIMAL(14,2) NULL AFTER `payment_method`");
        $pdo->exec("ALTER TABLE `sales` ADD COLUMN `cash_change` DECIMAL(14,2) NULL AFTER `cash_received`");
    }

    // Upgrade inventory_movements columns if upgrading from pre-Fase 1
    $movCols = $pdo->query("SHOW COLUMNS FROM `inventory_movements`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('store_id', $movCols, true)) {
        $pdo->exec("ALTER TABLE `inventory_movements` ADD COLUMN `store_id` INT NOT NULL DEFAULT 1 AFTER `id`");
        $pdo->exec("ALTER TABLE `inventory_movements` ADD COLUMN `user_id` INT NULL AFTER `store_id`");
    }

    // Seed default admin & seller if no users exist
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
    if ($userCount === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO `users` (`store_id`, `username`, `full_name`, `password_hash`, `card_code`, `role`)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([null, 'admin', 'Dueño / Administrador', password_hash('admin123', PASSWORD_DEFAULT), 'ADMIN-001', 'admin']);
        $stmt->execute([1, 'vendedor1', 'Vendedor Tienda 1', password_hash('1234', PASSWORD_DEFAULT), 'VEND-001', 'seller']);
    }
}

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $status = 400, array $extra = []): never
{
    jsonResponse(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonError('JSON inválido en el cuerpo de la petición.', 400);
    }
    return $decoded;
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireAuth(?string $requiredRole = null): array
{
    $user = currentUser();
    if (!$user) {
        jsonError('Sesión no iniciada. Por favor inicia sesión.', 401);
    }
    if ($requiredRole !== null && $user['role'] !== $requiredRole) {
        jsonError('No tienes permisos para realizar esta acción.', 403);
    }
    return $user;
}

/**
 * Resolves the effective store_id.
 * Sellers are always locked to their assigned store_id.
 * Admins can request 'all' (returns null for Global view) or a specific store_id.
 */
function resolveStoreId(?string $rawStoreId, array $user, bool $allowGlobal = true): ?int
{
    if ($user['role'] === 'seller') {
        $sid = (int)($user['store_id'] ?? 1);
        return $sid > 0 ? $sid : 1;
    }
    if ($rawStoreId === null || $rawStoreId === '' || $rawStoreId === 'all') {
        return $allowGlobal ? null : 1;
    }
    $sid = (int)$rawStoreId;
    return $sid > 0 ? $sid : ($allowGlobal ? null : 1);
}

function syncProductTotalStock(PDO $pdo, int $productId): void
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(stock), 0) FROM store_stock WHERE product_id = ?');
    $stmt->execute([$productId]);
    $total = (float)$stmt->fetchColumn();
    $upd = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
    $upd->execute([$total, $productId]);
}

function calculatePricing(
    float $costPrice,
    float $ivaRate,
    float $otherTaxRate,
    ?float $salePriceNet = null,
    ?float $salePriceFinal = null
): array {
    $taxMultiplier = 1.0 + (($ivaRate + $otherTaxRate) / 100.0);
    if ($salePriceNet !== null && $salePriceNet >= 0) {
        $net = round($salePriceNet, 2);
        $final = round($net * $taxMultiplier, 2);
    } elseif ($salePriceFinal !== null && $salePriceFinal >= 0) {
        $final = round($salePriceFinal, 2);
        $net = $taxMultiplier > 0 ? round($final / $taxMultiplier, 2) : $final;
    } else {
        $net = round($costPrice, 2);
        $final = round($net * $taxMultiplier, 2);
    }

    $ivaAmount = round($net * ($ivaRate / 100.0), 2);
    $otherTaxAmount = round($net * ($otherTaxRate / 100.0), 2);
    $unitProfit = round($net - $costPrice, 2);

    return [
        'cost_price' => round($costPrice, 2),
        'sale_price_net' => $net,
        'iva_rate' => round($ivaRate, 2),
        'other_tax_rate' => round($otherTaxRate, 2),
        'sale_price_final' => $final,
        'unit_iva' => $ivaAmount,
        'unit_other_tax' => $otherTaxAmount,
        'unit_profit' => $unitProfit,
    ];
}
