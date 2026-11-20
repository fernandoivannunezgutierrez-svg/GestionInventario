<?php
declare(strict_types=1);

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
        return $pdo;
    } catch (PDOException $e) {
        // If database does not exist yet (error 1049), bootstrap it from schema.sql
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
            return $pdo;
        }
        throw $e;
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

/**
 * Computes net, IVA, other tax, and final price given either net or final price.
 */
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
