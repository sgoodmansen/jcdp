<?php

declare(strict_types=1);

const K9_DEPARTMENT_SLUG = 'k9';

function k9_schema_ready(): bool
{
    foreach (['k9_activity_logs', 'k9_teams', 'k9_locations', 'k9_training_aids', 'k9_medical_visits', 'k9_expenses'] as $tableName) {
        $statement = db()->query("SHOW TABLES LIKE " . db()->quote($tableName));
        if (!$statement->fetchColumn()) {
            return false;
        }
    }

    foreach (['k9_activity_logs', 'k9_medical_visits', 'k9_expenses'] as $tableName) {
        foreach (['voided_at', 'voided_by_user_id', 'void_reason'] as $columnName) {
            $statement = db()->prepare(
                'SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name
                   AND COLUMN_NAME = :column_name'
            );
            $statement->execute([
                'table_name' => $tableName,
                'column_name' => $columnName,
            ]);
            if ((int) $statement->fetchColumn() === 0) {
                return false;
            }
        }
    }

    return true;
}

function k9_require_ready(): void
{
    if (k9_schema_ready()) {
        return;
    }

    page_header('K-9 Setup Needed');
    ?>
    <main class="shell">
        <section class="panel">
            <h1>K-9 Setup Needed</h1>
            <p>The K-9 Activity & Records module needs to be installed before this page can be used.</p>
            <?php if (is_system_admin()): ?>
                <a class="button" href="<?= e(url('admin/setup-k9-module.php')) ?>">Run K-9 setup</a>
            <?php else: ?>
                <p>Ask an IT system admin to run the K-9 setup page.</p>
            <?php endif; ?>
        </section>
    </main>
    <?php
    page_footer();
    exit;
}

function require_k9_access(): void
{
    require_department_access(K9_DEPARTMENT_SLUG);
    k9_require_ready();
}

function require_k9_manager(): void
{
    require_department_manager(K9_DEPARTMENT_SLUG);
    k9_require_ready();
}

function current_k9_handler(): ?array
{
    $user = current_user();
    if (!$user) {
        return null;
    }

    $statement = db()->prepare('SELECT * FROM k9_handlers WHERE user_id = :user_id AND is_active = 1');
    $statement->execute(['user_id' => $user['id']]);

    return $statement->fetch() ?: null;
}

function k9_user_can_manage(): bool
{
    return can_manage_department(K9_DEPARTMENT_SLUG);
}

function k9_user_can_manage_handler_lists(): bool
{
    return k9_user_can_manage() || current_k9_handler() !== null;
}

function k9_navigation(string $activeKey): void
{
    $isManager = k9_user_can_manage();
    $canManageHandlerLists = k9_user_can_manage_handler_lists();
    $activityKeys = ['activity', 'activity-edit', 'deployment-edit', 'medical-edit', 'expense-edit'];
    $activityItems = [
        ['key' => 'activity', 'label' => 'View Activity Log', 'href' => url('departments/k9/activity.php')],
        ['key' => 'activity-edit', 'label' => 'Add Training', 'href' => url('departments/k9/activity-edit.php')],
        ['key' => 'deployment-edit', 'label' => 'Add Deployment', 'href' => url('departments/k9/deployment-edit.php')],
        ['key' => 'medical-edit', 'label' => 'Add Medical', 'href' => url('departments/k9/medical-edit.php')],
        ['key' => 'expense-edit', 'label' => 'Add Expense', 'href' => url('departments/k9/expense-edit.php')],
    ];

    ?>
    <div class="department-nav election-nav">
        <a class="button<?= $activeKey === 'home' ? '' : ' secondary' ?>" href="<?= e(url('departments/k9/index.php')) ?>">K-9 Home</a>

        <details class="election-nav-menu">
            <summary class="<?= in_array($activeKey, $activityKeys, true) ? 'active' : '' ?>">Activity Log</summary>
            <div class="election-nav-list">
                <?php foreach ($activityItems as $item): ?>
                    <a class="<?= $activeKey === $item['key'] ? 'active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
                <?php endforeach; ?>
            </div>
        </details>

        <a class="button<?= $activeKey === 'reports' ? '' : ' secondary' ?>" href="<?= e(url('departments/k9/reports.php')) ?>">Reports</a>

        <?php if ($isManager): ?>
            <a class="button<?= $activeKey === 'teams' ? '' : ' secondary' ?>" href="<?= e(url('departments/k9/teams.php')) ?>">Teams</a>
        <?php endif; ?>
        <?php if ($canManageHandlerLists): ?>
            <a class="button<?= $activeKey === 'setup' ? '' : ' secondary' ?>" href="<?= e(url('departments/k9/setup.php')) ?>">Setup</a>
        <?php endif; ?>
    </div>
    <?php
}

function k9_lookup_options(string $tableName, string $labelColumn = 'name', bool $activeOnly = true): array
{
    $allowed = [
        'k9_activity_types',
        'k9_training_areas',
        'k9_indications',
        'k9_locations',
        'k9_training_aids',
        'k9_vet_offices',
        'k9_vet_doctors',
        'k9_expense_categories',
        'k9_incident_types',
        'k9_assisting_agencies',
        'k9_deployment_outcomes',
    ];

    if (!in_array($tableName, $allowed, true)) {
        return [];
    }

    $sql = "SELECT * FROM $tableName";
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= " ORDER BY sort_order, $labelColumn";

    return db()->query($sql)->fetchAll();
}

function k9_lookup_id_by_name(string $tableName, string $name): ?int
{
    $allowed = [
        'k9_activity_types',
        'k9_training_areas',
        'k9_indications',
        'k9_locations',
        'k9_training_aids',
        'k9_expense_categories',
        'k9_incident_types',
        'k9_assisting_agencies',
        'k9_deployment_outcomes',
    ];

    if (!in_array($tableName, $allowed, true)) {
        return null;
    }

    $statement = db()->prepare("SELECT id FROM $tableName WHERE LOWER(name) = LOWER(:name) LIMIT 1");
    $statement->execute(['name' => $name]);
    $id = $statement->fetchColumn();

    return $id ? (int) $id : null;
}

function k9_visible_team_sql(string $teamAlias = 'k9_teams'): array
{
    if (k9_user_can_manage()) {
        return ['', []];
    }

    $handler = current_k9_handler();
    if (!$handler) {
        return [' AND 1 = 0', []];
    }

    return [" AND $teamAlias.handler_id = :current_handler_id", ['current_handler_id' => $handler['id']]];
}

function k9_not_voided_sql(string $alias): string
{
    return " AND $alias.voided_at IS NULL";
}

function k9_decimal(?string $value): float
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }

    return round((float) str_replace([','], '', $value), 2);
}

function k9_is_valid_date(?string $value): bool
{
    $value = trim((string) $value);
    if ($value === '') {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function k9_date_is_future(?string $value): bool
{
    if (!k9_is_valid_date($value)) {
        return false;
    }

    return $value > date('Y-m-d');
}

function k9_flash_validation_errors(array $errors, string $redirectPath): void
{
    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect_to($redirectPath);
    }
}

function k9_money(float|int|string|null $amount): string
{
    return '$' . number_format((float) ($amount ?? 0), 2);
}
