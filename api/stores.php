<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stores = $pdo->query(
            'SELECT s.*,
               (SELECT COUNT(*) FROM users u WHERE u.store_id = s.id AND u.is_active = 1) AS sellers_count,
               (SELECT COALESCE(SUM(ss.stock * p.cost_price), 0)
                FROM store_stock ss JOIN products p ON p.id = ss.product_id
                WHERE ss.store_id = s.id AND p.is_active = 1) AS stock_cost_value,
               (SELECT COALESCE(SUM(sa.total_gross), 0) FROM sales sa WHERE sa.store_id = s.id) AS total_sales_gross,
               (SELECT COALESCE(SUM(sa.total_profit), 0) FROM sales sa WHERE sa.store_id = s.id) AS total_profit
             FROM stores s
             WHERE s.is_active = 1
             ORDER BY s.id ASC'
        )->fetchAll();
        jsonResponse(['ok' => true, 'stores' => $stores]);
    }

    if ($user['role'] !== 'admin') {
        jsonError('Solo el administrador puede gestionar tiendas.', 403);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $name = trim((string)($body['name'] ?? ''));
        $address = trim((string)($body['address'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));

        if ($name === '') {
            jsonError('El nombre de la tienda es obligatorio.', 422);
        }

        $stmt = $pdo->prepare('INSERT INTO stores (name, address, phone) VALUES (?, ?, ?)');
        $stmt->execute([$name, $address !== '' ? $address : null, $phone !== '' ? $phone : null]);
        $storeId = (int)$pdo->lastInsertId();

        // Initialize store_stock rows at 0 for existing products
        $pdo->prepare(
            'INSERT IGNORE INTO store_stock (store_id, product_id, stock, min_stock)
             SELECT ?, id, 0, min_stock FROM products WHERE is_active = 1'
        )->execute([$storeId]);

        $stores = $pdo->query('SELECT * FROM stores WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        jsonResponse(['ok' => true, 'store_id' => $storeId, 'stores' => $stores], 201);
    }

    if ($method === 'PUT') {
        $body = getJsonBody();
        $id = (int)($body['id'] ?? 0);
        $name = trim((string)($body['name'] ?? ''));
        $address = trim((string)($body['address'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));

        if ($id <= 0 || $name === '') {
            jsonError('ID y nombre de tienda son obligatorios.', 422);
        }

        $stmt = $pdo->prepare('UPDATE stores SET name = ?, address = ?, phone = ? WHERE id = ?');
        $stmt->execute([$name, $address !== '' ? $address : null, $phone !== '' ? $phone : null, $id]);

        $stores = $pdo->query('SELECT * FROM stores WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        jsonResponse(['ok' => true, 'stores' => $stores]);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    jsonError('Error en tiendas: ' . $e->getMessage(), 500);
}
