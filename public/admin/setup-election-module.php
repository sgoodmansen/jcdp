<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

$ranSetup = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statements = [
        "INSERT INTO departments (name, slug, description)
         VALUES ('Election Readiness', 'election', 'Election day preparation, staffing, training, checklist, and follow-up module.')
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)",

        "CREATE TABLE IF NOT EXISTS election_periods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_period_active_dates (is_active, starts_on, ends_on)
        )",

        "CREATE TABLE IF NOT EXISTS election_precincts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL UNIQUE,
            location_name VARCHAR(160) NOT NULL,
            street_address VARCHAR(190) NOT NULL,
            city VARCHAR(100) NOT NULL,
            state VARCHAR(40) NOT NULL,
            zip_code VARCHAR(20) NOT NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_precinct_active_name (is_active, name)
        )",

        "CREATE TABLE IF NOT EXISTS election_positions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description TEXT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_chief_judge TINYINT(1) NOT NULL DEFAULT 0,
            is_assistant_chief_judge TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_position_active_order (is_active, sort_order, name)
        )",

        "CREATE TABLE IF NOT EXISTS election_settings (
            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS election_workers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NULL,
            precinct_id INT UNSIGNED NULL,
            position_id INT UNSIGNED NULL,
            recruited_by_worker_id INT UNSIGNED NULL,
            created_by_user_id INT UNSIGNED NULL,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NULL,
            email_normalized VARCHAR(190) NULL,
            phone VARCHAR(40) NULL,
            phone_digits VARCHAR(20) NULL,
            name_key VARCHAR(170) NULL,
            mobile_phone VARCHAR(40) NULL,
            mailing_address VARCHAR(190) NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(40) NULL,
            zip_code VARCHAR(20) NULL,
            wants_email_reminders TINYINT(1) NOT NULL DEFAULT 0,
            wants_text_reminders TINYINT(1) NOT NULL DEFAULT 0,
            reminder_preferences_asked_at TIMESTAMP NULL,
            access_token_hash CHAR(64) NULL UNIQUE,
            access_token_created_at TIMESTAMP NULL,
            availability_status VARCHAR(20) NOT NULL DEFAULT 'active',
            unavailable_reason TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_worker_name (last_name, first_name),
            INDEX idx_election_worker_email_normalized (email_normalized),
            INDEX idx_election_worker_phone_digits (phone_digits),
            INDEX idx_election_worker_name_key (name_key, zip_code),
            INDEX idx_election_worker_availability (availability_status, is_active),
            INDEX idx_election_worker_lookup (election_period_id, precinct_id, position_id, is_active),
            CONSTRAINT fk_election_workers_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_workers_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_workers_position FOREIGN KEY (position_id) REFERENCES election_positions(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_workers_recruited_by FOREIGN KEY (recruited_by_worker_id) REFERENCES election_workers(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_workers_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_training_classes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NULL,
            class_title VARCHAR(180) NOT NULL,
            class_date DATE NOT NULL,
            start_time TIME NOT NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            building_address VARCHAR(190) NOT NULL,
            room_location VARCHAR(160) NULL,
            instructor_name VARCHAR(160) NOT NULL,
            seats_total INT UNSIGNED NOT NULL,
            notes TEXT NULL,
            is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_class_date (class_date, start_time),
            CONSTRAINT fk_election_classes_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_classes_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_worker_assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_id INT UNSIGNED NOT NULL,
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            position_id INT UNSIGNED NOT NULL,
            recruited_by_assignment_id INT UNSIGNED NULL,
            created_by_user_id INT UNSIGNED NULL,
            is_extra TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_election_assignment (worker_id, election_period_id, precinct_id, position_id),
            INDEX idx_election_assignment_lookup (election_period_id, precinct_id, position_id, is_active),
            CONSTRAINT fk_election_assignments_worker FOREIGN KEY (worker_id) REFERENCES election_workers(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_assignments_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_assignments_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_assignments_position FOREIGN KEY (position_id) REFERENCES election_positions(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_assignments_recruited_by FOREIGN KEY (recruited_by_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_assignments_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_worker_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NULL,
            note_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_election_worker_notes_worker_date (worker_id, created_at),
            CONSTRAINT fk_election_worker_notes_worker FOREIGN KEY (worker_id) REFERENCES election_workers(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_worker_notes_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_precinct_roles (
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            role_key VARCHAR(80) NOT NULL,
            assignment_id INT UNSIGNED NULL,
            created_by_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (election_period_id, precinct_id, role_key),
            INDEX idx_election_precinct_roles_assignment (assignment_id),
            CONSTRAINT fk_election_precinct_roles_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_precinct_roles_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_precinct_roles_assignment FOREIGN KEY (assignment_id) REFERENCES election_worker_assignments(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_precinct_roles_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_precinct_roles_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_training_class_positions (
            class_id INT UNSIGNED NOT NULL,
            position_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (class_id, position_id),
            CONSTRAINT fk_election_class_positions_class FOREIGN KEY (class_id) REFERENCES election_training_classes(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_class_positions_position FOREIGN KEY (position_id) REFERENCES election_positions(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS election_day_checklist_tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NOT NULL,
            task_title VARCHAR(190) NOT NULL,
            instructions TEXT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            chief_can_complete TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_day_tasks_period_order (election_period_id, is_active, sort_order, task_title),
            CONSTRAINT fk_election_day_tasks_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_day_tasks_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_day_checklist_completions (
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            task_id INT UNSIGNED NOT NULL,
            completed_at TIMESTAMP NULL,
            completed_by_user_id INT UNSIGNED NULL,
            completed_by_assignment_id INT UNSIGNED NULL,
            notes TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (election_period_id, precinct_id, task_id),
            CONSTRAINT fk_election_day_completions_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_day_completions_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_day_completions_task FOREIGN KEY (task_id) REFERENCES election_day_checklist_tasks(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_day_completions_user FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_day_completions_assignment FOREIGN KEY (completed_by_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_day_equipment_schedules (
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            delivery_date DATE NULL,
            delivery_time TIME NULL,
            pickup_date DATE NULL,
            pickup_time TIME NULL,
            notes TEXT NULL,
            updated_by_user_id INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (election_period_id, precinct_id),
            CONSTRAINT fk_election_day_equipment_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_day_equipment_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_day_equipment_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_precinct_notes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            note_type VARCHAR(40) NOT NULL DEFAULT 'other',
            note_text TEXT NOT NULL,
            is_resolved TINYINT(1) NOT NULL DEFAULT 0,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_precinct_notes_lookup (election_period_id, precinct_id, is_resolved, created_at),
            CONSTRAINT fk_election_precinct_notes_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_precinct_notes_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_precinct_notes_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_debrief_questions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NOT NULL,
            question_text VARCHAR(255) NOT NULL,
            help_text TEXT NULL,
            response_type VARCHAR(30) NOT NULL DEFAULT 'long_text',
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_debrief_questions_period_order (election_period_id, is_active, sort_order),
            CONSTRAINT fk_election_debrief_questions_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_debrief_questions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_debrief_responses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            submitted_at TIMESTAMP NULL,
            submitted_by_user_id INT UNSIGNED NULL,
            submitted_by_assignment_id INT UNSIGNED NULL,
            other_comments TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_election_debrief_response (election_period_id, precinct_id),
            CONSTRAINT fk_election_debrief_responses_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_debrief_responses_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_debrief_responses_user FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_debrief_responses_assignment FOREIGN KEY (submitted_by_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_debrief_answers (
            response_id BIGINT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            answer_text TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (response_id, question_id),
            CONSTRAINT fk_election_debrief_answers_response FOREIGN KEY (response_id) REFERENCES election_debrief_responses(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_debrief_answers_question FOREIGN KEY (question_id) REFERENCES election_debrief_questions(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS election_chief_feedback (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            chief_assignment_id INT UNSIGNED NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'other',
            message_text TEXT NOT NULL,
            created_by_user_id INT UNSIGNED NULL,
            updated_by_user_id INT UNSIGNED NULL,
            acknowledged_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_chief_feedback_lookup (election_period_id, precinct_id, chief_assignment_id, acknowledged_at),
            CONSTRAINT fk_election_chief_feedback_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_chief_feedback_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_chief_feedback_assignment FOREIGN KEY (chief_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE RESTRICT,
            CONSTRAINT fk_election_chief_feedback_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_chief_feedback_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS election_training_registrations (
            class_id INT UNSIGNED NOT NULL,
            worker_id INT UNSIGNED NOT NULL,
            registered_by_user_id INT UNSIGNED NULL,
            assignment_id INT UNSIGNED NULL,
            registered_by_assignment_id INT UNSIGNED NULL,
            attended TINYINT(1) NOT NULL DEFAULT 0,
            attended_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (class_id, worker_id),
            CONSTRAINT fk_election_registrations_class FOREIGN KEY (class_id) REFERENCES election_training_classes(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_registrations_worker FOREIGN KEY (worker_id) REFERENCES election_workers(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_registrations_assignment FOREIGN KEY (assignment_id) REFERENCES election_worker_assignments(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_registrations_user FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_registrations_assignment_by FOREIGN KEY (registered_by_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE SET NULL
        )",

        "INSERT INTO election_positions (name, sort_order, is_chief_judge, is_assistant_chief_judge)
         VALUES
            ('Chief Judge', 10, 1, 0),
            ('Assistant Chief Judge', 20, 0, 1),
            ('Greeter / Registrar', 30, 0, 0),
            ('E Poll Book Clerk', 40, 0, 0),
            ('Issuing Clerk', 50, 0, 0),
            ('Express Vote Specialist', 60, 0, 0),
            ('DS 300 Specialist', 70, 0, 0),
            ('Receiving Clerk', 80, 0, 0)
         ON DUPLICATE KEY UPDATE
            sort_order = VALUES(sort_order),
            is_chief_judge = VALUES(is_chief_judge),
            is_assistant_chief_judge = VALUES(is_assistant_chief_judge),
            is_active = 1",
    ];

    foreach ($statements as $sql) {
        db()->exec($sql);
    }

    $columns = db()->query("SHOW COLUMNS FROM election_precincts")->fetchAll();
    $columnNames = array_column($columns, 'Field');
    $columnSql = [
        'location_name' => "ALTER TABLE election_precincts ADD COLUMN location_name VARCHAR(160) NULL AFTER name",
        'street_address' => "ALTER TABLE election_precincts ADD COLUMN street_address VARCHAR(190) NULL AFTER location_name",
        'city' => "ALTER TABLE election_precincts ADD COLUMN city VARCHAR(100) NULL AFTER street_address",
        'state' => "ALTER TABLE election_precincts ADD COLUMN state VARCHAR(40) NULL AFTER city",
        'zip_code' => "ALTER TABLE election_precincts ADD COLUMN zip_code VARCHAR(20) NULL AFTER state",
    ];

    foreach ($columnSql as $columnName => $sql) {
        if (!in_array($columnName, $columnNames, true)) {
            db()->exec($sql);
        }
    }

    if (in_array('building_address', $columnNames, true)) {
        db()->exec(
            "UPDATE election_precincts
             SET street_address = COALESCE(NULLIF(street_address, ''), building_address),
                 location_name = COALESCE(NULLIF(location_name, ''), name),
                 city = COALESCE(NULLIF(city, ''), ''),
                 state = COALESCE(NULLIF(state, ''), 'ID'),
                 zip_code = COALESCE(NULLIF(zip_code, ''), '')
             WHERE street_address IS NULL OR street_address = ''"
        );
        db()->exec("ALTER TABLE election_precincts MODIFY building_address VARCHAR(190) NULL");
    }

    if (in_array('room_location', $columnNames, true)) {
        db()->exec("ALTER TABLE election_precincts MODIFY room_location VARCHAR(160) NULL");
    }

    $workerColumns = db()->query("SHOW COLUMNS FROM election_workers")->fetchAll();
    $workerColumnNames = array_column($workerColumns, 'Field');
    $workerColumnSql = [
        'email_normalized' => "ALTER TABLE election_workers ADD COLUMN email_normalized VARCHAR(190) NULL AFTER email",
        'phone_digits' => "ALTER TABLE election_workers ADD COLUMN phone_digits VARCHAR(20) NULL AFTER phone",
        'name_key' => "ALTER TABLE election_workers ADD COLUMN name_key VARCHAR(170) NULL AFTER phone_digits",
        'availability_status' => "ALTER TABLE election_workers ADD COLUMN availability_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER access_token_created_at",
        'unavailable_reason' => "ALTER TABLE election_workers ADD COLUMN unavailable_reason TEXT NULL AFTER availability_status",
    ];

    foreach ($workerColumnSql as $columnName => $sql) {
        if (!in_array($columnName, $workerColumnNames, true)) {
            db()->exec($sql);
        }
    }

    db()->exec("ALTER TABLE election_workers MODIFY election_period_id INT UNSIGNED NULL");
    db()->exec("ALTER TABLE election_workers MODIFY precinct_id INT UNSIGNED NULL");
    db()->exec("ALTER TABLE election_workers MODIFY position_id INT UNSIGNED NULL");

    db()->exec(
        "UPDATE election_workers
         SET email_normalized = NULLIF(LOWER(TRIM(email)), ''),
             phone_digits = NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''),
             phone = CASE
                 WHEN LENGTH(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), '')) = 10
                     THEN CONCAT('(', SUBSTRING(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''), 1, 3), ') ', SUBSTRING(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''), 4, 3), '-', SUBSTRING(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''), 7, 4))
                 WHEN LENGTH(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), '')) = 11
                      AND LEFT(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''), 1) = '1'
                     THEN CONCAT('(', SUBSTRING(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''), 2, 3), ') ', SUBSTRING(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''), 5, 3), '-', SUBSTRING(NULLIF(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '+', ''), ''), 8, 4))
                 ELSE phone
             END,
             name_key = NULLIF(CONCAT(LOWER(REPLACE(TRIM(first_name), ' ', '')), '|', LOWER(REPLACE(TRIM(last_name), ' ', ''))), '|'),
             availability_status = CASE
                 WHEN availability_status IN ('unavailable', 'inactive') THEN availability_status
                 ELSE 'active'
             END,
             is_active = CASE
                 WHEN availability_status IN ('unavailable', 'inactive') THEN 0
                 ELSE 1
             END"
    );

    $workerIndexes = db()->query("SHOW INDEX FROM election_workers")->fetchAll();
    $workerIndexNames = array_unique(array_column($workerIndexes, 'Key_name'));
    $workerIndexSql = [
        'idx_election_worker_email_normalized' => 'ALTER TABLE election_workers ADD INDEX idx_election_worker_email_normalized (email_normalized)',
        'idx_election_worker_phone_digits' => 'ALTER TABLE election_workers ADD INDEX idx_election_worker_phone_digits (phone_digits)',
        'idx_election_worker_name_key' => 'ALTER TABLE election_workers ADD INDEX idx_election_worker_name_key (name_key, zip_code)',
        'idx_election_worker_availability' => 'ALTER TABLE election_workers ADD INDEX idx_election_worker_availability (availability_status, is_active)',
    ];

    foreach ($workerIndexSql as $indexName => $sql) {
        if (!in_array($indexName, $workerIndexNames, true)) {
            db()->exec($sql);
        }
    }

    $registrationColumns = db()->query("SHOW COLUMNS FROM election_training_registrations")->fetchAll();
    $registrationColumnNames = array_column($registrationColumns, 'Field');
    if (!in_array('assignment_id', $registrationColumnNames, true)) {
        db()->exec("ALTER TABLE election_training_registrations ADD COLUMN assignment_id INT UNSIGNED NULL AFTER worker_id");
    }
    if (!in_array('registered_by_assignment_id', $registrationColumnNames, true)) {
        db()->exec("ALTER TABLE election_training_registrations ADD COLUMN registered_by_assignment_id INT UNSIGNED NULL AFTER registered_by_user_id");
    }

    $assignmentColumns = db()->query("SHOW COLUMNS FROM election_worker_assignments")->fetchAll();
    $assignmentColumnNames = array_column($assignmentColumns, 'Field');
    if (!in_array('is_extra', $assignmentColumnNames, true)) {
        db()->exec("ALTER TABLE election_worker_assignments ADD COLUMN is_extra TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by_user_id");
    }

    db()->exec(
        "INSERT IGNORE INTO election_worker_assignments (
            worker_id, election_period_id, precinct_id, position_id, recruited_by_assignment_id,
            created_by_user_id, is_extra, is_active, notes, created_at, updated_at
         )
         SELECT id, election_period_id, precinct_id, position_id, NULL,
                created_by_user_id, 0, is_active, notes, created_at, updated_at
         FROM election_workers
         WHERE election_period_id IS NOT NULL
           AND precinct_id IS NOT NULL
           AND position_id IS NOT NULL"
    );

    db()->exec(
        "UPDATE election_worker_assignments
         INNER JOIN (
             SELECT id,
                    ROW_NUMBER() OVER (
                        PARTITION BY election_period_id, precinct_id, position_id
                        ORDER BY is_active DESC, id
                    ) AS slot_order
             FROM election_worker_assignments
         ) ranked_assignments ON ranked_assignments.id = election_worker_assignments.id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
            AND election_positions.is_chief_judge = 0
         SET election_worker_assignments.is_extra = CASE WHEN ranked_assignments.slot_order = 1 THEN 0 ELSE 1 END"
    );

    db()->exec(
        "INSERT INTO election_precinct_roles (
            election_period_id, precinct_id, role_key, assignment_id, created_by_user_id, updated_by_user_id
         )
         SELECT assistant_assignments.election_period_id,
                assistant_assignments.precinct_id,
                'assistant_chief_judge',
                MIN(primary_assignments.id),
                assistant_assignments.created_by_user_id,
                assistant_assignments.created_by_user_id
         FROM election_worker_assignments assistant_assignments
         INNER JOIN election_positions assistant_positions ON assistant_positions.id = assistant_assignments.position_id
            AND assistant_positions.is_assistant_chief_judge = 1
         INNER JOIN election_worker_assignments primary_assignments ON primary_assignments.worker_id = assistant_assignments.worker_id
            AND primary_assignments.election_period_id = assistant_assignments.election_period_id
            AND primary_assignments.precinct_id = assistant_assignments.precinct_id
            AND primary_assignments.is_active = 1
            AND primary_assignments.id <> assistant_assignments.id
         INNER JOIN election_positions primary_positions ON primary_positions.id = primary_assignments.position_id
            AND primary_positions.is_chief_judge = 0
            AND primary_positions.is_assistant_chief_judge = 0
         WHERE assistant_assignments.is_active = 1
         GROUP BY assistant_assignments.election_period_id, assistant_assignments.precinct_id, assistant_assignments.worker_id
         ON DUPLICATE KEY UPDATE
            assignment_id = VALUES(assignment_id),
            updated_by_user_id = VALUES(updated_by_user_id)"
    );

    db()->exec(
        "UPDATE election_worker_assignments
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
            AND election_positions.is_assistant_chief_judge = 1
         SET election_worker_assignments.is_active = 0
         WHERE EXISTS (
             SELECT 1
             FROM election_precinct_roles
             WHERE election_precinct_roles.assignment_id IS NOT NULL
               AND election_precinct_roles.election_period_id = election_worker_assignments.election_period_id
               AND election_precinct_roles.precinct_id = election_worker_assignments.precinct_id
               AND election_precinct_roles.role_key = 'assistant_chief_judge'
         )"
    );

    db()->exec(
        "UPDATE election_training_registrations
         INNER JOIN election_workers ON election_workers.id = election_training_registrations.worker_id
         INNER JOIN election_worker_assignments ON election_worker_assignments.worker_id = election_workers.id
            AND election_worker_assignments.election_period_id = election_workers.election_period_id
            AND election_worker_assignments.precinct_id = election_workers.precinct_id
            AND election_worker_assignments.position_id = election_workers.position_id
         SET election_training_registrations.assignment_id = election_worker_assignments.id
         WHERE election_training_registrations.assignment_id IS NULL"
    );

    db()->exec(
        "INSERT IGNORE INTO election_training_class_positions (class_id, position_id)
         SELECT election_training_classes.id, election_positions.id
         FROM election_training_classes
         CROSS JOIN election_positions
         WHERE election_positions.is_chief_judge = 1
            OR election_positions.is_assistant_chief_judge = 1"
    );

    audit_event('setup', 'election_module', 'schema');
    $ranSetup = true;
}

page_header('Setup Election Module');
?>
<main class="shell">
    <section class="panel">
        <h1>Setup Election Module</h1>
        <p>Create or refresh the Election Readiness tables, department entry, and default worker positions.</p>

        <?php if ($ranSetup): ?>
            <div class="notice success">Election module setup is complete.</div>
        <?php endif; ?>

        <form method="post">
            <button type="submit">Run setup</button>
            <a class="button secondary" href="<?= e(url('departments/election/index.php')) ?>">Open Election Readiness</a>
        </form>
    </section>
</main>
<?php page_footer(); ?>
