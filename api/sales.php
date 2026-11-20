<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $stmt = $pdo->prepare(
            'SELECT * FROM sales ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $sales = $stmt->fetchAll();

        if ($sales) {
            $saleIds = array_column($sales, 'id');
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $itemStmt = $pdo->prepare(
                "SELECT * FROM sale_items WHERE sale_id IN ($placeholders) ORDER BY id ASC"
            );
            $itemStmt->execute($saleIds);
            $itemsBySale = [];
            foreach ($itemStmt->fetchAll() as $item) {
                $itemsBySale[(int)$item['sale_id']][] = $item;
            }
            foreach ($sales as &$sale) {
                $sale['items'] = $itemsBySale[(int)$sale['id']] ?? [];
            }
            unset($sale);
        }

        jsonResponse(['ok' => true, 'sales' => $sales]);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $items = $body['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            jsonError('Debe agregar al menos un producto a la venta.', 422);
        }

        $customerName = trim((string)($body['customer_name'] ?? ''));
        $reference = trim((string)($body['reference'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));

        $pdo->beginTransaction();

        $totalGross = 0.0;
        $totalIva = 0.0;
        $totalOtherTax = 0.0;
        $totalNet = 0.0;
        $totalCost = 0.0;
        $totalProfit = 0.0;

        $preparedLines = [];
        $prodSelect = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');

        foreach ($items as $rawItem) {
            $productId = (int)($rawItem['product_id'] ?? 0);
            $quantity = (float)($rawItem['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                $pdo->rollBack();
                jsonError('Cada producto de la venta debe tener una cantidad mayor a 0.', 422);
            }

            $prodSelect->execute([$productId]);
            $product = $prodSelect->fetch();
            if (!$product) {
                $pdo->rollBack();
                jsonError("Producto ID $productId no encontrado.", 404);
            }

            $stockBefore = (float)$product['stock'];
            if ($stockBefore < $quantity) {
                $pdo->rollBack();
                jsonError(
                    sprintf(
                        'Stock insuficiente para "%s". Disponible: %s %s, solicitado: %s %s.',
                        $product['name'],
                        rtrim(rtrim(number_format($stockBefore, 3, '.', ''), '0'), '.'),
                        $product['unit_label'],
                        rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'),
                        $product['unit_label']
                    ),
                    422
                );
            }

            $unitCost = (float)$product['cost_price'];
            $ivaRate = (float)$product['iva_rate'];
            $otherTaxRate = (float)$product['other_tax_rate'];
            $taxMultiplier = 1.0 + (($ivaRate + $otherTaxRate) / 100.0);

            $unitPriceFinal = isset($rawItem['unit_price_final']) && $rawItem['unit_price_final'] !== ''
                ? max(0.0, (float)$rawItem['unit_price_final'])
                : (float)$product['sale_price_final'];

            $unitPriceNet = $taxMultiplier > 0 ? round($unitPriceFinal / $taxMultiplier, 2) : $unitPriceFinal;

            $lineGross = round($quantity * $unitPriceFinal, 2);
            $lineNet = $taxMultiplier > 0 ? round($lineGross / $taxMultiplier, 2) : $lineGross;

            if ($otherTaxRate <= 0.0) {
                $lineIva = $ivaRate > 0.0 ? round($lineGross - $lineNet, 2) : 0.0;
                $lineOtherTax = 0.0;
            } else {
                $lineIva = round($lineNet * ($ivaRate / 100.0), 2);
                $lineOtherTax = round($lineGross - $lineNet - $lineIva, 2);
            }

            $lineCost = round($quantity * $unitCost, 2);
            $lineProfit = round($lineNet - $lineCost, 2);
            $stockAfter = round($stockBefore - $quantity, 3);

            $totalGross = round($totalGross + $lineGross, 2);
            $totalIva = round($totalIva + $lineIva, 2);
            $totalOtherTax = round($totalOtherTax + $lineOtherTax, 2);
            $totalNet = round($totalNet + $lineNet, 2);
            $totalCost = round($totalCost + $lineCost, 2);
            $totalProfit = round($totalProfit + $lineProfit, 2);

            $preparedLines[] = [
                'product' => $product,
                'quantity' => $quantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'unit_cost' => $unitCost,
                'unit_price_net' => $unitPriceNet,
                'iva_rate' => $ivaRate,
                'other_tax_rate' => $otherTaxRate,
                'unit_price_final' => $unitPriceFinal,
                'line_gross' => $lineGross,
                'line_iva' => $lineIva,
                'line_other_tax' => $lineOtherTax,
                'line_net' => $lineNet,
                'line_cost' => $lineCost,
                'line_profit' => $lineProfit,
            ];
        }

        $saleStmt = $pdo->prepare(
            'INSERT INTO sales
             (reference, customer_name, total_gross, total_iva, total_other_tax, total_net, total_cost, total_profit, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $saleStmt->execute([
            $reference !== '' ? $reference : null,
            $customerName !== '' ? $customerName : null,
            $totalGross,
            $totalIva,
            $totalOtherTax,
            $totalNet,
            $totalCost,
            $totalProfit,
            $notes !== '' ? $notes : null,
        ]);
        $saleId = (int)$pdo->lastInsertId();

        $itemInsert = $pdo->prepare(
            'INSERT INTO sale_items
             (sale_id, product_id, product_name, unit_label, quantity, unit_cost, unit_price_net,
              iva_rate, other_tax_rate, unit_price_final, line_gross, line_iva, line_other_tax,
              line_net, line_cost, line_profit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stockUpdate = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ?');
        $movInsert = $pdo->prepare(
            'INSERT INTO inventory_movements
             (product_id, movement_type, quantity, stock_before, stock_after,
              unit_cost, unit_price_final, money_movement, iva_amount, other_tax_amount,
              net_amount, profit_amount, counterparty, reference_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($preparedLines as $line) {
            $p = $line['product'];
            $itemInsert->execute([
                $saleId,
                $p['id'],
                $p['name'],
                $p['unit_label'],
                $line['quantity'],
                $line['unit_cost'],
                $line['unit_price_net'],
                $line['iva_rate'],
                $line['other_tax_rate'],
                $line['unit_price_final'],
                $line['line_gross'],
                $line['line_iva'],
                $line['line_other_tax'],
                $line['line_net'],
                $line['line_cost'],
                $line['line_profit'],
            ]);

            $stockUpdate->execute([$line['stock_after'], $p['id']]);

            $movInsert->execute([
                $p['id'],
                'sale',
                $line['quantity'],
                $line['stock_before'],
                $line['stock_after'],
                $line['unit_cost'],
                $line['unit_price_final'],
                $line['line_gross'],
                $line['line_iva'],
                $line['line_other_tax'],
                $line['line_net'],
                $line['line_profit'],
                $customerName !== '' ? $customerName : null,
                $saleId,
                $notes !== '' ? $notes : "Venta #$saleId",
            ]);
        }

        $pdo->commit();

        jsonResponse([
            'ok' => true,
            'sale' => [
                'id' => $saleId,
                'total_gross' => $totalGross,
                'total_iva' => $totalIva,
                'total_other_tax' => $totalOtherTax,
                'total_net' => $totalNet,
                'total_cost' => $totalCost,
                'total_profit' => $totalProfit,
            ],
        ], 201);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('Error en ventas: ' . $e->getMessage(), 500);
}
