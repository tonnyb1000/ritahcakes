CREATE DATABASE IF NOT EXISTS `ritahcakes`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ritahcakes`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'receptionist') NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cake_price_tier` VARCHAR(120) NOT NULL,
  `custom_price_description` TEXT DEFAULT '',
  `shape_details` TEXT DEFAULT '',
  `amount_to_pay` DECIMAL(12,2) NOT NULL,
  `amount_paid` DECIMAL(12,2) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL,
  `pickup_date` DATE NOT NULL,
  `owner_name` VARCHAR(150) NOT NULL,
  `cake_text` TEXT DEFAULT '',
  `design_color` TEXT DEFAULT '',
  `other_details` TEXT DEFAULT '',
  `status` ENUM('pending', 'received') NOT NULL DEFAULT 'pending',
  `created_by_user_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_at` TIMESTAMP NULL DEFAULT NULL,
  INDEX `idx_orders_status_pickup` (`status`, `pickup_date`)
);

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `expense_date` DATE NOT NULL,
  `category` VARCHAR(120) NOT NULL,
  `description` TEXT DEFAULT '',
  `amount` DECIMAL(12,2) NOT NULL,
  `created_by_user_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_expenses_date` (`expense_date`)
);
