<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $user = requireAuth('admin');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $storeId = resolveStoreId($_GET['store_id'] ?? null, $user, true);

        $sql = "SELECT m.*, p.name AS product_name, p.sku, p.unit_label, s.name AS store_name
                FROM inventory_movements m
                JOIN products p ON p.id = m.product_id
                LEFT JOIN stores s ON s.id = m.store_id
                WHERE m.movement_type = 'purchase'";
        $params = [];
        if ($storeId !== null) {
            $sql .= ' AND m.store_id = ?';
            $params[] = $storeId;
        }
        $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT ?';

        $stmt = $pdo->prepare($sql);
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p, PDO::PARAM_INT);
        }
        $stmt->bindValue($idx, $limit, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(['ok' => true, 'purchases' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $productId = (int)($body['product_id'] ?? 0);
        $storeId = resolveStoreId(isset($body['store_id']) ? (string)$body['store_id'] : null, $user, false) ?? 1;
        $quantity = (float)($body['quantity'] ?? 0);
        $unitCost = (float)($body['unit_cost'] ?? -1);
        $supplier = trim((string)($body['supplier'] ?? ''));
        $notes = trim((string)($body['notes'] ?? ''));

        if ($productId <= 0) {
            jsonError('Debe seleccionar un producto válido.', 422);
        }
        if ($quantity <= 0) {
            jsonError('La cantidad comprada debe ser mayor a 0.', 422);
        }
        if ($unitCost < 0) {
            jsonError('El costo unitario no puede ser negativo.', 422);
        }

        $pdo->beginTransaction();

        $prodStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');
        $prodStmt->execute([$productId]);
        $product = $prodStmt->fetch();
        if (!$product) {
            $pdo->rollBack();
            jsonError('Producto no encontrado.', 404);
        }

        // Ensure store_stock row exists and lock it
        $pdo->prepare('INSERT IGNORE INTO store_stock (store_id, product_id, stock, min_stock) VALUES (?, ?, 0, ?)')
            ->execute([$storeId, $productId, (float)$product['min_stock']]);
        $ssStmt = $pdo->prepare('SELECT stock FROM store_stock WHERE store_id = ? AND product_id = ? FOR UPDATE');
        $ssStmt->execute([$storeId, $productId]);
        $stockBefore = (float)$ssStmt->fetchColumn();

        $totalStockAllStores = (float)$product['stock'];
        $settings = $pdo->query('SELECT cost_method FROM settings WHERE id = 1')->fetch();
        $costMethod = $settings['cost_method'] ?? 'weighted_avg';

        $oldCost = (float)$product['cost_price'];
        $stockAfter = round($stockBefore + $quantity, 3);
        $newTotalStock = round($totalStockAllStores + $quantity, 3);

        if ($costMethod === 'weighted_avg' && $newTotalStock > 0) {
            $newCost = round((($totalStockAllStores * $oldCost) + ($quantity * $unitCost)) / $newTotalStock, 2);
        } else {
            $newCost = round($unitCost, 2);
        }

        $moneyMovement = round($quantity * $unitCost, 2);

        $pdo->prepare('UPDATE store_stock SET stock = ? WHERE store_id = ? AND product_id = ?')
            ->execute([$stockAfter, $storeId, $productId]);
        $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ?')
            ->execute([$newCost, $productId]);
        syncProductTotalStock($pdo, $productId);

        $movStmt = $pdo->prepare(
            'INSERT INTO inventory_movements
             (store_id, user_id, product_id, movement_type, quantity, stock_before, stock_after,
              unit_cost, unit_price_final, money_movement, iva_amount, other_tax_amount,
              net_amount, profit_amount, counterparty, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?)'
        );
        $movStmt->execute([
            $storeId,
            $user['id'],
            $productId,
            'purchase',
            $quantity,
            $stockBefore,
            $stockAfter,
            round($unitCost, 2),
            (float)$product['sale_price_final'],
            $moneyMovement,
            $moneyMovement,
            $supplier !== '' ? $supplier : null,
            $notes !== '' ? $notes : 'Compra de mercadería',
        ]);

        $movementId = (int)$pdo->lastInsertId();
        $pdo->commit();

        jsonResponse([
            'ok' => true,
            'movement_id' => $movementId,
            'store_id' => $storeId,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'new_cost_price' => $newCost,
            'money_movement' => $moneyMovement,
        ], 201);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('Error en compras: ' . $e->getMessage(), 500);
}
