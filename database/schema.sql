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

CREATE TABLE user_departments (
    user_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, department_id),
    CONSTRAINT fk_user_departments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_departments_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE CASCADE
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

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    request_ip VARCHAR(45) NULL,
    was_success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_time (email_hash, created_at),
    INDEX idx_login_attempts_ip_time (request_ip, created_at)
);

CREATE TABLE password_reset_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    request_ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_requests_email_time (email_hash, created_at),
    INDEX idx_password_reset_requests_ip_time (request_ip, created_at)
);

CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    request_ip VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_tokens_user (user_id),
    INDEX idx_password_reset_tokens_expires (expires_at),
    CONSTRAINT fk_password_reset_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
);

CREATE TABLE dmv_lienholders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    access_lienholder_id INT UNSIGNED NULL UNIQUE,
    created_by INT UNSIGNED NULL,
    company_name VARCHAR(180) NOT NULL,
    contact_name VARCHAR(160) NULL,
    mailing_address VARCHAR(190) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(40) NOT NULL,
    zip_code VARCHAR(20) NOT NULL,
    phone VARCHAR(40) NULL,
    phone_extension VARCHAR(20) NULL,
    fax VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
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
    review_status ENUM('unreviewed', 'verified', 'needs_review', 'local_alias') NOT NULL DEFAULT 'unreviewed',
    official_name VARCHAR(100) NULL,
    review_notes TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE dmv_vehicle_models (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    make_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    review_status ENUM('unreviewed', 'verified', 'needs_review', 'local_alias') NOT NULL DEFAULT 'unreviewed',
    official_name VARCHAR(100) NULL,
    review_notes TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
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

CREATE TABLE dare_schools (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    access_school_id VARCHAR(40) NULL UNIQUE,
    name VARCHAR(160) NOT NULL UNIQUE,
    address VARCHAR(190) NULL,
    city VARCHAR(100) NULL,
    state VARCHAR(40) NULL,
    zip_code VARCHAR(20) NULL,
    principal_name VARCHAR(160) NULL,
    sheriff_name VARCHAR(160) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE dare_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE dare_officers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    access_instructor_id VARCHAR(40) NULL UNIQUE,
    user_id INT UNSIGNED NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dare_officer_name (last_name, first_name),
    CONSTRAINT fk_dare_officers_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE dare_teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    access_teacher_id VARCHAR(40) NULL UNIQUE,
    school_id INT UNSIGNED NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dare_teacher_name (last_name, first_name),
    CONSTRAINT fk_dare_teachers_school
        FOREIGN KEY (school_id) REFERENCES dare_schools(id)
        ON DELETE SET NULL
);

CREATE TABLE dare_classes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    access_dareclass_id VARCHAR(40) NULL UNIQUE,
    school_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NULL,
    officer_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    school_year VARCHAR(20) NULL,
    class_name VARCHAR(160) NOT NULL,
    semester VARCHAR(80) NULL,
    period VARCHAR(40) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    graduation_date DATE NULL,
    status ENUM('active', 'completed', 'graduated', 'closed', 'cancelled') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dare_class_dates (start_date, end_date),
    INDEX idx_dare_class_status (status),
    CONSTRAINT fk_dare_classes_school
        FOREIGN KEY (school_id) REFERENCES dare_schools(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_dare_classes_teacher
        FOREIGN KEY (teacher_id) REFERENCES dare_teachers(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_dare_classes_officer
        FOREIGN KEY (officer_id) REFERENCES dare_officers(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_dare_classes_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE dare_students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    access_student_id VARCHAR(40) NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dare_student_name (last_name, first_name)
);

CREATE TABLE dare_class_students (
    class_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    essay_completed TINYINT(1) NOT NULL DEFAULT 0,
    essay_winner TINYINT(1) NOT NULL DEFAULT 0,
    gender VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (class_id, student_id),
    CONSTRAINT fk_dare_class_students_class
        FOREIGN KEY (class_id) REFERENCES dare_classes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dare_class_students_student
        FOREIGN KEY (student_id) REFERENCES dare_students(id)
        ON DELETE CASCADE
);

CREATE TABLE dare_lessons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dare_lessons_active_order (is_active, sort_order, title)
);

CREATE TABLE dare_class_lessons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NULL,
    lesson_title VARCHAR(160) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at TIMESTAMP NULL,
    completed_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dare_class_lesson (class_id, sort_order, lesson_title),
    INDEX idx_dare_class_lessons_class_completed (class_id, completed_at),
    CONSTRAINT fk_dare_class_lessons_class
        FOREIGN KEY (class_id) REFERENCES dare_classes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_dare_class_lessons_lesson
        FOREIGN KEY (lesson_id) REFERENCES dare_lessons(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_dare_class_lessons_completed_by
        FOREIGN KEY (completed_by) REFERENCES users(id)
        ON DELETE SET NULL
);

INSERT INTO departments (name, slug, description) VALUES
    ('DMV', 'dmv', 'DMV department database module.'),
    ('DARE', 'dare', 'DARE department database module.'),
    ('K-9', 'k-9', 'K-9 department database module.');
