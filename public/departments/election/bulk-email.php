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

$selectedPeriodId = (int) ($_REQUEST['election_period_id'] ?? 0);
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

$selectedPrecinctId = (int) ($_REQUEST['precinct_id'] ?? 0);
$allowedPrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $precincts);
if ($selectedPrecinctId > 0 && !in_array($selectedPrecinctId, $allowedPrecinctIds, true)) {
    $selectedPrecinctId = 0;
}
if (!$isManager && $allowedPrecinctIds) {
    $selectedPrecinctId = (int) $allowedPrecinctIds[0];
}

$recipientFilter = $_REQUEST['recipient_filter'] ?? 'all';
if (!in_array($recipientFilter, ['all', 'not_signed_up', 'signed_up', 'complete'], true)) {
    $recipientFilter = 'all';
}

function election_bulk_email_recipients(int $periodId, int $precinctId, string $recipientFilter): array
{
    if ($periodId <= 0) {
        return [];
    }

    $sql = 'SELECT election_workers.*,
                   election_periods.name AS election_name,
                   MIN(election_worker_assignments.id) AS email_assignment_id,
                   COUNT(DISTINCT election_worker_assignments.id) AS assignment_count,
                   COUNT(DISTINCT election_training_registrations.class_id) AS training_registration_count,
                   SUM(CASE WHEN election_training_registrations.attended = 1 THEN 1 ELSE 0 END) AS training_attended_count,
                   GROUP_CONCAT(
                       DISTINCT CONCAT(election_precincts.name, " - ", election_positions.name)
                       ORDER BY election_precincts.name, election_positions.sort_order
                       SEPARATOR "\n"
                   ) AS assignment_summary
            FROM election_worker_assignments
            INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
            INNER JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
            INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
            INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
            LEFT JOIN election_training_registrations ON election_training_registrations.assignment_id = election_worker_assignments.id
            LEFT JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
                AND election_training_classes.election_period_id = election_worker_assignments.election_period_id
                AND election_training_classes.is_cancelled = 0
            WHERE election_worker_assignments.election_period_id = :election_period_id
              AND election_worker_assignments.is_active = 1
              AND election_workers.availability_status = :availability_status
              AND election_workers.is_active = 1';
    $params = [
        'election_period_id' => $periodId,
        'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
    ];

    if ($precinctId > 0) {
        $sql .= ' AND election_worker_assignments.precinct_id = :precinct_id';
        $params['precinct_id'] = $precinctId;
    }

    $sql .= ' GROUP BY election_workers.id';

    if ($recipientFilter === 'not_signed_up') {
        $sql .= ' HAVING training_registration_count = 0';
    } elseif ($recipientFilter === 'signed_up') {
        $sql .= ' HAVING training_registration_count > 0 AND training_attended_count = 0';
    } elseif ($recipientFilter === 'complete') {
        $sql .= ' HAVING training_attended_count > 0';
    }

    $sql .= ' ORDER BY election_workers.last_name, election_workers.first_name
              LIMIT 500';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

$recipients = election_bulk_email_recipients($selectedPeriodId, $selectedPrecinctId, $recipientFilter);
$sendableRecipients = array_values(array_filter($recipients, fn($recipient) => trim((string) ($recipient['email'] ?? '')) !== ''));
$missingEmailCount = count($recipients) - count($sendableRecipients);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_bulk_email') {
    $sentCount = 0;
    $failedCount = 0;

    foreach ($sendableRecipients as $recipient) {
        $token = election_generate_worker_token((int) $recipient['id']);
        $accessUrl = election_worker_access_url((int) $recipient['id'], $token);
        if (election_send_worker_welcome_email($recipient, $accessUrl)) {
            $sentCount++;
        } else {
            $failedCount++;
        }
    }

    audit_event('sent_bulk_welcome_email', 'election_period', (string) $selectedPeriodId, [
        'precinct_id' => $selectedPrecinctId,
        'recipient_filter' => $recipientFilter,
        'sent_count' => $sentCount,
        'failed_count' => $failedCount,
        'missing_email_count' => $missingEmailCount,
    ]);

    if ($sentCount > 0 && $failedCount === 0) {
        flash('success', 'Bulk email sent to ' . $sentCount . ' worker' . ($sentCount === 1 ? '' : 's') . '.');
    } elseif ($sentCount > 0) {
        flash('error', 'Bulk email sent to ' . $sentCount . ' worker' . ($sentCount === 1 ? '' : 's') . ', but ' . $failedCount . ' failed.');
    } else {
        flash('error', 'No emails were sent. Check that selected workers have email addresses and that mail is available.');
    }

    redirect_to('departments/election/bulk-email.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $selectedPrecinctId . '&recipient_filter=' . urlencode($recipientFilter));
}

$actions = [
    ['label' => 'Workers', 'href' => url('departments/election/workers.php'), 'primary' => true],
    ['label' => 'Email Template', 'href' => url('departments/election/email-template.php')],
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Staffing Progress', 'href' => url('departments/election/staffing-progress.php?election_period_id=' . $selectedPeriodId)],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

page_header('Bulk Email Access Links');
?>
<main class="shell">
    <section class="panel">
        <h1>Bulk Email Access Links</h1>
        <p>Preview workers and send fresh election access links by email.</p>
        <?php election_navigation('bulk-email'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Recipients</h1>
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
            <label>
                Training status
                <select name="recipient_filter">
                    <option value="all" <?= $recipientFilter === 'all' ? 'selected' : '' ?>>All assigned workers</option>
                    <option value="not_signed_up" <?= $recipientFilter === 'not_signed_up' ? 'selected' : '' ?>>Not signed up</option>
                    <option value="signed_up" <?= $recipientFilter === 'signed_up' ? 'selected' : '' ?>>Signed up, not complete</option>
                    <option value="complete" <?= $recipientFilter === 'complete' ? 'selected' : '' ?>>Training complete</option>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Preview</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Preview</h1>
                <p class="muted"><?= e((string) count($sendableRecipients)) ?> can receive email<?= $missingEmailCount > 0 ? ' / ' . e((string) $missingEmailCount) . ' missing email' : '' ?>.</p>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="send_bulk_email">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                <input type="hidden" name="recipient_filter" value="<?= e($recipientFilter) ?>">
                <button type="submit" <?= $sendableRecipients ? '' : 'disabled' ?>>Send access links</button>
            </form>
        </div>

        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Assignments</th>
                    <th>Training</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recipients as $recipient): ?>
                    <?php
                    $trainingRegistrationCount = (int) $recipient['training_registration_count'];
                    $trainingAttendedCount = (int) $recipient['training_attended_count'];
                    $trainingLabel = $trainingAttendedCount > 0 ? 'Complete' : ($trainingRegistrationCount > 0 ? 'Signed up' : 'Not signed up');
                    ?>
                    <tr>
                        <td data-label="Name"><?= e(election_person_name($recipient)) ?></td>
                        <td data-label="Email"><?= e($recipient['email'] ?: 'No email') ?></td>
                        <td data-label="Assignments">
                            <?php foreach (array_filter(explode("\n", (string) ($recipient['assignment_summary'] ?? ''))) as $summary): ?>
                                <?= e($summary) ?><br>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="Training">
                            <span class="badge <?= $trainingAttendedCount > 0 ? 'badge-success' : ($trainingRegistrationCount > 0 ? 'badge-muted' : 'badge-warning') ?>">
                                <?= e($trainingLabel) ?>
                            </span>
                        </td>
                        <td data-label="Status">
                            <span class="badge <?= trim((string) ($recipient['email'] ?? '')) !== '' ? 'badge-success' : 'badge-warning' ?>">
                                <?= trim((string) ($recipient['email'] ?? '')) !== '' ? 'Ready' : 'Missing email' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recipients): ?>
                    <tr><td colspan="5">No workers matched the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
