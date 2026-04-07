-- RCMP NIMS MySQL Schema
-- Run: mysql -u root -p < db/schema.sql

CREATE DATABASE IF NOT EXISTS nims
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE nims;

CREATE TABLE IF NOT EXISTS role (
  id INT UNSIGNED PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE
);

INSERT IGNORE INTO role (id, name) VALUES (1, 'technician'), (2, 'admin'), (3, 'nextcheck users');

CREATE TABLE IF NOT EXISTS users (
  staff_id VARCHAR(32) NOT NULL,
  full_name VARCHAR(128) NOT NULL,
  email VARCHAR(128) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  phone VARCHAR(64) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (staff_id),
  FOREIGN KEY (role_id) REFERENCES role(id),
  UNIQUE KEY uq_users_email (email),
  INDEX idx_staff_id (staff_id),
  INDEX idx_role_id (role_id)
);

-- RCMP staff directory (CSV import, handover recipients). Linked from handover_staff.employee_no
CREATE TABLE IF NOT EXISTS staff (
  `employee_no` VARCHAR(32) NOT NULL,
  `full_name` VARCHAR(128) NOT NULL,
  `department` VARCHAR(128) DEFAULT NULL,
  `email` VARCHAR(128) DEFAULT NULL,
  `phone` VARCHAR(64) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_no`),
  INDEX idx_staff_full_name (full_name),
  INDEX idx_staff_department (department)
);

CREATE TABLE IF NOT EXISTS status (
  status_id INT UNSIGNED PRIMARY KEY,
  name VARCHAR(128) NOT NULL UNIQUE
);

INSERT IGNORE INTO status (status_id, name) VALUES (1, 'Active'), (2, 'Non-active'), (3, 'Deploy'), (4, 'Reserved'), (5, 'Maintenance'), (6, 'Faulty'), 
(7, 'Disposed'), (8, 'Lost'), (9, 'Online'), (10, 'Offline'), (11, 'Active (nextcheck)'), (12, 'Pending (nextcheck)'), (13, 'Checkout (nextcheck)');

CREATE TABLE IF NOT EXISTS laptop (
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
    `status_id` INT UNSIGNED NOT NULL COMMENT '1=Active, 2=Non-active, 3=Deploy, 4=Reserved, 5=Maintenance, 6=Faulty, 7=Disposed, 8=Lost',
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id),
    FOREIGN KEY (status_id) REFERENCES status(status_id),
    INDEX idx_asset_id (asset_id),
    INDEX idx_status_id (status_id)
);

CREATE TABLE IF NOT EXISTS network (
    `asset_id` INT(11) NOT NULL,
    `serial_num` VARCHAR(100) DEFAULT NULL,
    `brand` VARCHAR(100) DEFAULT NULL,
    `model` VARCHAR(100) DEFAULT NULL,
    `mac_address` VARCHAR(100) DEFAULT NULL,
    `ip_address` VARCHAR(100) DEFAULT NULL,
    `PO_DATE` DATE DEFAULT NULL,
    `PO_NUM` VARCHAR(50) DEFAULT NULL,
    `DO_DATE` DATE DEFAULT NULL,
    `DO_NUM` VARCHAR(50) DEFAULT NULL,
    `INVOICE_DATE` DATE DEFAULT NULL,
    `INVOICE_NUM` VARCHAR(50) DEFAULT NULL,
    `PURCHASE_COST` DECIMAL(10,2) DEFAULT NULL,
    `status_id` INT UNSIGNED NOT NULL COMMENT '9=Online, 10=Offline, 3=Deploy, 5=Maintenance, 6=Faulty, 7=Disposed, 8=Lost',
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id),
    FOREIGN KEY (status_id) REFERENCES status(status_id),
    INDEX idx_asset_id (asset_id),
    INDEX idx_status_id (status_id)
);

-- AV assets (audio/visual equipment: speaker, mic, projector, etc.)
CREATE TABLE IF NOT EXISTS av (
    `asset_id` INT(11) NOT NULL,
    `asset_id_old` VARCHAR(64) DEFAULT NULL,
    `category` VARCHAR(128) DEFAULT NULL,
    `brand` VARCHAR(100) DEFAULT NULL,
    `model` VARCHAR(100) DEFAULT NULL,
    `serial_num` VARCHAR(100) DEFAULT NULL,
    `status_id` INT UNSIGNED NOT NULL COMMENT '1=Active, 2=Non-active, 3=Deploy, 5=Maintenance, 6=Faulty, 7=Disposed, 8=Lost',
    `PO_DATE` DATE DEFAULT NULL,
    `PO_NUM` VARCHAR(50) DEFAULT NULL,
    `DO_DATE` DATE DEFAULT NULL,
    `DO_NUM` VARCHAR(50) DEFAULT NULL,
    `INVOICE_DATE` DATE DEFAULT NULL,
    `INVOICE_NUM` VARCHAR(50) DEFAULT NULL,
    `PURCHASE_COST` DECIMAL(10,2) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (asset_id),
    FOREIGN KEY (status_id) REFERENCES status(status_id),
    INDEX idx_asset_id (asset_id),
    INDEX idx_status_id (status_id)
);

CREATE TABLE IF NOT EXISTS av_deployment (
  `deployment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `asset_id` INT(11) NOT NULL,
  `building` VARCHAR(128) NOT NULL,
  `level` VARCHAR(128) NOT NULL,
  `zone` VARCHAR(128) NOT NULL,
  `deployment_date` DATE NOT NULL,
  `deployment_remarks` TEXT DEFAULT NULL,
  `staff_id` VARCHAR(32) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (deployment_id),
  FOREIGN KEY (asset_id) REFERENCES av(asset_id),
  FOREIGN KEY (staff_id) REFERENCES users(staff_id),
  INDEX idx_deployment_id (deployment_id),
  INDEX idx_asset_id (asset_id)
);

CREATE TABLE IF NOT EXISTS handover (
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

CREATE TABLE IF NOT EXISTS network_deployment (
  `deployment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `asset_id` INT(11) NOT NULL,
  `building` VARCHAR(128) NOT NULL,
  `level` VARCHAR(128) NOT NULL,
  `zone` VARCHAR(128) NOT NULL,
  `deployment_date` DATE NOT NULL,
  `deployment_remarks` TEXT DEFAULT NULL,
  `staff_id` VARCHAR(32) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (deployment_id),
  FOREIGN KEY (asset_id) REFERENCES network(asset_id),
  FOREIGN KEY (staff_id) REFERENCES users(staff_id),
  INDEX idx_deployment_id (deployment_id),
  INDEX idx_asset_id (asset_id)
);

-- One row per recipient on a handover; person details live in staff.
CREATE TABLE IF NOT EXISTS handover_staff(
  `handover_staff_id` INT(11) NOT NULL AUTO_INCREMENT,
  `employee_no` VARCHAR(32) NOT NULL,
  `handover_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (handover_staff_id),
  FOREIGN KEY (`employee_no`) REFERENCES staff(`employee_no`),
  FOREIGN KEY (`handover_id`) REFERENCES handover(`handover_id`),
  INDEX idx_handover_staff_employee (`employee_no`),
  INDEX idx_handover_id (`handover_id`)
);

CREATE TABLE IF NOT EXISTS warranty(
  `warranty_id` INT(11) NOT NULL AUTO_INCREMENT,
  `asset_id` INT(11) NOT NULL,
  `asset_type` ENUM('laptop','network') NOT NULL DEFAULT 'laptop',
  `warranty_start_date` DATE NOT NULL,
  `warranty_end_date` DATE NOT NULL,
  `warranty_remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (warranty_id),
  INDEX idx_warranty_id (warranty_id),
  INDEX idx_asset_id (asset_id),
  INDEX idx_asset_type_asset (asset_type, asset_id)
);

-- In-house repairs for laptops with no (or expired) vendor warranty; logged by technician
CREATE TABLE IF NOT EXISTS repair (
  `repair_id` INT(11) NOT NULL AUTO_INCREMENT,
  `asset_id` INT(11) NOT NULL,
  `staff_id` VARCHAR(32) NOT NULL,
  `repair_date` DATE NOT NULL,
  `completed_date` DATE DEFAULT NULL,
  `issue_summary` VARCHAR(255) NOT NULL,
  `repair_remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`repair_id`),
  FOREIGN KEY (`asset_id`) REFERENCES `laptop`(`asset_id`),
  FOREIGN KEY (`staff_id`) REFERENCES `users`(`staff_id`),
  INDEX `idx_repair_asset_id` (`asset_id`),
  INDEX `idx_repair_staff_id` (`staff_id`),
  INDEX `idx_repair_date` (`repair_date`)
);

-- Warranty claim log (multiple claims allowed until warranty_end_date)
CREATE TABLE IF NOT EXISTS warranty_claim (
  `claim_id` INT(11) NOT NULL AUTO_INCREMENT,
  `asset_id` INT(11) NOT NULL,
  `warranty_id` INT(11) DEFAULT NULL,
  `claim_date` DATE NOT NULL,
  `claim_time` TIME DEFAULT NULL,
  `issue_summary` VARCHAR(255) NOT NULL,
  `claim_remarks` TEXT DEFAULT NULL,
  `claimed_by` VARCHAR(32) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`claim_id`),
  FOREIGN KEY (`asset_id`) REFERENCES `laptop`(`asset_id`),
  FOREIGN KEY (`warranty_id`) REFERENCES `warranty`(`warranty_id`),
  FOREIGN KEY (`claimed_by`) REFERENCES `users`(`staff_id`),
  INDEX `idx_claim_asset_id` (`asset_id`),
  INDEX `idx_claim_warranty_id` (`warranty_id`),
  INDEX `idx_claim_date` (`claim_date`),
  INDEX `idx_claimed_by` (`claimed_by`)
);

-- Return after a handover: either one staff recipient (handover_staff_id) or place-only handover (handover_id, no handover_staff row)
CREATE TABLE IF NOT EXISTS handover_return (
  `return_id` INT(11) NOT NULL AUTO_INCREMENT,
  `handover_staff_id` INT(11) DEFAULT NULL,
  `handover_id` INT(11) DEFAULT NULL,
  `returned_by` VARCHAR(32) NOT NULL,
  `return_date` DATE NOT NULL,
  `return_time` TIME DEFAULT NULL,
  `return_place` VARCHAR(128) DEFAULT NULL,
  `condition` VARCHAR(64) DEFAULT NULL,
  `return_remarks` TEXT DEFAULT NULL,
  `return_status_id` INT UNSIGNED DEFAULT NULL,
  `return_dedupe_key` VARCHAR(48) GENERATED ALWAYS AS (
    CASE
      WHEN `handover_staff_id` IS NOT NULL THEN CONCAT('S', `handover_staff_id`)
      WHEN `handover_id` IS NOT NULL THEN CONCAT('P', `handover_id`)
      ELSE 'X0'
    END
  ) STORED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`return_id`),
  CONSTRAINT `chk_return_staff_or_place` CHECK (
    (`handover_staff_id` IS NOT NULL AND `handover_id` IS NULL)
    OR (`handover_staff_id` IS NULL AND `handover_id` IS NOT NULL)
  ),
  FOREIGN KEY (`handover_staff_id`) REFERENCES `handover_staff`(`handover_staff_id`),
  FOREIGN KEY (`handover_id`) REFERENCES `handover`(`handover_id`),
  FOREIGN KEY (`returned_by`) REFERENCES `users`(`staff_id`),
  FOREIGN KEY (`return_status_id`) REFERENCES `status`(`status_id`),
  UNIQUE KEY `uq_return_dedupe_key` (`return_dedupe_key`),
  INDEX `idx_return_handover_id` (`handover_id`),
  INDEX `idx_returned_by` (`returned_by`),
  INDEX `idx_return_date` (`return_date`),
  INDEX `idx_return_status_id` (`return_status_id`)
);

-- NextCheck: user submits request (categories only); technician assigns laptop assets (status 12 → 13 on laptop)
CREATE TABLE IF NOT EXISTS nexcheck_request (
  `nexcheck_id` INT(11) NOT NULL AUTO_INCREMENT,
  `requested_by` VARCHAR(32) NOT NULL,
  `borrow_date` DATE NOT NULL,
  `return_date` DATE NOT NULL,
  `program_type` VARCHAR(128) NOT NULL COMMENT 'e.g. academic project/class, Official Event, Club/Society',
  `usage_location` VARCHAR(255) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `terms_accepted_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`nexcheck_id`),
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`staff_id`),
  INDEX `idx_nexcheck_requested_by` (`requested_by`),
  INDEX `idx_nexcheck_borrow_date` (`borrow_date`),
  INDEX `idx_nexcheck_return_date` (`return_date`)
);

CREATE TABLE IF NOT EXISTS nexcheck_request_item (
  `request_item_id` INT(11) NOT NULL AUTO_INCREMENT,
  `nexcheck_id` INT(11) NOT NULL,
  `category` VARCHAR(128) NOT NULL COMMENT 'e.g. Laptop, Projector — user selects category only',
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_item_id`),
  FOREIGN KEY (`nexcheck_id`) REFERENCES `nexcheck_request`(`nexcheck_id`) ON DELETE CASCADE,
  INDEX `idx_nexcheck_item_request` (`nexcheck_id`)
);

CREATE TABLE IF NOT EXISTS nexcheck_assignment (
  `assignment_id` INT(11) NOT NULL AUTO_INCREMENT,
  `nexcheck_id` INT(11) NOT NULL,
  `request_item_id` INT(11) DEFAULT NULL,
  `asset_id` INT(11) NOT NULL,
  `assigned_by` VARCHAR(32) NOT NULL,
  `assigned_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'set when asset marked Pending (nextcheck) 12',
  `checkout_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'set when asset marked Checkout (nextcheck) 13',
  `returned_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'technician checked equipment back in',
  `return_condition` VARCHAR(512) DEFAULT NULL COMMENT 'physical condition / notes at return',
  `returned_by` VARCHAR(32) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  FOREIGN KEY (`nexcheck_id`) REFERENCES `nexcheck_request`(`nexcheck_id`) ON DELETE CASCADE,
  FOREIGN KEY (`request_item_id`) REFERENCES `nexcheck_request_item`(`request_item_id`) ON DELETE SET NULL,
  FOREIGN KEY (`asset_id`) REFERENCES `laptop`(`asset_id`),
  FOREIGN KEY (`assigned_by`) REFERENCES `users`(`staff_id`),
  FOREIGN KEY (`returned_by`) REFERENCES `users`(`staff_id`),
  UNIQUE KEY `uq_nexcheck_asset_per_request` (`nexcheck_id`, `asset_id`),
  INDEX `idx_nexcheck_assignment_asset` (`asset_id`),
  INDEX `idx_nexcheck_assignment_returned` (`returned_at`)
);

-- Disposal process (one disposal form can include multiple assets)
CREATE TABLE IF NOT EXISTS disposal (
  `disposal_id` INT(11) NOT NULL AUTO_INCREMENT,
  `requested_by` VARCHAR(32) NOT NULL,
  `disposal_date` DATE NOT NULL,
  `disposal_time` TIME DEFAULT NULL,
  `disposal_remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`disposal_id`),
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`staff_id`),
  INDEX `idx_disposal_requested_by` (`requested_by`),
  INDEX `idx_disposal_date` (`disposal_date`)
);

CREATE TABLE IF NOT EXISTS disposal_item (
  `disposal_item_id` INT(11) NOT NULL AUTO_INCREMENT,
  `disposal_id` INT(11) NOT NULL,
  `asset_id` INT(11) NOT NULL,
  `asset_type` ENUM('laptop','network') NOT NULL DEFAULT 'laptop',
  `item_remarks` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`disposal_item_id`),
  FOREIGN KEY (`disposal_id`) REFERENCES `disposal`(`disposal_id`) ON DELETE CASCADE,
  FOREIGN KEY (`asset_id`) REFERENCES `laptop`(`asset_id`),
  UNIQUE KEY `uq_disposal_asset` (`disposal_id`, `asset_id`, `asset_type`),
  INDEX `idx_disposal_item_disposal_id` (`disposal_id`),
  INDEX `idx_disposal_item_asset_id` (`asset_id`)
);