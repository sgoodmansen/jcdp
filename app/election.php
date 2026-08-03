<?php

declare(strict_types=1);

const ELECTION_DEPARTMENT_SLUG = 'election';
const ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE = 'assistant_chief_judge';
const ELECTION_WORKER_STATUS_ACTIVE = 'active';
const ELECTION_WORKER_STATUS_UNAVAILABLE = 'unavailable';
const ELECTION_WORKER_STATUS_INACTIVE = 'inactive';
const ELECTION_DEFAULT_ACCESS_EMAIL_SUBJECT = 'Election training access for [Election Name]';
const ELECTION_DEFAULT_ACCESS_EMAIL_BODY = "Hello [Worker Name],\n\nYou have been added as an election worker for [Election Name].\n\nUse this access link to review your information and sign up for an available training class:\n\n[Access Link]\n\nThis link is intended for you only. It remains active through the election period unless a supervisor sends a replacement link.\n\nThank you.";

function election_worker_position_flags(?int $positionId): array
{
    if (!$positionId) {
        return ['is_chief' => false, 'is_assistant_chief' => false, 'has_chief_permissions' => false];
    }

    $statement = db()->prepare('SELECT is_chief_judge, is_assistant_chief_judge FROM election_positions WHERE id = :id');
    $statement->execute(['id' => $positionId]);
    $position = $statement->fetch();

    $isChief = $position && (int) $position['is_chief_judge'] === 1;
    $isAssistant = $position && (int) $position['is_assistant_chief_judge'] === 1;

    return [
        'is_chief' => $isChief,
        'is_assistant_chief' => $isAssistant,
        'has_chief_permissions' => $isChief || $isAssistant,
    ];
}

function current_election_worker(): ?array
{
    if (empty($_SESSION['election_worker_id'])) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT election_workers.*
         FROM election_workers
         WHERE election_workers.id = :id
           AND election_workers.is_active = 1
         LIMIT 1'
    );
    $statement->execute(['id' => $_SESSION['election_worker_id']]);
    $worker = $statement->fetch();

    if (!$worker) {
        unset($_SESSION['election_worker_id']);
        return null;
    }

    return $worker;
}

function election_assignments_for_worker(int $workerId, bool $activeOnly = true): array
{
    if (!election_assignments_table_exists()) {
        return [];
    }

    $roleSelectSql = election_precinct_roles_table_exists()
        ? ', CASE WHEN election_precinct_roles.assignment_id IS NULL THEN 0 ELSE 1 END AS is_assistant_chief_judge_extra'
        : ', 0 AS is_assistant_chief_judge_extra';
    $roleJoinSql = election_precinct_roles_table_exists()
        ? ' LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
                AND election_precinct_roles.role_key = "' . ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE . '"'
        : '';

    $sql = 'SELECT election_worker_assignments.*,
                   election_workers.first_name,
                   election_workers.last_name,
                   election_workers.email,
                   election_workers.phone,
                   election_workers.mailing_address,
                   election_workers.city,
                   election_workers.state,
                   election_workers.zip_code,
                   election_workers.wants_email_reminders,
                   election_workers.wants_text_reminders,
                   election_positions.name AS position_name,
                   election_positions.is_chief_judge,
                   election_positions.is_assistant_chief_judge,
                   election_precincts.name AS precinct_name,
                   election_periods.name AS election_name,
                   election_periods.ends_on AS election_ends_on,
                   election_periods.is_active AS election_is_active' . $roleSelectSql . '
            FROM election_worker_assignments
            INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
            INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
            INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
            INNER JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id' . $roleJoinSql . '
            WHERE election_worker_assignments.worker_id = :worker_id';

    if ($activeOnly) {
        $sql .= ' AND election_worker_assignments.is_active = 1
                  AND election_workers.is_active = 1
                  AND election_periods.is_active = 1
                  AND election_periods.ends_on >= CURDATE()';
    }

    $sql .= ' ORDER BY election_periods.starts_on DESC, election_precincts.name, election_positions.sort_order';

    $statement = db()->prepare($sql);
    $statement->execute(['worker_id' => $workerId]);

    return $statement->fetchAll();
}

function election_assignments_table_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $statement = db()->prepare('SHOW TABLES LIKE :table_name');
        $statement->execute(['table_name' => 'election_worker_assignments']);
        $exists = (bool) $statement->fetchColumn();
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function election_require_assignment_setup(): void
{
    if (election_assignments_table_exists()
        && election_assignment_extra_column_exists()
        && election_precinct_roles_table_exists()
        && election_worker_matching_columns_exist()
        && election_worker_status_columns_exist()) {
        return;
    }

    http_response_code(503);
    page_header('Election setup required');
    ?>
    <main class="shell">
        <section class="panel">
            <h1>Election setup required</h1>
            <p>The Election module database needs to be updated before this page can be used.</p>
            <?php if (is_system_admin()): ?>
                <div class="actions">
                    <a class="button" href="<?= e(url('admin/setup-election-module.php')) ?>">Run Election setup</a>
                </div>
            <?php else: ?>
                <p>Ask an IT system admin to run the Election module setup page.</p>
            <?php endif; ?>
        </section>
    </main>
    <?php
    page_footer();
    exit;
}

function election_assignment_extra_column_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $statement = db()->query("SHOW COLUMNS FROM election_worker_assignments WHERE Field = 'is_extra'");
        $exists = (bool) $statement->fetchColumn();
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function election_precinct_roles_table_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $statement = db()->prepare('SHOW TABLES LIKE :table_name');
        $statement->execute(['table_name' => 'election_precinct_roles']);
        $exists = (bool) $statement->fetchColumn();
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function election_settings_table_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $statement = db()->prepare('SHOW TABLES LIKE :table_name');
        $statement->execute(['table_name' => 'election_settings']);
        $exists = (bool) $statement->fetchColumn();
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function election_setting(string $key, string $default = ''): string
{
    if (!election_settings_table_exists()) {
        return $default;
    }

    $statement = db()->prepare('SELECT setting_value FROM election_settings WHERE setting_key = :setting_key');
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function election_save_setting(string $key, string $value): void
{
    $statement = db()->prepare(
        'INSERT INTO election_settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

function election_worker_matching_columns_exist(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $columns = db()->query("SHOW COLUMNS FROM election_workers WHERE Field IN ('email_normalized', 'phone_digits', 'name_key')")->fetchAll();
        $exists = count($columns) === 3;
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function election_worker_status_columns_exist(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $columns = db()->query("SHOW COLUMNS FROM election_workers WHERE Field IN ('availability_status', 'unavailable_reason')")->fetchAll();
        $exists = count($columns) === 2;
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function election_worker_status_options(): array
{
    return [
        ELECTION_WORKER_STATUS_ACTIVE => 'Active',
        ELECTION_WORKER_STATUS_UNAVAILABLE => 'Unavailable',
        ELECTION_WORKER_STATUS_INACTIVE => 'Inactive',
    ];
}

function election_worker_status(array $worker): string
{
    $status = (string) ($worker['availability_status'] ?? '');
    if (array_key_exists($status, election_worker_status_options())) {
        return $status;
    }

    return (int) ($worker['is_active'] ?? 1) === 1
        ? ELECTION_WORKER_STATUS_ACTIVE
        : ELECTION_WORKER_STATUS_INACTIVE;
}

function election_worker_status_label(array $worker): string
{
    return election_worker_status_options()[election_worker_status($worker)] ?? 'Active';
}

function election_worker_status_badge_class(array $worker): string
{
    return match (election_worker_status($worker)) {
        ELECTION_WORKER_STATUS_ACTIVE => 'badge-success',
        ELECTION_WORKER_STATUS_UNAVAILABLE => 'badge-warning',
        default => 'badge-muted',
    };
}

function current_election_assignment(): ?array
{
    $worker = current_election_worker();
    if (!$worker) {
        unset($_SESSION['election_assignment_id']);
        return null;
    }

    $assignments = election_assignments_for_worker((int) $worker['id']);

    if (!$assignments) {
        unset($_SESSION['election_assignment_id']);
        return null;
    }

    $selectedAssignmentId = (int) ($_SESSION['election_assignment_id'] ?? 0);
    foreach ($assignments as $assignment) {
        if ((int) $assignment['id'] === $selectedAssignmentId) {
            return $assignment;
        }
    }

    if (count($assignments) === 1) {
        $_SESSION['election_assignment_id'] = $assignments[0]['id'];
        return $assignments[0];
    }

    return null;
}

function is_election_worker_logged_in(): bool
{
    return current_election_worker() !== null;
}

function is_election_portal_user(): bool
{
    return is_logged_in() && can_access_department(ELECTION_DEPARTMENT_SLUG);
}

function can_manage_election_module(): bool
{
    return is_election_portal_user();
}

function current_election_actor_can_manage_workers(): bool
{
    if (can_manage_election_module()) {
        return true;
    }

    $assignment = current_election_assignment();
    return $assignment !== null && election_assignment_has_chief_permissions($assignment);
}

function require_election_access(): void
{
    if (is_election_portal_user() || is_election_worker_logged_in()) {
        return;
    }

    redirect_to('login.php');
}

function require_election_manager(): void
{
    require_login();

    if (!can_manage_election_module()) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to manage this election module.</p></section></main>';
        page_footer();
        exit;
    }
}

function require_election_worker_manager(): void
{
    require_election_access();

    if (!current_election_actor_can_manage_workers()) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to manage election workers.</p></section></main>';
        page_footer();
        exit;
    }
}

function election_navigation(string $activeKey = ''): void
{
    $isManager = can_manage_election_module();
    $canManageWorkers = current_election_actor_can_manage_workers();
    $worker = current_election_worker();

    $groups = [];
    if ($canManageWorkers) {
        $groups['dashboard'] = [
            'label' => 'Dashboard',
            'items' => [
                ['key' => 'needs-attention', 'label' => 'Needs Attention', 'href' => url('departments/election/needs-attention.php')],
                ['key' => 'staffing-progress', 'label' => 'Staffing Progress', 'href' => url('departments/election/staffing-progress.php')],
            ],
        ];
        $groups['staffing'] = [
            'label' => 'Staffing',
            'items' => [
                ['key' => 'staffing', 'label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php')],
                ['key' => 'staffing-sheet', 'label' => 'Staffing Sheet', 'href' => url('departments/election/staffing-sheet.php')],
                ['key' => 'reuse-workers', 'label' => 'Reuse Past Workers', 'href' => url('departments/election/reuse-workers.php')],
            ],
        ];
        $groups['workers'] = [
            'label' => 'Workers',
            'items' => [
                ['key' => 'workers', 'label' => 'Worker List', 'href' => url('departments/election/workers.php')],
            ],
        ];
    }

    if ($isManager) {
        $groups['workers']['items'][] = ['key' => 'import-workers', 'label' => 'Import CSV', 'href' => url('departments/election/import-workers.php')];
        $groups['workers']['items'][] = ['key' => 'merge-workers', 'label' => 'Merge Contacts', 'href' => url('departments/election/merge-workers.php')];
        $groups['workers']['items'][] = ['key' => 'bulk-email', 'label' => 'Bulk Email', 'href' => url('departments/election/bulk-email.php')];
    }

    $groups['training'] = [
        'label' => 'Training',
        'items' => [
            ['key' => 'classes', 'label' => 'Training Classes', 'href' => url('departments/election/classes.php')],
        ],
    ];

    if ($isManager) {
        $groups['training']['items'][] = ['key' => 'class-edit', 'label' => 'New Class', 'href' => url('departments/election/class-edit.php')];
        $groups['training']['items'][] = ['key' => 'email-template', 'label' => 'Email Template', 'href' => url('departments/election/email-template.php')];
        $groups['admin'] = [
            'label' => 'Admin',
            'items' => [
                ['key' => 'setup', 'label' => 'Setup', 'href' => url('departments/election/setup.php')],
            ],
        ];
    }

    if ($worker) {
        $groups['account'] = [
            'label' => 'My Account',
            'items' => [
                ['key' => 'my-information', 'label' => 'My Information', 'href' => url('departments/election/worker-edit.php?id=' . (int) $worker['id'])],
                ['key' => 'sign-out', 'label' => 'Sign Out', 'href' => url('departments/election/sign-out.php')],
            ],
        ];
    }

    $breadcrumb = [
        ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
    ];
    if ($activeKey !== 'home') {
        foreach ($groups as $groupKey => $group) {
            if ($activeKey === $groupKey) {
                $breadcrumb[] = ['label' => $group['label'], 'href' => null];
                break;
            }

            foreach ($group['items'] as $item) {
                if ($item['key'] === $activeKey) {
                    $breadcrumb[] = ['label' => $group['label'], 'href' => null];
                    $breadcrumb[] = ['label' => $item['label'], 'href' => null];
                    break 2;
                }
            }
        }
    }

    ?>
    <div class="election-nav-block">
        <nav class="election-nav" aria-label="Election navigation">
            <a class="button<?= $activeKey === 'home' ? '' : ' secondary' ?>" href="<?= e(url('departments/election/index.php')) ?>">Election Home</a>
            <?php foreach ($groups as $groupKey => $group): ?>
                <?php $isActiveGroup = $activeKey === $groupKey || (bool) array_filter($group['items'], fn($item) => $item['key'] === $activeKey); ?>
                <details class="election-nav-menu">
                    <summary class="<?= $isActiveGroup ? 'active' : '' ?>"><?= e($group['label']) ?></summary>
                    <div class="election-nav-list">
                        <?php foreach ($group['items'] as $item): ?>
                            <a class="<?= $activeKey === $item['key'] ? 'active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>
        <div class="election-breadcrumb" aria-label="Election breadcrumb">
            <?php foreach ($breadcrumb as $index => $crumb): ?>
                <?php if ($index > 0): ?>
                    <span class="election-breadcrumb-separator">/</span>
                <?php endif; ?>
                <?php if (!empty($crumb['href']) && $index < count($breadcrumb) - 1): ?>
                    <a href="<?= e($crumb['href']) ?>"><?= e($crumb['label']) ?></a>
                <?php else: ?>
                    <span><?= e($crumb['label']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function election_person_name(array $person): string
{
    return trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
}

function election_assignment_has_extra_role(int $assignmentId, string $roleKey): bool
{
    if ($assignmentId <= 0 || !election_precinct_roles_table_exists()) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT 1
         FROM election_precinct_roles
         WHERE assignment_id = :assignment_id
           AND role_key = :role_key
         LIMIT 1'
    );
    $statement->execute([
        'assignment_id' => $assignmentId,
        'role_key' => $roleKey,
    ]);

    return (bool) $statement->fetchColumn();
}

function election_assignment_has_chief_permissions(array $assignment): bool
{
    if ((int) ($assignment['is_chief_judge'] ?? 0) === 1 || (int) ($assignment['is_assistant_chief_judge'] ?? 0) === 1) {
        return true;
    }

    if ((int) ($assignment['is_assistant_chief_judge_extra'] ?? 0) === 1) {
        return true;
    }

    return election_assignment_has_extra_role((int) ($assignment['id'] ?? 0), ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE);
}

function election_assistant_chief_position_ids(): array
{
    $statement = db()->query('SELECT id FROM election_positions WHERE is_assistant_chief_judge = 1');

    return array_map('intval', array_column($statement->fetchAll(), 'id'));
}

function election_assignment_training_position_ids(array $assignment): array
{
    $positionIds = [(int) ($assignment['position_id'] ?? 0)];

    if (election_assignment_has_extra_role((int) ($assignment['id'] ?? 0), ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE)
        || (int) ($assignment['is_assistant_chief_judge_extra'] ?? 0) === 1) {
        $positionIds = array_merge($positionIds, election_assistant_chief_position_ids());
    }

    return array_values(array_unique(array_filter($positionIds)));
}

function election_normalized_email(?string $value): ?string
{
    $value = strtolower(trim((string) $value));

    return $value === '' ? null : $value;
}

function election_phone_digits(?string $value): ?string
{
    $value = preg_replace('/\D+/', '', (string) $value);

    return $value === '' ? null : $value;
}

function election_worker_name_key(?string $firstName, ?string $lastName): ?string
{
    $firstName = strtolower(preg_replace('/\s+/', '', trim((string) $firstName)));
    $lastName = strtolower(preg_replace('/\s+/', '', trim((string) $lastName)));
    $key = $firstName . '|' . $lastName;

    return $key === '|' ? null : $key;
}

function election_find_possible_worker_matches(array $workerData, int $excludeWorkerId = 0): array
{
    $emailKey = election_normalized_email($workerData['email'] ?? '');
    $phoneKey = election_phone_digits($workerData['phone'] ?? '');
    $nameKey = election_worker_name_key($workerData['first_name'] ?? '', $workerData['last_name'] ?? '');
    $zipCode = trim((string) ($workerData['zip_code'] ?? ''));

    if ($emailKey === null && $phoneKey === null && $nameKey === null) {
        return [];
    }

    $conditions = [];
    $params = [];

    if ($emailKey !== null) {
        $conditions[] = 'election_workers.email_normalized = :email_normalized';
        $params['email_normalized'] = $emailKey;
    }

    if ($phoneKey !== null) {
        $conditions[] = 'election_workers.phone_digits = :phone_digits';
        $params['phone_digits'] = $phoneKey;
    }

    if ($nameKey !== null && $zipCode !== '') {
        $conditions[] = '(election_workers.name_key = :name_key_zip AND election_workers.zip_code = :zip_code)';
        $params['name_key_zip'] = $nameKey;
        $params['zip_code'] = $zipCode;
    } elseif ($nameKey !== null) {
        $conditions[] = 'election_workers.name_key = :name_key';
        $params['name_key'] = $nameKey;
    }

    $sql = 'SELECT election_workers.*,
                   MAX(election_periods.starts_on) AS latest_election_starts_on,
                   GROUP_CONCAT(
                       DISTINCT CONCAT(election_periods.name, " - ", election_precincts.name, ", ", election_positions.name)
                       ORDER BY election_periods.starts_on DESC SEPARATOR "\n"
                   ) AS assignment_summary
            FROM election_workers
            LEFT JOIN election_worker_assignments ON election_worker_assignments.worker_id = election_workers.id
            LEFT JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
            LEFT JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
            LEFT JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
            WHERE (' . implode(' OR ', $conditions) . ')';

    if ($excludeWorkerId > 0) {
        $sql .= ' AND election_workers.id <> :exclude_worker_id';
        $params['exclude_worker_id'] = $excludeWorkerId;
    }

    $sql .= ' GROUP BY election_workers.id
              ORDER BY
                CASE
                    WHEN election_workers.email_normalized = :sort_email THEN 1
                    WHEN election_workers.phone_digits = :sort_phone THEN 2
                    ELSE 3
                END,
                latest_election_starts_on DESC,
                election_workers.last_name,
                election_workers.first_name
              LIMIT 10';
    $params['sort_email'] = $emailKey ?? '';
    $params['sort_phone'] = $phoneKey ?? '';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function election_precinct_location(array $precinct): string
{
    $cityLine = trim(($precinct['city'] ?? '') . ', ' . ($precinct['state'] ?? '') . ' ' . ($precinct['zip_code'] ?? ''));
    $cityLine = trim($cityLine, ', ');
    $parts = array_filter([
        $precinct['location_name'] ?? '',
        $precinct['street_address'] ?? '',
        $cityLine,
    ]);

    return implode("\n", $parts);
}

function election_active_periods(): array
{
    return db()->query('SELECT * FROM election_periods WHERE is_active = 1 ORDER BY starts_on DESC, name')->fetchAll();
}

function election_positions(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM election_positions';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, name';

    return db()->query($sql)->fetchAll();
}

function election_precincts(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM election_precincts';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY name';

    return db()->query($sql)->fetchAll();
}

function election_class_allowed_position_ids(int $classId): array
{
    $statement = db()->prepare('SELECT position_id FROM election_training_class_positions WHERE class_id = :class_id');
    $statement->execute(['class_id' => $classId]);

    return array_map('intval', array_column($statement->fetchAll(), 'position_id'));
}

function election_generate_worker_token(int $workerId): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $statement = db()->prepare(
        'UPDATE election_workers
         SET access_token_hash = :token_hash,
             access_token_created_at = NOW()
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $workerId,
        'token_hash' => $tokenHash,
    ]);

    return $token;
}

function election_worker_access_url(int $workerId, string $token): string
{
    return absolute_url('election-access.php?worker=' . $workerId . '&token=' . urlencode($token));
}

function election_send_worker_welcome_email(array $worker, string $accessUrl): bool
{
    global $config;

    $email = trim((string) ($worker['email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $from = $config['election']['from_email']
        ?? $config['password_reset']['from_email']
        ?? 'no-reply@jeffersoncounty.local';
    $name = election_person_name($worker) ?: 'Election Worker';
    $replacements = [
        '[Worker Name]' => $name,
        '[Election Name]' => $worker['election_name'] ?? 'Election Training',
        '[Precinct Name]' => $worker['precinct_name'] ?? '',
        '[Position Name]' => $worker['position_name'] ?? '',
        '[Access Link]' => $accessUrl,
    ];
    $subject = strtr(election_setting('access_email_subject', ELECTION_DEFAULT_ACCESS_EMAIL_SUBJECT), $replacements);
    $message = strtr(election_setting('access_email_body', ELECTION_DEFAULT_ACCESS_EMAIL_BODY), $replacements);
    $headers = 'From: ' . $from;

    return @mail($email, $subject, $message, $headers);
}

function election_worker_for_email(int $workerId, ?int $assignmentId = null): ?array
{
    $sql = 'SELECT election_workers.*,
                   election_periods.name AS election_name,
                   election_positions.name AS position_name,
                   election_precincts.name AS precinct_name
            FROM election_workers
            INNER JOIN election_worker_assignments ON election_worker_assignments.worker_id = election_workers.id
            INNER JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
            INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
            INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
            WHERE election_workers.id = :id';
    $params = ['id' => $workerId];

    if ($assignmentId !== null && $assignmentId > 0) {
        $sql .= ' AND election_worker_assignments.id = :assignment_id';
        $params['assignment_id'] = $assignmentId;
    }

    $sql .= ' ORDER BY election_worker_assignments.is_active DESC,
                       election_periods.is_active DESC,
                       election_periods.starts_on DESC,
                       election_worker_assignments.id DESC
              LIMIT 1';

    $statement = db()->prepare($sql);
    $statement->execute($params);
    $worker = $statement->fetch();

    return $worker ?: null;
}

function election_find_worker_by_token(int $workerId, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }

    if (!election_assignments_table_exists()) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT election_workers.*
         FROM election_workers
         WHERE election_workers.id = :id
           AND election_workers.access_token_hash = :token_hash
           AND election_workers.is_active = 1
           AND EXISTS (
               SELECT 1
               FROM election_worker_assignments
               INNER JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
               WHERE election_worker_assignments.worker_id = election_workers.id
                 AND election_worker_assignments.is_active = 1
                 AND election_periods.is_active = 1
                 AND election_periods.ends_on >= CURDATE()
           )
         LIMIT 1'
    );
    $statement->execute([
        'id' => $workerId,
        'token_hash' => hash('sha256', $token),
    ]);
    $worker = $statement->fetch();

    return $worker ?: null;
}

function election_sync_class_positions(int $classId, array $positionIds): void
{
    $positionIds = array_values(array_unique(array_filter(array_map('intval', $positionIds))));

    $statement = db()->prepare('DELETE FROM election_training_class_positions WHERE class_id = :class_id');
    $statement->execute(['class_id' => $classId]);

    if (!$positionIds) {
        return;
    }

    $statement = db()->prepare(
        'INSERT INTO election_training_class_positions (class_id, position_id)
         VALUES (:class_id, :position_id)'
    );

    foreach ($positionIds as $positionId) {
        $statement->execute([
            'class_id' => $classId,
            'position_id' => $positionId,
        ]);
    }
}

function election_worker_scope_sql(string $workerAlias = 'election_workers'): array
{
    if (!election_assignments_table_exists()) {
        return [' AND 1 = 0', []];
    }

    if (can_manage_election_module()) {
        return ['', []];
    }

    $assignment = current_election_assignment();
    if (!$assignment) {
        return [' AND 1 = 0', []];
    }

    if (election_assignment_has_chief_permissions($assignment)) {
        return [" AND {$workerAlias}.precinct_id = :scope_precinct_id AND {$workerAlias}.election_period_id = :scope_election_period_id", [
            'scope_precinct_id' => (int) $assignment['precinct_id'],
            'scope_election_period_id' => (int) $assignment['election_period_id'],
        ]];
    }

    return [" AND {$workerAlias}.worker_id = :scope_worker_id", ['scope_worker_id' => (int) $assignment['worker_id']]];
}

function election_close_period(int $periodId): void
{
    db()->beginTransaction();

    if (election_assignments_table_exists()) {
        $statement = db()->prepare('UPDATE election_worker_assignments SET is_active = 0 WHERE election_period_id = :period_id');
        $statement->execute(['period_id' => $periodId]);
    }

    $statement = db()->prepare('UPDATE election_periods SET is_active = 0 WHERE id = :id');
    $statement->execute(['id' => $periodId]);

    db()->commit();
}
