<?php

declare(strict_types=1);

const ELECTION_DEPARTMENT_SLUG = 'election';

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
        'SELECT election_workers.*,
                election_positions.name AS position_name,
                election_positions.is_chief_judge,
                election_positions.is_assistant_chief_judge,
                election_precincts.name AS precinct_name,
                election_precincts.location_name AS precinct_location_name,
                election_precincts.street_address AS precinct_street_address,
                election_precincts.city AS precinct_city,
                election_precincts.state AS precinct_state,
                election_precincts.zip_code AS precinct_zip_code,
                election_periods.name AS election_name,
                election_periods.ends_on AS election_ends_on
         FROM election_workers
         INNER JOIN election_positions ON election_positions.id = election_workers.position_id
         INNER JOIN election_precincts ON election_precincts.id = election_workers.precinct_id
         INNER JOIN election_periods ON election_periods.id = election_workers.election_period_id
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

    $worker = current_election_worker();
    return $worker !== null
        && ((int) $worker['is_chief_judge'] === 1 || (int) $worker['is_assistant_chief_judge'] === 1);
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

function election_person_name(array $person): string
{
    return trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
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

function election_find_worker_by_token(int $workerId, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT election_workers.*
         FROM election_workers
         INNER JOIN election_periods ON election_periods.id = election_workers.election_period_id
         WHERE election_workers.id = :id
           AND election_workers.access_token_hash = :token_hash
           AND election_workers.is_active = 1
           AND election_periods.is_active = 1
           AND election_periods.ends_on >= CURDATE()
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
    if (can_manage_election_module()) {
        return ['', []];
    }

    $worker = current_election_worker();
    if (!$worker) {
        return [' AND 1 = 0', []];
    }

    if ((int) $worker['is_chief_judge'] === 1 || (int) $worker['is_assistant_chief_judge'] === 1) {
        return [" AND {$workerAlias}.precinct_id = :scope_precinct_id AND {$workerAlias}.election_period_id = :scope_election_period_id", [
            'scope_precinct_id' => (int) $worker['precinct_id'],
            'scope_election_period_id' => (int) $worker['election_period_id'],
        ]];
    }

    return [" AND {$workerAlias}.id = :scope_worker_id", ['scope_worker_id' => (int) $worker['id']]];
}

function election_close_period(int $periodId): void
{
    db()->beginTransaction();

    $statement = db()->prepare('UPDATE election_workers SET is_active = 0 WHERE election_period_id = :period_id');
    $statement->execute(['period_id' => $periodId]);

    $statement = db()->prepare('UPDATE election_periods SET is_active = 0 WHERE id = :id');
    $statement->execute(['id' => $periodId]);

    db()->commit();
}
