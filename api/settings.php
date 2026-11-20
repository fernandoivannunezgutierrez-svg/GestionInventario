<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();
    $user = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $settings = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
        $categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
        jsonResponse([
            'ok' => true,
            'settings' => $settings,
            'categories' => $categories,
        ]);
    }

    if ($user['role'] !== 'admin') {
        jsonError('Solo el administrador puede modificar la configuración.', 403);
    }

    if ($method === 'PUT') {
        $body = getJsonBody();
        $businessName = trim((string)($body['business_name'] ?? 'Mi Negocio Pyme'));
        if ($businessName === '') {
            $businessName = 'Mi Negocio Pyme';
        }
        $currencySymbol = trim((string)($body['currency_symbol'] ?? '$'));
        if ($currencySymbol === '') {
            $currencySymbol = '$';
        }
        $defaultIva = max(0, min(100, (float)($body['default_iva_rate'] ?? 19)));
        $defaultOtherTax = max(0, min(100, (float)($body['default_other_tax_rate'] ?? 0)));
        $decimalPlaces = in_array((int)($body['decimal_places'] ?? 0), [0, 2], true)
            ? (int)$body['decimal_places']
            : 0;
        $costMethod = ($body['cost_method'] ?? 'weighted_avg') === 'last_cost'
            ? 'last_cost'
            : 'weighted_avg';

        $stmt = $pdo->prepare(
            'UPDATE settings
             SET business_name = ?, currency_symbol = ?, default_iva_rate = ?,
                 default_other_tax_rate = ?, decimal_places = ?, cost_method = ?
             WHERE id = 1'
        );
        $stmt->execute([
            $businessName,
            $currencySymbol,
            $defaultIva,
            $defaultOtherTax,
            $decimalPlaces,
            $costMethod,
        ]);

        $updated = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
        jsonResponse(['ok' => true, 'settings' => $updated]);
    }

    if ($method === 'POST') {
        $body = getJsonBody();
        $name = trim((string)($body['name'] ?? ''));
        $color = trim((string)($body['color'] ?? '#3b82f6'));
        if ($name === '') {
            jsonError('El nombre de la categoría es obligatorio.', 422);
        }
        $stmt = $pdo->prepare('INSERT INTO categories (name, color) VALUES (?, ?)');
        $stmt->execute([$name, $color]);
        $categories = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
        jsonResponse(['ok' => true, 'categories' => $categories], 201);
    }

    jsonError('Método no permitido.', 405);
} catch (Throwable $e) {
    jsonError('Error en configuración: ' . $e->getMessage(), 500);
}
