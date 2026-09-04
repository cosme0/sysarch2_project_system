-- ==============================================================
-- AMDPMS - Automated Medication Dispensing & Pharmacy Mgmt System
-- Full database setup. Run this in phpMyAdmin or MySQL.
-- Database: db_users
-- ==============================================================

CREATE DATABASE IF NOT EXISTS db_users;
USE db_users;

-- --------------------------------------------------------------
-- users table (authentication + role)
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fname VARCHAR(50) NOT NULL,
    lname VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    `pass` VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    failed_attempts INT NOT NULL DEFAULT 0,
    last_failed DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------------
-- password reset tokens table
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(50) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email)
);

-- --------------------------------------------------------------
-- medications table (inventory; matches the 5 dispenser channels)
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `medications` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel INT NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    sku VARCHAR(20) NOT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock INT NOT NULL DEFAULT 0,
    category VARCHAR(50) NOT NULL DEFAULT 'Tablet',
    icon VARCHAR(16) NOT NULL DEFAULT '&#128138;'
);

-- --------------------------------------------------------------
-- dispense history table (audit trail for dispensed medications)
-- --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dispense_history` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(100) NULL,
    medication_sku VARCHAR(20) NULL,
    medication_name VARCHAR(100) NULL,
    qty INT NOT NULL DEFAULT 1,
    channel INT NULL,
    dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dispensed_at (dispensed_at)
);

-- --------------------------------------------------------------
-- Seed data: admin account intentionally omitted from setup scripts.
-- Create the first administrator manually after installation using a
-- secure password and a dedicated admin email address.
-- --------------------------------------------------------------

-- --------------------------------------------------------------
-- Seed data: 5 medications matching the 5 dispenser channels
-- --------------------------------------------------------------
INSERT INTO `medications` (channel, name, sku, price, stock, category, icon) VALUES
(1, 'Paracetamol 500mg', 'MED-001', 45.00, 420, 'Tablet', '&#128138;'),
(2, 'Amoxicillin 500mg', 'MED-014', 12.00, 86, 'Capsule', '&#128138;'),
(3, 'Metformin 850mg', 'MED-032', 8.50, 24, 'Tablet', '&#128138;'),
(4, 'Salbutamol Inhaler', 'MED-078', 185.00, 15, 'Inhaler', '&#129657;'),
(5, 'Vitamin B-Complex', 'MED-091', 95.00, 210, 'Supplement', '&#128056;')
ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price), category = VALUES(category), icon = VALUES(icon);
