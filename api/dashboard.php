<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = getPDO();

    $salesTotals = $pdo->query(
        'SELECT
           COALESCE(SUM(total_gross), 0) AS sales_gross,
           COALESCE(SUM(total_iva), 0) AS total_iva,
           COALESCE(SUM(total_other_tax), 0) AS total_other_tax,
           COALESCE(SUM(total_net), 0) AS sales_net,
           COALESCE(SUM(total_cost), 0) AS sales_cost,
           COALESCE(SUM(total_profit), 0) AS total_profit,
           COUNT(*) AS sales_count
         FROM sales'
    )->fetch();

    $todaySales = $pdo->query(
        'SELECT
           COALESCE(SUM(total_gross), 0) AS today_sales_gross,
           COALESCE(SUM(total_profit), 0) AS today_profit
         FROM sales
         WHERE DATE(created_at) = CURDATE()'
    )->fetch();

    $purchasesTotals = $pdo->query(
        "SELECT
           COALESCE(SUM(money_movement), 0) AS purchases_total,
           COUNT(*) AS purchases_count
         FROM inventory_movements
         WHERE movement_type = 'purchase'"
    )->fetch();

    $todayPurchases = $pdo->query(
        "SELECT COALESCE(SUM(money_movement), 0) AS today_purchases
         FROM inventory_movements
         WHERE movement_type = 'purchase' AND DATE(created_at) = CURDATE()"
    )->fetch();

    $inventoryTotals = $pdo->query(
        'SELECT
           COUNT(*) AS total_products,
           COALESCE(SUM(stock * cost_price), 0) AS inventory_cost_value,
           COALESCE(SUM(stock * sale_price_final), 0) AS inventory_sale_value,
           COALESCE(SUM(stock * (sale_price_net - cost_price)), 0) AS inventory_potential_profit,
           SUM(CASE WHEN stock <= min_stock THEN 1 ELSE 0 END) AS low_stock_count
         FROM products
         WHERE is_active = 1'
    )->fetch();

    $lowStock = $pdo->query(
        'SELECT id, sku, name, stock, min_stock, unit_label, cost_price, sale_price_final
         FROM products
         WHERE is_active = 1 AND stock <= min_stock
         ORDER BY (stock - min_stock) ASC, name ASC
         LIMIT 10'
    )->fetchAll();

    $recentMovements = $pdo->query(
        'SELECT m.*, p.name AS product_name, p.unit_label
         FROM inventory_movements m
         JOIN products p ON p.id = m.product_id
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 12'
    )->fetchAll();

    $topProducts = $pdo->query(
        'SELECT
           p.id, p.name, p.unit_label,
           COALESCE(SUM(si.quantity), 0) AS qty_sold,
           COALESCE(SUM(si.line_gross), 0) AS gross_sold,
           COALESCE(SUM(si.line_profit), 0) AS profit_earned
         FROM products p
         LEFT JOIN sale_items si ON si.product_id = p.id
         WHERE p.is_active = 1
         GROUP BY p.id, p.name, p.unit_label
         HAVING qty_sold > 0
         ORDER BY profit_earned DESC
         LIMIT 6'
    )->fetchAll();

    $salesGross = (float)$salesTotals['sales_gross'];
    $purchasesTotal = (float)$purchasesTotals['purchases_total'];
    $totalMoneyMovement = round($salesGross + $purchasesTotal, 2);
    $todayMoneyMovement = round((float)$todaySales['today_sales_gross'] + (float)$todayPurchases['today_purchases'], 2);

    jsonResponse([
        'ok' => true,
        'kpis' => [
            'total_profit' => round((float)$salesTotals['total_profit'], 2),
            'today_profit' => round((float)$todaySales['today_profit'], 2),
            'total_money_movement' => $totalMoneyMovement,
            'today_money_movement' => $todayMoneyMovement,
            'sales_gross' => round($salesGross, 2),
            'sales_net' => round((float)$salesTotals['sales_net'], 2),
            'sales_cost' => round((float)$salesTotals['sales_cost'], 2),
            'total_iva' => round((float)$salesTotals['total_iva'], 2),
            'total_other_tax' => round((float)$salesTotals['total_other_tax'], 2),
            'sales_count' => (int)$salesTotals['sales_count'],
            'purchases_total' => round($purchasesTotal, 2),
            'purchases_count' => (int)$purchasesTotals['purchases_count'],
            'total_products' => (int)$inventoryTotals['total_products'],
            'inventory_cost_value' => round((float)$inventoryTotals['inventory_cost_value'], 2),
            'inventory_sale_value' => round((float)$inventoryTotals['inventory_sale_value'], 2),
            'inventory_potential_profit' => round((float)$inventoryTotals['inventory_potential_profit'], 2),
            'low_stock_count' => (int)$inventoryTotals['low_stock_count'],
        ],
        'low_stock' => $lowStock,
        'recent_movements' => $recentMovements,
        'top_products' => $topProducts,
    ]);
} catch (Throwable $e) {
    jsonError('Error en dashboard: ' . $e->getMessage(), 500);
}
