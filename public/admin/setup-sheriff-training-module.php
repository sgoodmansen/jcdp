<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

$ranSetup = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statements = [
        "INSERT INTO departments (name, slug, description)
         VALUES ('Sheriff Training', 'sheriff-training', 'Sheriff Office training requests, lodging costs, fiscal budgets, and attendance history.')
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)",

        "CREATE TABLE IF NOT EXISTS sheriff_training_officers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            email VARCHAR(190) NULL,
            rank_title VARCHAR(120) NULL,
            division VARCHAR(120) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sheriff_training_officer_name (last_name, first_name),
            INDEX idx_sheriff_training_officer_active (is_active, last_name, first_name)
        )",

        "CREATE TABLE IF NOT EXISTS sheriff_training_divisions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sheriff_training_division_active_order (is_active, sort_order, name)
        )",

        "CREATE TABLE IF NOT EXISTS sheriff_training_fiscal_years (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fiscal_year INT UNSIGNED NOT NULL UNIQUE,
            label VARCHAR(20) NOT NULL UNIQUE,
            starts_on DATE NOT NULL,
            ends_on DATE NOT NULL,
            training_budget DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            lodging_budget DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sheriff_training_fy_dates (starts_on, ends_on)
        )",

        "CREATE TABLE IF NOT EXISTS sheriff_training_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            officer_id INT UNSIGNED NOT NULL,
            fiscal_year_id INT UNSIGNED NOT NULL,
            created_by_user_id INT UNSIGNED NULL,
            decision_by_user_id INT UNSIGNED NULL,
            class_name VARCHAR(190) NOT NULL,
            provider VARCHAR(160) NULL,
            location VARCHAR(190) NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            estimated_training_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            estimated_lodging_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            actual_training_cost DECIMAL(10,2) NULL,
            actual_lodging_cost DECIMAL(10,2) NULL,
            status ENUM('pending', 'approved', 'denied', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
            decision_comment TEXT NULL,
            decision_at TIMESTAMP NULL,
            status_email_sent_at TIMESTAMP NULL,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sheriff_training_request_status (status, start_date),
            INDEX idx_sheriff_training_request_officer (officer_id, start_date),
            INDEX idx_sheriff_training_request_fy (fiscal_year_id, status),
            CONSTRAINT fk_sheriff_training_requests_officer FOREIGN KEY (officer_id) REFERENCES sheriff_training_officers(id) ON DELETE RESTRICT,
            CONSTRAINT fk_sheriff_training_requests_fy FOREIGN KEY (fiscal_year_id) REFERENCES sheriff_training_fiscal_years(id) ON DELETE RESTRICT,
            CONSTRAINT fk_sheriff_training_requests_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_sheriff_training_requests_decision_by FOREIGN KEY (decision_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS sheriff_training_request_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            changed_by_user_id INT UNSIGNED NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NULL,
            comment TEXT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sheriff_training_history_request (request_id, created_at),
            CONSTRAINT fk_sheriff_training_history_request FOREIGN KEY (request_id) REFERENCES sheriff_training_requests(id) ON DELETE CASCADE,
            CONSTRAINT fk_sheriff_training_history_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
    ];

    foreach ($statements as $sql) {
        db()->exec($sql);
    }

    db()->exec(
        "INSERT IGNORE INTO sheriff_training_divisions (name, sort_order)
         SELECT DISTINCT TRIM(division), 100
         FROM sheriff_training_officers
         WHERE division IS NOT NULL
           AND TRIM(division) <> ''"
    );

    $currentFiscalYear = sheriff_training_fiscal_year_for_date();
    $yearDates = sheriff_training_fiscal_year_dates($currentFiscalYear);
    $statement = db()->prepare(
        'INSERT IGNORE INTO sheriff_training_fiscal_years (fiscal_year, label, starts_on, ends_on)
         VALUES (:fiscal_year, :label, :starts_on, :ends_on)'
    );
    $statement->execute([
        'fiscal_year' => $currentFiscalYear,
        'label' => 'FY ' . $currentFiscalYear,
        'starts_on' => $yearDates['starts_on'],
        'ends_on' => $yearDates['ends_on'],
    ]);

    audit_event('setup', 'sheriff_training_module', 'schema');
    $ranSetup = true;
}

page_header('Setup Sheriff Training Module');
?>
<main class="shell">
    <section class="panel">
        <h1>Setup Sheriff Training Module</h1>
        <p>Create or refresh the Sheriff Training department, officer list, fiscal budgets, training requests, and review history tables.</p>

        <?php if ($ranSetup): ?>
            <div class="notice success">Sheriff Training setup is complete.</div>
        <?php endif; ?>

        <form method="post">
            <button type="submit">Run setup</button>
            <a class="button secondary" href="<?= e(url('departments/sheriff-training/index.php')) ?>">Open Sheriff Training</a>
        </form>
    </section>
</main>
<?php page_footer(); ?>
