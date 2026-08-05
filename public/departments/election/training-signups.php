<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();

$currentAssignment = current_election_assignment();
$isManager = can_manage_election_module();

$periods = $isManager
    ? db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll()
    : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['election_period_id']]);
    $periods = array_filter([$statement->fetch() ?: null]);
}

$selectedPeriodId = (int) ($_GET['election_period_id'] ?? 0);
if ($selectedPeriodId === 0) {
    foreach ($periods as $period) {
        if ((int) $period['is_active'] === 1) {
            $selectedPeriodId = (int) $period['id'];
            break;
        }
    }
}
if ($selectedPeriodId === 0 && $periods) {
    $selectedPeriodId = (int) $periods[0]['id'];
}

$allowedPeriodIds = array_map(fn($period) => (int) $period['id'], $periods);
if ($selectedPeriodId > 0 && !in_array($selectedPeriodId, $allowedPeriodIds, true)) {
    $selectedPeriodId = (int) ($allowedPeriodIds[0] ?? 0);
}

$precincts = $isManager ? election_precincts() : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_precincts WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['precinct_id']]);
    $precincts = array_filter([$statement->fetch() ?: null]);
}

$selectedPrecinctId = (int) ($_GET['precinct_id'] ?? 0);
$allowedPrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $precincts);
if ($selectedPrecinctId > 0 && !in_array($selectedPrecinctId, $allowedPrecinctIds, true)) {
    $selectedPrecinctId = 0;
}
if (!$isManager && $allowedPrecinctIds) {
    $selectedPrecinctId = (int) $allowedPrecinctIds[0];
}

$assignmentRows = [];
if ($selectedPeriodId > 0) {
    $sql = 'SELECT election_worker_assignments.*,
                   election_workers.first_name,
                   election_workers.last_name,
                   election_workers.email,
                   election_workers.phone,
                   election_precincts.name AS precinct_name,
                   election_positions.name AS position_name,
                   election_positions.is_chief_judge,
                   election_positions.is_assistant_chief_judge,
                   CASE WHEN election_precinct_roles.assignment_id IS NULL THEN 0 ELSE 1 END AS is_assistant_chief_judge_extra
            FROM election_worker_assignments
            INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
            INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
            INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
            LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
                AND election_precinct_roles.role_key = :assistant_role
            WHERE election_worker_assignments.election_period_id = :election_period_id
              AND election_worker_assignments.is_active = 1';
    $params = [
        'assistant_role' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
        'election_period_id' => $selectedPeriodId,
    ];

    if ($selectedPrecinctId > 0) {
        $sql .= ' AND election_worker_assignments.precinct_id = :precinct_id';
        $params['precinct_id'] = $selectedPrecinctId;
    } elseif (!$isManager && $allowedPrecinctIds) {
        $placeholders = [];
        foreach ($allowedPrecinctIds as $index => $precinctId) {
            $key = 'precinct_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $precinctId;
        }
        $sql .= ' AND election_worker_assignments.precinct_id IN (' . implode(',', $placeholders) . ')';
    }

    $sql .= ' ORDER BY election_precincts.name, election_positions.sort_order, election_workers.last_name, election_workers.first_name';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $assignmentRows = $statement->fetchAll();
}

$assignmentIds = array_map(fn($row) => (int) $row['id'], $assignmentRows);
$registrationsByAssignmentId = [];
if ($assignmentIds) {
    $placeholders = [];
    $params = ['election_period_id' => $selectedPeriodId];
    foreach ($assignmentIds as $index => $assignmentId) {
        $key = 'assignment_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $assignmentId;
    }

    $statement = db()->prepare(
        'SELECT election_training_registrations.assignment_id,
                election_training_registrations.attended,
                election_training_classes.id AS class_id,
                election_training_classes.class_title,
                election_training_classes.class_date,
                election_training_classes.start_time,
                GROUP_CONCAT(election_training_class_positions.position_id ORDER BY election_positions.sort_order SEPARATOR ",") AS class_position_ids
         FROM election_training_registrations
         INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
         LEFT JOIN election_training_class_positions ON election_training_class_positions.class_id = election_training_classes.id
         LEFT JOIN election_positions ON election_positions.id = election_training_class_positions.position_id
         WHERE election_training_registrations.assignment_id IN (' . implode(',', $placeholders) . ')
           AND election_training_classes.election_period_id = :election_period_id
           AND election_training_classes.is_cancelled = 0
         GROUP BY election_training_registrations.assignment_id,
                  election_training_classes.id,
                  election_training_registrations.attended
         ORDER BY election_training_classes.class_date, election_training_classes.start_time'
    );
    $statement->execute($params);

    foreach ($statement->fetchAll() as $registration) {
        $registrationsByAssignmentId[(int) $registration['assignment_id']][] = $registration;
    }
}

$optionalTrainingPositionIds = election_optional_training_position_ids();
$now = new DateTimeImmutable();

function election_training_signup_status(array $registration, DateTimeImmutable $now): array
{
    if ((int) $registration['attended'] === 1) {
        return ['label' => 'Complete', 'class' => 'badge-success'];
    }

    $classStartsAt = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        (string) $registration['class_date'] . ' ' . (string) $registration['start_time']
    );

    if ($classStartsAt && $classStartsAt < $now) {
        return ['label' => 'Missed', 'class' => 'badge-warning'];
    }

    return ['label' => 'Scheduled', 'class' => 'badge-muted'];
}

$actions = [
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php?election_period_id=' . $selectedPeriodId), 'primary' => true],
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

page_header('Training Signups');
?>
<main class="shell">
    <section class="panel">
        <h1>Training Signups</h1>
        <p>Review assigned workers and the training classes they are signed up for.</p>
        <?php election_navigation('training-signups'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Filters</h1>
        <form class="form compact-form" method="get">
            <label>
                Election
                <select name="election_period_id" required>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $selectedPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Precinct
                <select name="precinct_id">
                    <?php if ($isManager): ?>
                        <option value="">All precincts</option>
                    <?php endif; ?>
                    <?php foreach ($precincts as $precinct): ?>
                        <option value="<?= e((string) $precinct['id']) ?>" <?= $selectedPrecinctId === (int) $precinct['id'] ? 'selected' : '' ?>>
                            <?= e($precinct['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">View signups</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Workers</h1>
            <span class="badge badge-muted"><?= e((string) count($assignmentRows)) ?> shown</span>
        </div>
        <table class="table mobile-card-table training-signups-table">
            <thead>
                <tr>
                    <th>Worker</th>
                    <th>Precinct</th>
                    <th>Position</th>
                    <th>Training</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignmentRows as $row): ?>
                    <?php
                    $assignmentTrainingIds = election_assignment_training_position_ids($row);
                    $registrations = $registrationsByAssignmentId[(int) $row['id']] ?? [];
                    ?>
                    <tr>
                        <td data-label="Worker">
                            <?= e(election_person_name($row)) ?>
                            <br><span class="meta"><?= e($row['email'] ?: 'No email') ?> - <?= e($row['phone'] ?: 'No phone') ?></span>
                        </td>
                        <td data-label="Precinct"><?= e($row['precinct_name']) ?></td>
                        <td data-label="Position">
                            <?= e($row['position_name']) ?>
                            <div class="training-role-tags">
                                <?php if ((int) ($row['is_extra'] ?? 0) === 1): ?>
                                    <span class="badge badge-muted training-role-tag">Extra</span>
                                <?php endif; ?>
                                <?php if ((int) ($row['is_assistant_chief_judge_extra'] ?? 0) === 1): ?>
                                    <span class="badge badge-muted training-role-tag">Assistant Chief</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Training">
                            <?php if (!$registrations): ?>
                                <span class="meta">No class signup</span>
                            <?php else: ?>
                                <div class="training-signup-list">
                                    <?php foreach ($registrations as $registration): ?>
                                        <div class="training-signup-item">
                                            <a href="<?= e(url('departments/election/class-detail.php?id=' . (int) $registration['class_id'])) ?>">
                                                <?= e($registration['class_title']) ?>
                                            </a>
                                            <br><span class="meta"><?= e(format_display_date($registration['class_date'])) ?> <?= e(format_display_time($registration['start_time'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <?php if (!$registrations): ?>
                                <span class="badge badge-muted">Not signed up</span>
                            <?php else: ?>
                                <div class="training-status-list">
                                    <?php foreach ($registrations as $registration): ?>
                                        <?php
                                        $classPositionIds = array_values(array_filter(array_map('intval', explode(',', (string) ($registration['class_position_ids'] ?? '')))));
                                        $isOptionalRole = (bool) array_intersect($assignmentTrainingIds, $optionalTrainingPositionIds);
                                        $matchesCurrentPosition = $isOptionalRole || (bool) array_intersect($assignmentTrainingIds, $classPositionIds);
                                        $status = election_training_signup_status($registration, $now);
                                        ?>
                                        <div class="training-status-item">
                                            <span class="badge <?= e($status['class']) ?>">
                                                <?= e($status['label']) ?>
                                            </span>
                                            <?php if (!$matchesCurrentPosition): ?>
                                                <span class="badge badge-warning">Position mismatch</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$assignmentRows): ?>
                    <tr><td colspan="5">No active worker assignments were found for this selection.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
