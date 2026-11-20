<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $user = currentUser();
        if (!$user) {
            jsonResponse(['ok' => true, 'user' => null]);
        }
        $stores = $pdo->query('SELECT * FROM stores WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        jsonResponse(['ok' => true, 'user' => $user, 'stores' => $stores]);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $cardCode = trim((string)($body['card_code'] ?? ''));
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($cardCode !== '') {
            $stmt = $pdo->prepare(
                'SELECT u.*, s.name AS store_name
                 FROM users u
                 LEFT JOIN stores s ON s.id = u.store_id
                 WHERE u.card_code = ? AND u.is_active = 1'
            );
            $stmt->execute([$cardCode]);
            $user = $stmt->fetch();
            if (!$user) {
                jsonError('Tarjeta / código no reconocido o usuario inactivo.', 401);
            }
        } else {
            if ($username === '' || $password === '') {
                jsonError('Ingresa usuario y contraseña.', 422);
            }
            $stmt = $pdo->prepare(
                'SELECT u.*, s.name AS store_name
                 FROM users u
                 LEFT JOIN stores s ON s.id = u.store_id
                 WHERE u.username = ? AND u.is_active = 1'
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                jsonError('Usuario o contraseña incorrectos.', 401);
            }
        }

        $sessionUser = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'store_id' => $user['store_id'] ? (int)$user['store_id'] : null,
            'store_name' => $user['store_name'] ?? null,
            'card_code' => $user['card_code'] ?? null,
        ];
        $_SESSION['user'] = $sessionUser;

        $stores = $pdo->query('SELECT * FROM stores WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
        jsonResponse(['ok' => true, 'user' => $sessionUser, 'stores' => $stores]);
    }

    if ($method === 'DELETE') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        jsonResponse(['ok' => true]);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    jsonError('Error de autenticación: ' . $e->getMessage(), 500);
}
