<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));
        $stmt = $pdo->prepare(
            "SELECT m.*, p.name AS product_name, p.sku, p.unit_label
             FROM inventory_movements m
             JOIN products p ON p.id = m.product_id
             WHERE m.movement_type = 'purchase'
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(['ok' => true, 'purchases' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $productId = (int)($body['product_id'] ?? 0);
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

        $settings = $pdo->query('SELECT cost_method FROM settings WHERE id = 1')->fetch();
        $costMethod = $settings['cost_method'] ?? 'weighted_avg';

        $stockBefore = (float)$product['stock'];
        $oldCost = (float)$product['cost_price'];
        $stockAfter = round($stockBefore + $quantity, 3);

        if ($costMethod === 'weighted_avg' && $stockAfter > 0) {
            $newCost = round((($stockBefore * $oldCost) + ($quantity * $unitCost)) / $stockAfter, 2);
        } else {
            $newCost = round($unitCost, 2);
        }

        $moneyMovement = round($quantity * $unitCost, 2);

        $updStmt = $pdo->prepare('UPDATE products SET stock = ?, cost_price = ? WHERE id = ?');
        $updStmt->execute([$stockAfter, $newCost, $productId]);

        $movStmt = $pdo->prepare(
            'INSERT INTO inventory_movements
             (product_id, movement_type, quantity, stock_before, stock_after,
              unit_cost, unit_price_final, money_movement, iva_amount, other_tax_amount,
              net_amount, profit_amount, counterparty, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?)'
        );
        $movStmt->execute([
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
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'new_cost_price' => $newCost,
            'money_movement' => $moneyMovement,
        ], 201);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('Error en compras: ' . $e->getMessage(), 500);
}
