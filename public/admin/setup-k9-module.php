<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

$ranSetup = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $statements = [
        "INSERT INTO departments (name, slug, description)
         VALUES ('K-9 Activity & Records', 'k9', 'K-9 team activity, deployments, medical reminders, shots, expenses, and reports.')
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)",

        "CREATE TABLE IF NOT EXISTS k9_handlers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_access_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            officer_number VARCHAR(30) NULL,
            handler_name VARCHAR(120) NOT NULL,
            position_start_date DATE NULL,
            position_end_date DATE NULL,
            reminder_days INT UNSIGNED NOT NULL DEFAULT 30,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_k9_handlers_user (user_id),
            INDEX idx_k9_handlers_active (is_active, handler_name),
            CONSTRAINT fk_k9_handlers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS k9_dogs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_access_id INT UNSIGNED NULL,
            dog_name VARCHAR(120) NOT NULL,
            breed VARCHAR(120) NULL,
            service_start_date DATE NULL,
            service_end_date DATE NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_k9_dogs_active (is_active, dog_name)
        )",

        "CREATE TABLE IF NOT EXISTS k9_teams (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_access_id INT UNSIGNED NULL,
            dog_id INT UNSIGNED NOT NULL,
            handler_id INT UNSIGNED NOT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_k9_teams_handler (handler_id, is_active),
            INDEX idx_k9_teams_dog (dog_id, is_active),
            CONSTRAINT fk_k9_teams_dog FOREIGN KEY (dog_id) REFERENCES k9_dogs(id) ON DELETE RESTRICT,
            CONSTRAINT fk_k9_teams_handler FOREIGN KEY (handler_id) REFERENCES k9_handlers(id) ON DELETE RESTRICT
        )",

        "CREATE TABLE IF NOT EXISTS k9_activity_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        )",

        "CREATE TABLE IF NOT EXISTS k9_training_areas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        )",

        "CREATE TABLE IF NOT EXISTS k9_indications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        )",

        "CREATE TABLE IF NOT EXISTS k9_locations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            address_description VARCHAR(255) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS k9_training_aids (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            category VARCHAR(80) NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS k9_expense_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        )",

        "CREATE TABLE IF NOT EXISTS k9_incident_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        )",

        "CREATE TABLE IF NOT EXISTS k9_assisting_agencies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        )",

        "CREATE TABLE IF NOT EXISTS k9_deployment_outcomes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        )",

        "CREATE TABLE IF NOT EXISTS k9_user_preferences (
            user_id INT UNSIGNED PRIMARY KEY,
            default_summary_period ENUM('week', 'month', 'year') NOT NULL DEFAULT 'year',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_k9_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS k9_activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_access_id INT UNSIGNED NULL,
            team_id INT UNSIGNED NOT NULL,
            dog_id INT UNSIGNED NOT NULL,
            handler_id INT UNSIGNED NOT NULL,
            activity_date DATE NOT NULL,
            activity_type_id INT UNSIGNED NULL,
            location_id INT UNSIGNED NULL,
            training_area_id INT UNSIGNED NULL,
            indication_id INT UNSIGNED NULL,
            training_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            is_post_training TINYINT(1) NOT NULL DEFAULT 0,
            incident_number VARCHAR(80) NULL,
            incident_type_id INT UNSIGNED NULL,
            assisting_agency_id INT UNSIGNED NULL,
            arrest_made TINYINT(1) NOT NULL DEFAULT 0,
            deployment_outcome_id INT UNSIGNED NULL,
            notes TEXT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_k9_activity_date (activity_date),
            INDEX idx_k9_activity_team_date (team_id, activity_date),
            CONSTRAINT fk_k9_activity_team FOREIGN KEY (team_id) REFERENCES k9_teams(id) ON DELETE RESTRICT,
            CONSTRAINT fk_k9_activity_dog FOREIGN KEY (dog_id) REFERENCES k9_dogs(id) ON DELETE RESTRICT,
            CONSTRAINT fk_k9_activity_handler FOREIGN KEY (handler_id) REFERENCES k9_handlers(id) ON DELETE RESTRICT,
            CONSTRAINT fk_k9_activity_type FOREIGN KEY (activity_type_id) REFERENCES k9_activity_types(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_activity_location FOREIGN KEY (location_id) REFERENCES k9_locations(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_activity_training_area FOREIGN KEY (training_area_id) REFERENCES k9_training_areas(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_activity_indication FOREIGN KEY (indication_id) REFERENCES k9_indications(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_activity_incident_type FOREIGN KEY (incident_type_id) REFERENCES k9_incident_types(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_activity_agency FOREIGN KEY (assisting_agency_id) REFERENCES k9_assisting_agencies(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_activity_outcome FOREIGN KEY (deployment_outcome_id) REFERENCES k9_deployment_outcomes(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_activity_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS k9_activity_log_aids (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            activity_log_id BIGINT UNSIGNED NOT NULL,
            training_aid_id INT UNSIGNED NOT NULL,
            amount_grams DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            INDEX idx_k9_activity_aids_log (activity_log_id),
            CONSTRAINT fk_k9_activity_aids_log FOREIGN KEY (activity_log_id) REFERENCES k9_activity_logs(id) ON DELETE CASCADE,
            CONSTRAINT fk_k9_activity_aids_aid FOREIGN KEY (training_aid_id) REFERENCES k9_training_aids(id) ON DELETE RESTRICT
        )",

        "CREATE TABLE IF NOT EXISTS k9_medical_visits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_access_id INT UNSIGNED NULL,
            dog_id INT UNSIGNED NOT NULL,
            visit_date DATE NOT NULL,
            vet_office_name VARCHAR(190) NULL,
            doctor_name VARCHAR(160) NULL,
            reason_for_visit VARCHAR(190) NULL,
            notes TEXT NULL,
            next_appointment_date DATE NULL,
            next_appointment_time TIME NULL,
            next_appointment_scheduled VARCHAR(80) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_k9_medical_dog FOREIGN KEY (dog_id) REFERENCES k9_dogs(id) ON DELETE RESTRICT
        )",

        "CREATE TABLE IF NOT EXISTS k9_medical_shots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_access_id INT UNSIGNED NULL,
            medical_visit_id BIGINT UNSIGNED NULL,
            dog_id INT UNSIGNED NOT NULL,
            shot_description VARCHAR(190) NOT NULL,
            shot_expiration DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_k9_shots_visit FOREIGN KEY (medical_visit_id) REFERENCES k9_medical_visits(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_shots_dog FOREIGN KEY (dog_id) REFERENCES k9_dogs(id) ON DELETE RESTRICT
        )",

        "CREATE TABLE IF NOT EXISTS k9_expenses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legacy_access_id INT UNSIGNED NULL,
            dog_id INT UNSIGNED NULL,
            expense_date DATE NOT NULL,
            expense_category_id INT UNSIGNED NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            vendor VARCHAR(190) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_k9_expenses_dog FOREIGN KEY (dog_id) REFERENCES k9_dogs(id) ON DELETE SET NULL,
            CONSTRAINT fk_k9_expenses_category FOREIGN KEY (expense_category_id) REFERENCES k9_expense_categories(id) ON DELETE SET NULL
        )",
    ];

    foreach ($statements as $sql) {
        db()->exec($sql);
    }

    foreach ([
        'k9_handlers',
        'k9_dogs',
        'k9_teams',
        'k9_activity_logs',
        'k9_medical_visits',
        'k9_medical_shots',
        'k9_expenses',
    ] as $tableName) {
        $statement = db()->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = "legacy_access_id"'
        );
        $statement->execute(['table_name' => $tableName]);
        if ((int) $statement->fetchColumn() === 0) {
            db()->exec("ALTER TABLE $tableName ADD COLUMN legacy_access_id INT UNSIGNED NULL AFTER id");
        }
    }

    $seedGroups = [
        'k9_activity_types' => ['Training', 'Deployed'],
        'k9_training_areas' => ['Article Search', 'Building Search', 'Cadaver Training', 'Field Search', 'Narcotics Detection', 'Obedience', 'Patrol Training', 'Tracking'],
        'k9_indications' => ['K9 Alerted', 'No Alert by K9', 'False Response by K9'],
        'k9_expense_categories' => ['Food', 'Vet / Medical', 'Training', 'Equipment', 'Kennel / Boarding', 'Medication', 'Other'],
        'k9_incident_types' => ['Traffic Stop', 'Agency Assist', 'Search Warrant', 'Building Search', 'Area Search', 'Narcotics Search', 'Other'],
        'k9_assisting_agencies' => ['Jefferson County Sheriff Office', 'Rigby Police Department', 'Idaho State Police', 'Other'],
        'k9_deployment_outcomes' => ['Located narcotics', 'Located person', 'No find', 'Arrest made', 'Citation issued', 'Other'],
    ];

    foreach ($seedGroups as $tableName => $names) {
        $statement = db()->prepare("INSERT IGNORE INTO $tableName (name, sort_order) VALUES (:name, :sort_order)");
        foreach ($names as $index => $name) {
            $statement->execute(['name' => $name, 'sort_order' => ($index + 1) * 10]);
        }
    }

    $aidStatement = db()->prepare('INSERT IGNORE INTO k9_training_aids (name, category, sort_order) VALUES (:name, :category, :sort_order)');
    foreach ([
        ['None', 'Other'],
        ['Toy', 'Toy'],
        ['Ball', 'Toy'],
        ['Treats', 'Treat'],
        ['Bite Suit', 'Bite suit'],
        ['Bite Sleeve', 'Bite suit'],
        ['Bite Pillow', 'Bite suit'],
        ['Methamphetamine', 'Drug'],
        ['Cocaine', 'Drug'],
        ['Marijuana', 'Drug'],
        ['Heroin', 'Drug'],
        ['Scent Logic Methamphetamine', 'Drug'],
        ['Scent Logic Cocaine', 'Drug'],
        ['Scent Logic Marijuana', 'Drug'],
        ['Scent Logic Heroin', 'Drug'],
    ] as $index => $aid) {
        $aidStatement->execute(['name' => $aid[0], 'category' => $aid[1], 'sort_order' => ($index + 1) * 10]);
    }

    audit_event('setup', 'k9_module', 'schema');
    $ranSetup = true;
}

page_header('Setup K-9 Module');
?>
<main class="shell">
    <section class="panel">
        <h1>Setup K-9 Module</h1>
        <p>Create or refresh the K-9 Activity & Records department, teams, activity log, lookup lists, medical, shot, and expense tables.</p>

        <?php if ($ranSetup): ?>
            <div class="notice success">K-9 setup is complete.</div>
        <?php endif; ?>

        <form method="post">
            <button type="submit">Run setup</button>
            <a class="button secondary" href="<?= e(url('departments/k9/index.php')) ?>">Open K-9 Activity & Records</a>
        </form>
    </section>
</main>
<?php page_footer(); ?>
