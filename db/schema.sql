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
