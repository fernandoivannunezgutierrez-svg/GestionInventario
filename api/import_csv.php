<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $user = requireAuth('admin');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonError('Método no permitido.', 405);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonError('Debe adjuntar un archivo CSV válido.', 422);
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $rawContent = file_get_contents($tmpPath);
    if ($rawContent === false || trim($rawContent) === '') {
        jsonError('El archivo CSV está vacío.', 422);
    }

    // Strip UTF-8 BOM if present
    $rawContent = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);
    $lines = preg_split("/\r\n|\n|\r/", $rawContent);
    if (!$lines || count($lines) < 2) {
        jsonError('El CSV debe tener cabecera y al menos una fila de datos.', 422);
    }

    // Auto-detect delimiter (; or ,)
    $firstLine = $lines[0];
    $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $rawContent);
    rewind($handle);

    $headers = fgetcsv($handle, 0, $delimiter);
    if (!$headers) {
        fclose($handle);
        jsonError('No se pudo leer la cabecera del CSV.', 422);
    }

    $colMap = [];
    foreach ($headers as $idx => $h) {
        $key = strtolower(trim((string)$h));
        $colMap[$key] = $idx;
    }

    $getVal = static function (array $row, array $aliases, string $default = '') use ($colMap): string {
        foreach ($aliases as $alias) {
            $k = strtolower($alias);
            if (isset($colMap[$k]) && isset($row[$colMap[$k]])) {
                return trim((string)$row[$colMap[$k]]);
            }
        }
        return $default;
    };

    $storeId = resolveStoreId($_POST['store_id'] ?? null, $user, false) ?? 1;
    $activeStores = $pdo->query('SELECT id FROM stores WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);

    // Cache existing categories
    $catCache = [];
    foreach ($pdo->query('SELECT id, name FROM categories')->fetchAll() as $c) {
        $catCache[mb_strtolower($c['name'])] = (int)$c['id'];
    }
    $palette = ['#10b981', '#3b82f6', '#f59e0b', '#06b6d4', '#8b5cf6', '#ec4899', '#64748b'];

    $created = 0;
    $updated = 0;
    $skipped = 0;

    $findSku = $pdo->prepare('SELECT id, is_active FROM products WHERE sku = ? LIMIT 1');
    $insCat = $pdo->prepare('INSERT INTO categories (name, color) VALUES (?, ?)');
    $insProd = $pdo->prepare(
        'INSERT INTO products
         (sku, name, category_id, measure_type, unit_label, allow_decimals, stock, min_stock,
          cost_price, sale_price_net, iva_rate, other_tax_rate, sale_price_final)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $updProd = $pdo->prepare(
        'UPDATE products
         SET name = ?, category_id = ?, measure_type = ?, unit_label = ?, allow_decimals = ?,
             min_stock = ?, cost_price = ?, sale_price_net = ?, iva_rate = ?, other_tax_rate = ?,
             sale_price_final = ?, is_active = 1
         WHERE id = ?'
    );
    $ssIns = $pdo->prepare('INSERT IGNORE INTO store_stock (store_id, product_id, stock, min_stock) VALUES (?, ?, ?, ?)');
    $movIns = $pdo->prepare(
        'INSERT INTO inventory_movements
         (store_id, user_id, product_id, movement_type, quantity, stock_before, stock_after,
          unit_cost, unit_price_final, money_movement, iva_amount, other_tax_amount,
          net_amount, profit_amount, counterparty, notes)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?)'
    );

    $pdo->beginTransaction();

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }

        $name = $getVal($row, ['Nombre', 'name', 'producto']);
        if ($name === '') {
            $skipped++;
            continue;
        }

        $sku = $getVal($row, ['SKU', 'sku', 'codigo']);
        $sku = $sku === '' ? null : $sku;

        $catName = $getVal($row, ['Categoria', 'categoría', 'category']);
        $categoryId = null;
        if ($catName !== '') {
            $catKey = mb_strtolower($catName);
            if (isset($catCache[$catKey])) {
                $categoryId = $catCache[$catKey];
            } else {
                $color = $palette[count($catCache) % count($palette)];
                $insCat->execute([$catName, $color]);
                $categoryId = (int)$pdo->lastInsertId();
                $catCache[$catKey] = $categoryId;
            }
        }

        $rawType = strtolower($getVal($row, ['Tipo_Medida', 'measure_type', 'tipo'], 'unit'));
        $measureType = in_array($rawType, ['unit', 'weight', 'volume', 'length', 'pack'], true) ? $rawType : 'unit';
        $unitLabel = $getVal($row, ['Unidad', 'unit_label', 'unidad'], 'unid.');
        if ($unitLabel === '') {
            $unitLabel = 'unid.';
        }
        $allowDecimals = in_array($measureType, ['weight', 'volume', 'length'], true) ? 1 : 0;

        $stock = max(0.0, (float)str_replace(',', '.', $getVal($row, ['Stock_Inicial', 'stock'], '0')));
        $minStock = max(0.0, (float)str_replace(',', '.', $getVal($row, ['Stock_Minimo', 'min_stock'], '5')));
        $cost = max(0.0, (float)str_replace(',', '.', $getVal($row, ['Costo_Compra_Unit', 'cost_price', 'costo'], '0')));
        $margin = (float)str_replace(',', '.', $getVal($row, ['Margen_Ganancia_Pct', 'margin_pct', 'margen'], '0'));
        $iva = max(0.0, min(100.0, (float)str_replace(',', '.', $getVal($row, ['IVA_Pct', 'iva_rate', 'iva'], '19'))));
        $otherTax = max(0.0, min(100.0, (float)str_replace(',', '.', $getVal($row, ['Otro_Impuesto_Pct', 'other_tax_rate'], '0'))));

        $rawNet = $getVal($row, ['Precio_Venta_Neto', 'sale_price_net', 'precio_neto'], '');
        $rawFinal = $getVal($row, ['Precio_Final_Cliente', 'sale_price_final', 'precio_final'], '');

        $saleNet = $rawNet !== '' ? (float)str_replace(',', '.', $rawNet) : null;
        $saleFinal = $rawFinal !== '' ? (float)str_replace(',', '.', $rawFinal) : null;
        if ($saleNet === null && $saleFinal === null) {
            $saleNet = round($cost * (1 + $margin / 100), 2);
        }

        $pricing = calculatePricing($cost, $iva, $otherTax, $saleNet, $saleFinal);

        $existingId = null;
        if ($sku !== null) {
            $findSku->execute([$sku]);
            $found = $findSku->fetch();
            if ($found) {
                $existingId = (int)$found['id'];
            }
        }

        if ($existingId !== null) {
            $updProd->execute([
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
                $existingId,
            ]);
            foreach ($activeStores as $sid) {
                $ssIns->execute([(int)$sid, $existingId, 0.0, $minStock]);
            }
            $updated++;
        } else {
            $insProd->execute([
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

            foreach ($activeStores as $sid) {
                $qty = ((int)$sid === $storeId) ? $stock : 0.0;
                $ssIns->execute([(int)$sid, $productId, $qty, $minStock]);
            }

            if ($stock > 0) {
                $moneyMovement = round($stock * $pricing['cost_price'], 2);
                $movIns->execute([
                    $storeId,
                    (int)$user['id'],
                    $productId,
                    'purchase',
                    $stock,
                    $stock,
                    $pricing['cost_price'],
                    $pricing['sale_price_final'],
                    $moneyMovement,
                    $moneyMovement,
                    'Importación CSV',
                    'Carga masiva desde CSV',
                ]);
            }
            $created++;
        }
    }

    fclose($handle);
    $pdo->commit();

    jsonResponse([
        'ok' => true,
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'store_id' => $storeId,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('Error al importar CSV: ' . $e->getMessage(), 500);
}
