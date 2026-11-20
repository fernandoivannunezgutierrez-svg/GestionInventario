CREATE DATABASE IF NOT EXISTS `gestion_inventario`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `gestion_inventario`;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  `business_name` VARCHAR(120) NOT NULL DEFAULT 'Mi Negocio',
  `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '$',
  `default_iva_rate` DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  `default_other_tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `decimal_places` TINYINT NOT NULL DEFAULT 0,
  `cost_method` ENUM('weighted_avg', 'last_cost') NOT NULL DEFAULT 'weighted_avg',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`id`, `business_name`, `currency_symbol`, `default_iva_rate`, `default_other_tax_rate`, `decimal_places`, `cost_method`)
VALUES (1, 'Mi Negocio Pyme', '$', 19.00, 0.00, 0, 'weighted_avg');

CREATE TABLE IF NOT EXISTS `stores` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `address` VARCHAR(200) NULL,
  `phone` VARCHAR(40) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `stores` (`id`, `name`, `address`)
VALUES (1, 'Tienda 1 (Principal)', 'Casa Matriz');

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `store_id` INT NULL,
  `username` VARCHAR(60) NOT NULL UNIQUE,
  `full_name` VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `card_code` VARCHAR(64) NULL UNIQUE,
  `role` ENUM('admin', 'seller') NOT NULL DEFAULT 'seller',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_users_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(80) NOT NULL UNIQUE,
  `color` VARCHAR(20) NOT NULL DEFAULT '#3b82f6'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `categories` (`name`, `color`) VALUES
  ('Papelería y Oficina', '#3b82f6'),
  ('Abarrotes y Alimentos', '#10b981'),
  ('Bebidas y Líquidos', '#06b6d4'),
  ('Ferretería y Materiales', '#f59e0b'),
  ('General', '#64748b');

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sku` VARCHAR(50) NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `category_id` INT NULL,
  `measure_type` ENUM('unit', 'weight', 'volume', 'length', 'pack') NOT NULL DEFAULT 'unit',
  `unit_label` VARCHAR(20) NOT NULL DEFAULT 'unid.',
  `allow_decimals` TINYINT(1) NOT NULL DEFAULT 0,
  `stock` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `min_stock` DECIMAL(12,3) NOT NULL DEFAULT 5.000,
  `cost_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `sale_price_net` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `iva_rate` DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  `other_tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `sale_price_final` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_stock` (
  `store_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `stock` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `min_stock` DECIMAL(12,3) NOT NULL DEFAULT 5.000,
  PRIMARY KEY (`store_id`, `product_id`),
  CONSTRAINT `fk_store_stock_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_store_stock_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `store_id` INT NOT NULL DEFAULT 1,
  `user_id` INT NULL,
  `reference` VARCHAR(60) NULL,
  `customer_name` VARCHAR(120) NULL,
  `payment_method` ENUM('cash', 'card', 'transfer', 'mixed') NOT NULL DEFAULT 'cash',
  `cash_received` DECIMAL(14,2) NULL,
  `cash_change` DECIMAL(14,2) NULL,
  `total_gross` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_iva` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_other_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_net` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sales_store` (`store_id`),
  INDEX `idx_sales_user` (`user_id`),
  CONSTRAINT `fk_sales_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `product_name` VARCHAR(150) NOT NULL,
  `unit_label` VARCHAR(20) NOT NULL,
  `quantity` DECIMAL(12,3) NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL,
  `unit_price_net` DECIMAL(14,2) NOT NULL,
  `iva_rate` DECIMAL(5,2) NOT NULL,
  `other_tax_rate` DECIMAL(5,2) NOT NULL,
  `unit_price_final` DECIMAL(14,2) NOT NULL,
  `line_gross` DECIMAL(14,2) NOT NULL,
  `line_iva` DECIMAL(14,2) NOT NULL,
  `line_other_tax` DECIMAL(14,2) NOT NULL,
  `line_net` DECIMAL(14,2) NOT NULL,
  `line_cost` DECIMAL(14,2) NOT NULL,
  `line_profit` DECIMAL(14,2) NOT NULL,
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sale_items_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `store_id` INT NOT NULL DEFAULT 1,
  `user_id` INT NULL,
  `product_id` INT NOT NULL,
  `movement_type` ENUM('purchase', 'sale', 'adjustment_in', 'adjustment_out') NOT NULL,
  `quantity` DECIMAL(12,3) NOT NULL,
  `stock_before` DECIMAL(12,3) NOT NULL,
  `stock_after` DECIMAL(12,3) NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_price_final` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `money_movement` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `iva_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `other_tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `profit_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `counterparty` VARCHAR(120) NULL,
  `reference_id` INT NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_movements_created` (`created_at`),
  INDEX `idx_movements_product` (`product_id`),
  INDEX `idx_movements_type` (`movement_type`),
  INDEX `idx_movements_store` (`store_id`),
  CONSTRAINT `fk_movements_store` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_movements_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
