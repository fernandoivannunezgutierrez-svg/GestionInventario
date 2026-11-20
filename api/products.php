<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function enrichProductRow(array $row, bool $isSeller = false): array
{
    $stock = (float)$row['stock'];
    $minStock = (float)$row['min_stock'];
    $cost = (float)$row['cost_price'];
    $net = (float)$row['sale_price_net'];
    $ivaRate = (float)$row['iva_rate'];
    $otherRate = (float)$row['other_tax_rate'];
    $final = (float)$row['sale_price_final'];

    $base = [
        'id' => (int)$row['id'],
        'sku' => $row['sku'],
        'name' => $row['name'],
        'category_id' => $row['category_id'] ? (int)$row['category_id'] : null,
        'category_name' => $row['category_name'] ?? null,
        'category_color' => $row['category_color'] ?? null,
        'measure_type' => $row['measure_type'],
        'unit_label' => $row['unit_label'],
        'allow_decimals' => (bool)$row['allow_decimals'],
        'stock' => $stock,
        'min_stock' => $minStock,
        'iva_rate' => $ivaRate,
        'other_tax_rate' => $otherRate,
        'sale_price_final' => $final,
        'is_low_stock' => $stock <= $minStock,
    ];

    if ($isSeller) {
        return $base;
    }

    $unitProfit = round($net - $cost, 2);
    $unitIva = round($net * ($ivaRate / 100.0), 2);
    $unitOtherTax = round($net * ($otherRate / 100.0), 2);
    $stockCostValue = round($stock * $cost, 2);
    $stockSaleValue = round($stock * $final, 2);
    $stockPotentialProfit = round($stock * $unitProfit, 2);
    $marginPct = $cost > 0 ? round(($unitProfit / $cost) * 100, 1) : 0.0;

    return array_merge($base, [
        'cost_price' => $cost,
        'sale_price_net' => $net,
        'unit_iva' => $unitIva,
        'unit_other_tax' => $unitOtherTax,
        'unit_profit' => $unitProfit,
        'margin_pct' => $marginPct,
        'stock_cost_value' => $stockCostValue,
        'stock_sale_value' => $stockSaleValue,
        'stock_potential_profit' => $stockPotentialProfit,
    ]);
}

try {
    $pdo = getPDO();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isSeller = $user['role'] === 'seller';

    if ($method === 'GET') {
        $search = trim((string)($_GET['search'] ?? ''));
        $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
        $storeId = resolveStoreId($_GET['store_id'] ?? null, $user, true);

        $params = [];
        if ($storeId !== null) {
            $sql = 'SELECT p.*, c.name AS category_name, c.color AS category_color,
                           COALESCE(ss.stock, 0) AS stock,
                           COALESCE(ss.min_stock, p.min_stock) AS min_stock
                    FROM products p
                    LEFT JOIN categories c ON c.id = p.category_id
                    LEFT JOIN store_stock ss ON ss.product_id = p.id AND ss.store_id = ?
                    WHERE p.is_active = 1';
            $params[] = $storeId;
        } else {
            $sql = 'SELECT p.*, c.name AS category_name, c.color AS category_color,
                           COALESCE((SELECT SUM(ss.stock) FROM store_stock ss WHERE ss.product_id = p.id), p.stock) AS stock,
                           p.min_stock AS min_stock
                    FROM products p
                    LEFT JOIN categories c ON c.id = p.category_id
                    WHERE p.is_active = 1';
        }

        if ($search !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY p.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(fn($r) => enrichProductRow($r, $isSeller), $stmt->fetchAll());

        jsonResponse(['ok' => true, 'products' => $rows, 'store_id' => $storeId]);
    }

    if ($isSeller) {
        jsonError('Solo el administrador puede crear o editar productos.', 403);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            jsonError('El nombre del producto es obligatorio.', 422);
        }

        $storeId = resolveStoreId(isset($body['store_id']) ? (string)$body['store_id'] : null, $user, false) ?? 1;
        $sku = trim((string)($body['sku'] ?? ''));
        $sku = $sku === '' ? null : $sku;
        $categoryId = isset($body['category_id']) && $body['category_id'] !== '' ? (int)$body['category_id'] : null;

        $allowedTypes = ['unit', 'weight', 'volume', 'length', 'pack'];
        $measureType = in_array($body['measure_type'] ?? 'unit', $allowedTypes, true)
            ? (string)$body['measure_type']
            : 'unit';
        $unitLabel = trim((string)($body['unit_label'] ?? 'unid.'));
        if ($unitLabel === '') {
            $unitLabel = 'unid.';
        }
        $allowDecimals = !empty($body['allow_decimals']) ? 1 : 0;

        $stock = max(0, (float)($body['stock'] ?? 0));
        $minStock = max(0, (float)($body['min_stock'] ?? 5));
        $costPrice = max(0, (float)($body['cost_price'] ?? 0));
        $ivaRate = max(0, min(100, (float)($body['iva_rate'] ?? 19)));
        $otherTaxRate = max(0, min(100, (float)($body['other_tax_rate'] ?? 0)));

        $saleNet = isset($body['sale_price_net']) && $body['sale_price_net'] !== ''
            ? (float)$body['sale_price_net']
            : null;
        $saleFinal = isset($body['sale_price_final']) && $body['sale_price_final'] !== ''
            ? (float)$body['sale_price_final']
            : null;

        $pricing = calculatePricing($costPrice, $ivaRate, $otherTaxRate, $saleNet, $saleFinal);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO products
             (sku, name, category_id, measure_type, unit_label, allow_decimals, stock, min_stock,
              cost_price, sale_price_net, iva_rate, other_tax_rate, sale_price_final)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sku,
            $name,
            $categoryId,
            $measureType,
            $unitLabel,
            $allowDecimals,
            $stock,
            $minStock,
            $pricing['cost_price'],
            $pricing['sale_price_net'],
            $pricing['iva_rate'],
            $pricing['other_tax_rate'],
            $pricing['sale_price_final'],
        ]);
        $productId = (int)$pdo->lastInsertId();

        // Create store_stock rows for all active stores
        $activeStores = $pdo->query('SELECT id FROM stores WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
        $ssIns = $pdo->prepare('INSERT INTO store_stock (store_id, product_id, stock, min_stock) VALUES (?, ?, ?, ?)');
        foreach ($activeStores as $sid) {
            $initialQty = ((int)$sid === $storeId) ? $stock : 0.0;
            $ssIns->execute([(int)$sid, $productId, $initialQty, $minStock]);
        }

        if ($stock > 0) {
            $moneyMovement = round($stock * $pricing['cost_price'], 2);
            $movStmt = $pdo->prepare(
                'INSERT INTO inventory_movements
                 (store_id, user_id, product_id, movement_type, quantity, stock_before, stock_after,
                  unit_cost, unit_price_final, money_movement, iva_amount, other_tax_amount,
                  net_amount, profit_amount, counterparty, notes)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?)'
            );
            $movStmt->execute([
                $storeId,
                $user['id'],
                $productId,
                'purchase',
                $stock,
                $stock,
                $pricing['cost_price'],
                $pricing['sale_price_final'],
                $moneyMovement,
                $moneyMovement,
                'Stock inicial',
                'Registro inicial de producto',
            ]);
        }

        $pdo->commit();

        $fetch = $pdo->prepare(
            'SELECT p.*, c.name AS category_name, c.color AS category_color,
                    COALESCE(ss.stock, p.stock) AS stock,
                    COALESCE(ss.min_stock, p.min_stock) AS min_stock
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN store_stock ss ON ss.product_id = p.id AND ss.store_id = ?
             WHERE p.id = ?'
        );
        $fetch->execute([$storeId, $productId]);
        $product = enrichProductRow($fetch->fetch(), false);

        jsonResponse(['ok' => true, 'product' => $product], 201);
    }

    if ($method === 'PUT') {
        $body = getJsonBody();
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID de producto inválido.', 422);
        }

        $existingStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            jsonError('Producto no encontrado.', 404);
        }

        $storeId = resolveStoreId(isset($body['store_id']) ? (string)$body['store_id'] : null, $user, true);
        $name = trim((string)($body['name'] ?? $existing['name']));
        if ($name === '') {
            jsonError('El nombre del producto es obligatorio.', 422);
        }
        $sku = array_key_exists('sku', $body) ? trim((string)$body['sku']) : (string)$existing['sku'];
        $sku = $sku === '' ? null : $sku;
        $categoryId = array_key_exists('category_id', $body)
            ? ($body['category_id'] !== '' && $body['category_id'] !== null ? (int)$body['category_id'] : null)
            : $existing['category_id'];

        $allowedTypes = ['unit', 'weight', 'volume', 'length', 'pack'];
        $measureType = in_array($body['measure_type'] ?? $existing['measure_type'], $allowedTypes, true)
            ? (string)($body['measure_type'] ?? $existing['measure_type'])
            : (string)$existing['measure_type'];
        $unitLabel = trim((string)($body['unit_label'] ?? $existing['unit_label']));
        if ($unitLabel === '') {
            $unitLabel = 'unid.';
        }
        $allowDecimals = array_key_exists('allow_decimals', $body)
            ? (!empty($body['allow_decimals']) ? 1 : 0)
            : (int)$existing['allow_decimals'];
        $minStock = max(0, (float)($body['min_stock'] ?? $existing['min_stock']));

        $costPrice = max(0, (float)($body['cost_price'] ?? $existing['cost_price']));
        $ivaRate = max(0, min(100, (float)($body['iva_rate'] ?? $existing['iva_rate'])));
        $otherTaxRate = max(0, min(100, (float)($body['other_tax_rate'] ?? $existing['other_tax_rate'])));

        $saleNet = isset($body['sale_price_net']) && $body['sale_price_net'] !== ''
            ? (float)$body['sale_price_net']
            : null;
        $saleFinal = isset($body['sale_price_final']) && $body['sale_price_final'] !== ''
            ? (float)$body['sale_price_final']
            : null;

        if ($saleNet === null && $saleFinal === null) {
            $saleNet = (float)$existing['sale_price_net'];
        }

        $pricing = calculatePricing($costPrice, $ivaRate, $otherTaxRate, $saleNet, $saleFinal);

        $stmt = $pdo->prepare(
            'UPDATE products
             SET sku = ?, name = ?, category_id = ?, measure_type = ?, unit_label = ?,
                 allow_decimals = ?, min_stock = ?, cost_price = ?, sale_price_net = ?,
                 iva_rate = ?, other_tax_rate = ?, sale_price_final = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $sku,
            $name,
            $categoryId,
            $measureType,
            $unitLabel,
            $allowDecimals,
            $minStock,
            $pricing['cost_price'],
            $pricing['sale_price_net'],
            $pricing['iva_rate'],
            $pricing['other_tax_rate'],
            $pricing['sale_price_final'],
            $id,
        ]);

        if ($storeId !== null) {
            $pdo->prepare('UPDATE store_stock SET min_stock = ? WHERE store_id = ? AND product_id = ?')
                ->execute([$minStock, $storeId, $id]);
        } else {
            $pdo->prepare('UPDATE store_stock SET min_stock = ? WHERE product_id = ?')
                ->execute([$minStock, $id]);
        }

        $fetch = $pdo->prepare(
            'SELECT p.*, c.name AS category_name, c.color AS category_color
             FROM products p LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = ?'
        );
        $fetch->execute([$id]);
        $product = enrichProductRow($fetch->fetch(), false);

        jsonResponse(['ok' => true, 'product' => $product]);
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            $body = getJsonBody();
            $id = (int)($body['id'] ?? 0);
        }
        if ($id <= 0) {
            jsonError('ID de producto inválido.', 422);
        }

        $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(['ok' => true]);
    }

    jsonError('Método no permitido.', 405);
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        jsonError('Ya existe un producto con ese código SKU.', 409);
    }
    jsonError('Error en productos: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    jsonError('Error en productos: ' . $e->getMessage(), 500);
}
