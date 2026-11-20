<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isSeller = $user['role'] === 'seller';

    if ($method === 'GET') {
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $storeId = resolveStoreId($_GET['store_id'] ?? null, $user, true);

        $sql = 'SELECT sa.*, st.name AS store_name, u.full_name AS seller_name
                FROM sales sa
                LEFT JOIN stores st ON st.id = sa.store_id
                LEFT JOIN users u ON u.id = sa.user_id
                WHERE 1=1';
        $params = [];

        if ($storeId !== null) {
            $sql .= ' AND sa.store_id = ?';
            $params[] = $storeId;
        }
        if ($isSeller) {
            $sql .= ' AND sa.user_id = ?';
            $params[] = (int)$user['id'];
        }
        $sql .= ' ORDER BY sa.created_at DESC, sa.id DESC LIMIT ?';

        $stmt = $pdo->prepare($sql);
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p, PDO::PARAM_INT);
        }
        $stmt->bindValue($idx, $limit, PDO::PARAM_INT);
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
                if ($isSeller) {
                    unset($item['unit_cost'], $item['line_cost'], $item['line_profit']);
                }
                $itemsBySale[(int)$item['sale_id']][] = $item;
            }
            foreach ($sales as &$sale) {
                if ($isSeller) {
                    unset($sale['total_cost'], $sale['total_profit']);
                }
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

        $storeId = resolveStoreId(isset($body['store_id']) ? (string)$body['store_id'] : null, $user, false) ?? 1;
        $customerName = trim((string)($body['customer_name'] ?? ''));
        $reference = trim((string)($body['reference'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));

        $allowedPay = ['cash', 'card', 'transfer', 'mixed'];
        $paymentMethod = in_array($body['payment_method'] ?? 'cash', $allowedPay, true)
            ? (string)$body['payment_method']
            : 'cash';
        $cashReceived = isset($body['cash_received']) && $body['cash_received'] !== ''
            ? max(0.0, (float)$body['cash_received'])
            : null;

        $pdo->beginTransaction();

        $totalGross = 0.0;
        $totalIva = 0.0;
        $totalOtherTax = 0.0;
        $totalNet = 0.0;
        $totalCost = 0.0;
        $totalProfit = 0.0;

        $preparedLines = [];
        $prodSelect = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');
        $ssInit = $pdo->prepare('INSERT IGNORE INTO store_stock (store_id, product_id, stock, min_stock) VALUES (?, ?, 0, ?)');
        $ssLock = $pdo->prepare('SELECT stock FROM store_stock WHERE store_id = ? AND product_id = ? FOR UPDATE');

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

            $ssInit->execute([$storeId, $productId, (float)$product['min_stock']]);
            $ssLock->execute([$storeId, $productId]);
            $stockBefore = (float)$ssLock->fetchColumn();

            if ($stockBefore < $quantity) {
                $pdo->rollBack();
                jsonError(
                    sprintf(
                        'Stock insuficiente en esta tienda para "%s". Disponible: %s %s, solicitado: %s %s.',
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

            // Sellers cannot override unit price arbitrarily unless admin
            $unitPriceFinal = (!$isSeller && isset($rawItem['unit_price_final']) && $rawItem['unit_price_final'] !== '')
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

        $cashChange = null;
        if ($paymentMethod === 'cash' && $cashReceived !== null) {
            if ($cashReceived < $totalGross) {
                $pdo->rollBack();
                jsonError('El efectivo recibido es menor al total a pagar.', 422);
            }
            $cashChange = round($cashReceived - $totalGross, 2);
        }

        $saleStmt = $pdo->prepare(
            'INSERT INTO sales
             (store_id, user_id, reference, customer_name, payment_method, cash_received, cash_change,
              total_gross, total_iva, total_other_tax, total_net, total_cost, total_profit, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $saleStmt->execute([
            $storeId,
            (int)$user['id'],
            $reference !== '' ? $reference : null,
            $customerName !== '' ? $customerName : null,
            $paymentMethod,
            $cashReceived,
            $cashChange,
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
        $ssUpdate = $pdo->prepare('UPDATE store_stock SET stock = ? WHERE store_id = ? AND product_id = ?');
        $movInsert = $pdo->prepare(
            'INSERT INTO inventory_movements
             (store_id, user_id, product_id, movement_type, quantity, stock_before, stock_after,
              unit_cost, unit_price_final, money_movement, iva_amount, other_tax_amount,
              net_amount, profit_amount, counterparty, reference_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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

            $ssUpdate->execute([$line['stock_after'], $storeId, $p['id']]);
            syncProductTotalStock($pdo, (int)$p['id']);

            $movInsert->execute([
                $storeId,
                (int)$user['id'],
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

        $saleResp = [
            'id' => $saleId,
            'store_id' => $storeId,
            'payment_method' => $paymentMethod,
            'cash_received' => $cashReceived,
            'cash_change' => $cashChange,
            'total_gross' => $totalGross,
            'total_iva' => $totalIva,
            'total_other_tax' => $totalOtherTax,
            'total_net' => $totalNet,
        ];
        if (!$isSeller) {
            $saleResp['total_cost'] = $totalCost;
            $saleResp['total_profit'] = $totalProfit;
        }

        jsonResponse(['ok' => true, 'sale' => $saleResp], 201);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('Error en ventas: ' . $e->getMessage(), 500);
}
