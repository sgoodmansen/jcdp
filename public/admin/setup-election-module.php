<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

$ranSetup = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statements = [
        "INSERT INTO departments (name, slug, description)
         VALUES ('Election Training', 'election', 'Election worker training, precinct assignment, and attendance module.')
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

        "CREATE TABLE IF NOT EXISTS election_workers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            election_period_id INT UNSIGNED NOT NULL,
            precinct_id INT UNSIGNED NOT NULL,
            position_id INT UNSIGNED NOT NULL,
            recruited_by_worker_id INT UNSIGNED NULL,
            created_by_user_id INT UNSIGNED NULL,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(40) NULL,
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
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_election_worker_name (last_name, first_name),
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

        "CREATE TABLE IF NOT EXISTS election_training_class_positions (
            class_id INT UNSIGNED NOT NULL,
            position_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (class_id, position_id),
            CONSTRAINT fk_election_class_positions_class FOREIGN KEY (class_id) REFERENCES election_training_classes(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_class_positions_position FOREIGN KEY (position_id) REFERENCES election_positions(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS election_training_registrations (
            class_id INT UNSIGNED NOT NULL,
            worker_id INT UNSIGNED NOT NULL,
            registered_by_user_id INT UNSIGNED NULL,
            registered_by_worker_id INT UNSIGNED NULL,
            attended TINYINT(1) NOT NULL DEFAULT 0,
            attended_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (class_id, worker_id),
            CONSTRAINT fk_election_registrations_class FOREIGN KEY (class_id) REFERENCES election_training_classes(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_registrations_worker FOREIGN KEY (worker_id) REFERENCES election_workers(id) ON DELETE CASCADE,
            CONSTRAINT fk_election_registrations_user FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_election_registrations_worker_by FOREIGN KEY (registered_by_worker_id) REFERENCES election_workers(id) ON DELETE SET NULL
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

    audit_event('setup', 'election_module', 'schema');
    $ranSetup = true;
}

page_header('Setup Election Module');
?>
<main class="shell">
    <section class="panel">
        <h1>Setup Election Module</h1>
        <p>Create or refresh the Election Training tables, department entry, and default worker positions.</p>

        <?php if ($ranSetup): ?>
            <div class="notice success">Election module setup is complete.</div>
        <?php endif; ?>

        <form method="post">
            <button type="submit">Run setup</button>
            <a class="button secondary" href="<?= e(url('departments/election/index.php')) ?>">Open Election Training</a>
        </form>
    </section>
</main>
<?php page_footer(); ?>
