<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $user = requireAuth('admin');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $storeId = resolveStoreId($_GET['store_id'] ?? null, $user, true);
        $productId = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : null;
        $type = trim((string)($_GET['type'] ?? ''));
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));
        $export = ($_GET['export'] ?? '') === 'csv';
        $limit = $export ? 5000 : min(300, max(10, (int)($_GET['limit'] ?? 100)));

        $sql = 'SELECT m.*, p.name AS product_name, p.sku, p.unit_label,
                       st.name AS store_name, u.full_name AS user_name
                FROM inventory_movements m
                JOIN products p ON p.id = m.product_id
                LEFT JOIN stores st ON st.id = m.store_id
                LEFT JOIN users u ON u.id = m.user_id
                WHERE 1=1';
        $params = [];

        if ($storeId !== null) {
            $sql .= ' AND m.store_id = ?';
            $params[] = $storeId;
        }
        if ($productId !== null) {
            $sql .= ' AND m.product_id = ?';
            $params[] = $productId;
        }
        if ($type !== '' && in_array($type, ['purchase', 'sale', 'adjustment_in', 'adjustment_out'], true)) {
            $sql .= ' AND m.movement_type = ?';
            $params[] = $type;
        }
        if ($from !== '') {
            $sql .= ' AND DATE(m.created_at) >= ?';
            $params[] = $from;
        }
        if ($to !== '') {
            $sql .= ' AND DATE(m.created_at) <= ?';
            $params[] = $to;
        }

        $sql .= ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($export) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="kardex_movimientos.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID', 'Fecha', 'Tienda', 'Usuario', 'Tipo', 'Producto', 'SKU', 'Unidad', 'Cantidad',
                'Stock Anterior', 'Stock Nuevo', 'Costo Unit.', 'Precio Final Unit.',
                'Movimiento Dinero', 'IVA', 'Otro Impuesto', 'Neto', 'Ganancia',
                'Contraparte', 'Notas'
            ], ';');
            $typeLabels = [
                'purchase' => 'Compra (Entrada)',
                'sale' => 'Venta (Salida)',
                'adjustment_in' => 'Ajuste Entrada',
                'adjustment_out' => 'Ajuste Salida / Merma',
            ];
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['created_at'],
                    $r['store_name'] ?? 'Tienda 1',
                    $r['user_name'] ?? '',
                    $typeLabels[$r['movement_type']] ?? $r['movement_type'],
                    $r['product_name'],
                    $r['sku'] ?? '',
                    $r['unit_label'],
                    $r['quantity'],
                    $r['stock_before'],
                    $r['stock_after'],
                    $r['unit_cost'],
                    $r['unit_price_final'],
                    $r['money_movement'],
                    $r['iva_amount'],
                    $r['other_tax_amount'],
                    $r['net_amount'],
                    $r['profit_amount'],
                    $r['counterparty'] ?? '',
                    $r['notes'] ?? '',
                ], ';');
            }
            fclose($out);
            exit;
        }

        jsonResponse(['ok' => true, 'movements' => $rows]);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $storeId = resolveStoreId(isset($body['store_id']) ? (string)$body['store_id'] : null, $user, false) ?? 1;
        $productId = (int)($body['product_id'] ?? 0);
        $type = (string)($body['movement_type'] ?? '');
        $quantity = (float)($body['quantity'] ?? 0);
        $notes = trim((string)($body['notes'] ?? ''));

        if ($productId <= 0) {
            jsonError('Debe seleccionar un producto.', 422);
        }
        if (!in_array($type, ['adjustment_in', 'adjustment_out'], true)) {
            jsonError('Tipo de ajuste inválido.', 422);
        }
        if ($quantity <= 0) {
            jsonError('La cantidad debe ser mayor a 0.', 422);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            $pdo->rollBack();
            jsonError('Producto no encontrado.', 404);
        }

        $pdo->prepare('INSERT IGNORE INTO store_stock (store_id, product_id, stock, min_stock) VALUES (?, ?, 0, ?)')
            ->execute([$storeId, $productId, (float)$product['min_stock']]);
        $ssLock = $pdo->prepare('SELECT stock FROM store_stock WHERE store_id = ? AND product_id = ? FOR UPDATE');
        $ssLock->execute([$storeId, $productId]);
        $stockBefore = (float)$ssLock->fetchColumn();

        if ($type === 'adjustment_out' && $stockBefore < $quantity) {
            $pdo->rollBack();
            jsonError('No hay suficiente stock en esta tienda para descontar esa cantidad.', 422);
        }

        $stockAfter = $type === 'adjustment_in'
            ? round($stockBefore + $quantity, 3)
            : round($stockBefore - $quantity, 3);

        $pdo->prepare('UPDATE store_stock SET stock = ? WHERE store_id = ? AND product_id = ?')
            ->execute([$stockAfter, $storeId, $productId]);
        syncProductTotalStock($pdo, $productId);

        $unitCost = (float)$product['cost_price'];
        $costImpact = round($quantity * $unitCost, 2);

        $ins = $pdo->prepare(
            'INSERT INTO inventory_movements
             (store_id, user_id, product_id, movement_type, quantity, stock_before, stock_after,
              unit_cost, unit_price_final, money_movement, iva_amount, other_tax_amount,
              net_amount, profit_amount, counterparty, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?, ?)'
        );
        $ins->execute([
            $storeId,
            (int)$user['id'],
            $productId,
            $type,
            $quantity,
            $stockBefore,
            $stockAfter,
            $unitCost,
            (float)$product['sale_price_final'],
            $type === 'adjustment_out' ? -$costImpact : 0.0,
            'Ajuste interno',
            $notes !== '' ? $notes : ($type === 'adjustment_in' ? 'Ajuste de entrada' : 'Merma / Ajuste de salida'),
        ]);

        $pdo->commit();
        jsonResponse([
            'ok' => true,
            'store_id' => $storeId,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
        ], 201);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('Error en movimientos: ' . $e->getMessage(), 500);
}
