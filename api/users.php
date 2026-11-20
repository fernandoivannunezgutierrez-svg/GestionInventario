<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $admin = requireAuth('admin');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $storeId = isset($_GET['store_id']) && $_GET['store_id'] !== '' && $_GET['store_id'] !== 'all'
            ? (int)$_GET['store_id']
            : null;

        $sql = 'SELECT u.id, u.store_id, u.username, u.full_name, u.card_code, u.role, u.is_active, u.created_at,
                       s.name AS store_name,
                       (SELECT COUNT(*) FROM sales sa WHERE sa.user_id = u.id AND DATE(sa.created_at) = CURDATE()) AS today_sales_count,
                       (SELECT COALESCE(SUM(sa.total_gross), 0) FROM sales sa WHERE sa.user_id = u.id AND DATE(sa.created_at) = CURDATE()) AS today_sales_gross,
                       (SELECT COALESCE(SUM(sa.total_profit), 0) FROM sales sa WHERE sa.user_id = u.id AND DATE(sa.created_at) = CURDATE()) AS today_sales_profit,
                       (SELECT COALESCE(SUM(sa.total_gross), 0) FROM sales sa WHERE sa.user_id = u.id) AS total_sales_gross
                FROM users u
                LEFT JOIN stores s ON s.id = u.store_id
                WHERE 1=1';
        $params = [];
        if ($storeId !== null) {
            $sql .= ' AND (u.store_id = ? OR u.role = "admin")';
            $params[] = $storeId;
        }
        $sql .= ' ORDER BY u.role ASC, u.full_name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['ok' => true, 'users' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $username = trim((string)($body['username'] ?? ''));
        $fullName = trim((string)($body['full_name'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $role = ($body['role'] ?? 'seller') === 'admin' ? 'admin' : 'seller';
        $storeId = $role === 'seller' ? (int)($body['store_id'] ?? 1) : (isset($body['store_id']) && $body['store_id'] !== '' ? (int)$body['store_id'] : null);
        $cardCode = trim((string)($body['card_code'] ?? ''));
        $cardCode = $cardCode !== '' ? $cardCode : null;

        if ($username === '' || $fullName === '' || $password === '') {
            jsonError('Usuario, nombre completo y contraseña son obligatorios.', 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (store_id, username, full_name, password_hash, card_code, role)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $storeId,
            $username,
            $fullName,
            password_hash($password, PASSWORD_DEFAULT),
            $cardCode,
            $role,
        ]);

        jsonResponse(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT') {
        $body = getJsonBody();
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID de usuario inválido.', 422);
        }

        $existingStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            jsonError('Usuario no encontrado.', 404);
        }

        $fullName = trim((string)($body['full_name'] ?? $existing['full_name']));
        $role = ($body['role'] ?? $existing['role']) === 'admin' ? 'admin' : 'seller';
        $storeId = $role === 'seller'
            ? (int)($body['store_id'] ?? ($existing['store_id'] ?? 1))
            : (isset($body['store_id']) && $body['store_id'] !== '' ? (int)$body['store_id'] : null);
        $cardCode = array_key_exists('card_code', $body)
            ? (trim((string)$body['card_code']) !== '' ? trim((string)$body['card_code']) : null)
            : $existing['card_code'];
        $isActive = array_key_exists('is_active', $body)
            ? (!empty($body['is_active']) ? 1 : 0)
            : (int)$existing['is_active'];

        if ($id === (int)$admin['id'] && $isActive === 0) {
            jsonError('No puedes desactivar tu propio usuario administrador.', 422);
        }

        $password = (string)($body['password'] ?? '');
        if ($password !== '') {
            $stmt = $pdo->prepare(
                'UPDATE users SET store_id = ?, full_name = ?, password_hash = ?, card_code = ?, role = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$storeId, $fullName, password_hash($password, PASSWORD_DEFAULT), $cardCode, $role, $isActive, $id]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users SET store_id = ?, full_name = ?, card_code = ?, role = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$storeId, $fullName, $cardCode, $role, $isActive, $id]);
        }

        jsonResponse(['ok' => true]);
    }

    jsonError('Método no permitido.', 405);
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        jsonError('El nombre de usuario o código de tarjeta ya está en uso.', 409);
    }
    jsonError('Error en usuarios: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    jsonError('Error en usuarios: ' . $e->getMessage(), 500);
}
