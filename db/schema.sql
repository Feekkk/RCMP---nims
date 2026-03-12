-- RCMP NIMS MySQL Schema
-- Run: mysql -u root -p < db/schema.sql

CREATE DATABASE IF NOT EXISTS nims
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE nims;

CREATE TABLE role (
  id INT UNSIGNED PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE
);

INSERT INTO role (id, name) VALUES (1, 'technician'), (2, 'admin');

CREATE TABLE users (
  staff_id VARCHAR(32) NOT NULL,
  full_name VARCHAR(128) NOT NULL,
  email VARCHAR(128) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (staff_id),
  FOREIGN KEY (role_id) REFERENCES role(id),
  INDEX idx_staff_id (staff_id),
  INDEX idx_role_id (role_id)
);

CREATE TABLE status (
  status_id INT UNSIGNED PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE
);

INSERT INTO status (status_id, name) VALUES (1, 'Active'), (2, 'Non-active'), (3, 'Deploy'), (4, 'Reserved'), (5, 'Maintenance'), (6, 'Faulty'), (7, 'Disposed'), (8, 'Lost'), (9, 'Online'), (10, 'Offline');

CREATE TABLE laptop (
    `asset_id` INT(11) NOT NULL,
    `serial_num` VARCHAR(100) DEFAULT NULL,
    `brand` VARCHAR(100) DEFAULT NULL,
    `model` VARCHAR(100) DEFAULT NULL,
    `category` VARCHAR(100) DEFAULT NULL,
    `part_number` VARCHAR(100) DEFAULT NULL,
    `processor` VARCHAR(100) DEFAULT NULL,
    `memory` VARCHAR(100) DEFAULT NULL,
    `os` VARCHAR(100) DEFAULT NULL,
    `storage` VARCHAR(100) DEFAULT NULL,
    `gpu` VARCHAR(100) DEFAULT NULL,
    `PO_DATE` DATE DEFAULT NULL,
    `PO_NUM` VARCHAR(50) DEFAULT NULL,
    `DO_DATE` DATE DEFAULT NULL,
    `DO_NUM` VARCHAR(50) DEFAULT NULL,
    `INVOICE_DATE` DATE DEFAULT NULL,
    `INVOICE_NUM` VARCHAR(50) DEFAULT NULL,
    `PURCHASE_COST` DECIMAL(10,2) DEFAULT NULL,
    `status_id` INT UNSIGNED NOT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id),
    FOREIGN KEY (status_id) REFERENCES status(status_id),
    INDEX idx_asset_id (asset_id),
    INDEX idx_status_id (status_id)
);

CREATE TABLE handover (
  `handover_id` INT(11) NOT NULL AUTO_INCREMENT,
  `asset_id` INT(11) NOT NULL,
  `staff_id` VARCHAR(32) NOT NULL,
  `handover_date` DATE NOT NULL,
  `handover_remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (handover_id),
  FOREIGN KEY (asset_id) REFERENCES laptop(asset_id),
  FOREIGN KEY (staff_id) REFERENCES users(staff_id),
  INDEX idx_handover_id (handover_id),
  INDEX idx_asset_id (asset_id)
);

CREATE TABLE handover_staff(
  `handover_staff_id` INT(11) NOT NULL AUTO_INCREMENT,
  `staff_id` VARCHAR(32) NOT NULL,
  `handover_id` INT(11) NOT NULL,
  `department` VARCHAR(128) NOT NULL,
  `assignment_type` VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (handover_staff_id),
  FOREIGN KEY (staff_id) REFERENCES users(staff_id),
  FOREIGN KEY (handover_id) REFERENCES handover(handover_id),
  INDEX idx_staff_id (staff_id),
  INDEX idx_handover_id (handover_id)
);

CREATE TABLE warranty(
  `warranty_id` INT(11) NOT NULL AUTO_INCREMENT,
  `asset_id` INT(11) NOT NULL,
  `warranty_start_date` DATE NOT NULL,
  `warranty_end_date` DATE NOT NULL,
  `warranty_remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (warranty_id),
  FOREIGN KEY (asset_id) REFERENCES laptop(asset_id),
  INDEX idx_warranty_id (warranty_id),
  INDEX idx_asset_id (asset_id)
);