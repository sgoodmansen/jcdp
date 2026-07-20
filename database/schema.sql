CREATE DATABASE IF NOT EXISTS jc_data_portal
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE jc_data_portal;

CREATE TABLE departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('standard_user', 'department_admin', 'system_admin') NOT NULL DEFAULT 'standard_user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL
);

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(120) NULL,
    entity_id VARCHAR(120) NULL,
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE dmv_lienholders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by INT UNSIGNED NULL,
    company_name VARCHAR(180) NOT NULL,
    contact_name VARCHAR(160) NULL,
    mailing_address VARCHAR(190) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(40) NOT NULL,
    zip_code VARCHAR(20) NOT NULL,
    phone VARCHAR(40) NULL,
    fax VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dmv_lienholder_company (company_name),
    CONSTRAINT fk_dmv_lienholders_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE dmv_title_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lienholder_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    request_date DATE NOT NULL,
    registrant_name VARCHAR(160) NOT NULL,
    registrant_name_2 VARCHAR(160) NULL,
    registrant_address VARCHAR(190) NOT NULL,
    registrant_city VARCHAR(100) NOT NULL,
    registrant_state VARCHAR(40) NOT NULL,
    registrant_zip_code VARCHAR(20) NOT NULL,
    registrant_phone VARCHAR(40) NULL,
    vehicle_year VARCHAR(10) NULL,
    vehicle_make_id INT UNSIGNED NULL,
    vehicle_model_id INT UNSIGNED NULL,
    vehicle_make VARCHAR(80) NULL,
    vehicle_model VARCHAR(80) NULL,
    vin VARCHAR(80) NULL,
    status ENUM('draft', 'sent', 'received', 'closed') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dmv_request_date (request_date),
    INDEX idx_dmv_registrant_name (registrant_name),
    INDEX idx_dmv_status (status),
    CONSTRAINT fk_dmv_title_requests_lienholder
        FOREIGN KEY (lienholder_id) REFERENCES dmv_lienholders(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_dmv_title_requests_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE dmv_vehicle_makes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE dmv_vehicle_models (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    make_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dmv_model_make_name (make_id, name),
    CONSTRAINT fk_dmv_vehicle_models_make
        FOREIGN KEY (make_id) REFERENCES dmv_vehicle_makes(id)
        ON DELETE CASCADE
);

CREATE TABLE dmv_vehicle_make_aliases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    make_id INT UNSIGNED NOT NULL,
    alias VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dmv_vehicle_make_aliases_make
        FOREIGN KEY (make_id) REFERENCES dmv_vehicle_makes(id)
        ON DELETE CASCADE
);

INSERT INTO departments (name, slug, description) VALUES
    ('DMV', 'dmv', 'DMV department database module.'),
    ('DARE', 'dare', 'DARE department database module.'),
    ('K-9', 'k-9', 'K-9 department database module.');
