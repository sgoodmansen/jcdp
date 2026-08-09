<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_payroll_setup();

$periods = db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll();
$selectedPeriodId = (int) ($_GET['election_period_id'] ?? $_POST['election_period_id'] ?? 0);
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

$selectedPeriod = null;
foreach ($periods as $period) {
    if ((int) $period['id'] === $selectedPeriodId) {
        $selectedPeriod = $period;
        break;
    }
}

if ($selectedPeriodId > 0) {
    election_payroll_ensure_period_defaults($selectedPeriodId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPeriodId > 0) {
    $action = $_POST['action'] ?? '';
    $isLocked = election_payroll_period_is_locked($selectedPeriodId);
    $userId = current_user()['id'] ?? null;

    if ($action === 'lock_payroll') {
        $statement = db()->prepare(
            'UPDATE election_payroll_settings
             SET is_locked = 1, locked_at = NOW(), locked_by_user_id = :user_id, updated_by_user_id = :user_id
             WHERE election_period_id = :election_period_id'
        );
        $statement->execute(['user_id' => $userId, 'election_period_id' => $selectedPeriodId]);
        audit_event('locked_payroll', 'election_period', (string) $selectedPeriodId);
        flash('success', 'Payroll locked for this election period.');
        redirect_to('departments/election/payroll.php?election_period_id=' . $selectedPeriodId);
    }

    if ($action === 'unlock_payroll') {
        $statement = db()->prepare(
            'UPDATE election_payroll_settings
             SET is_locked = 0, locked_at = NULL, locked_by_user_id = NULL, updated_by_user_id = :user_id
             WHERE election_period_id = :election_period_id'
        );
        $statement->execute(['user_id' => $userId, 'election_period_id' => $selectedPeriodId]);
        audit_event('unlocked_payroll', 'election_period', (string) $selectedPeriodId);
        flash('success', 'Payroll unlocked.');
        redirect_to('departments/election/payroll.php?election_period_id=' . $selectedPeriodId);
    }

    if ($isLocked) {
        flash('error', 'Payroll is locked. Unlock payroll before changing work status or mileage.');
        redirect_to('departments/election/payroll.php?election_period_id=' . $selectedPeriodId);
    }

    if ($action === 'save_payroll') {
        $statuses = (array) ($_POST['work_status'] ?? []);
        $payAsChief = array_map('intval', (array) ($_POST['pay_as_chief_judge'] ?? []));
        $notes = (array) ($_POST['assignment_notes'] ?? []);

        $assignmentStatement = db()->prepare(
            'SELECT id, election_period_id, worker_id
             FROM election_worker_assignments
             WHERE election_period_id = :election_period_id'
        );
        $assignmentStatement->execute(['election_period_id' => $selectedPeriodId]);
        $validAssignments = $assignmentStatement->fetchAll();

        $saveWork = db()->prepare(
            'INSERT INTO election_payroll_work_records (
                assignment_id, election_period_id, worker_id, work_status, pay_as_chief_judge, notes, updated_by_user_id
             ) VALUES (
                :assignment_id, :election_period_id, :worker_id, :work_status, :pay_as_chief_judge, :notes, :user_id
             )
             ON DUPLICATE KEY UPDATE
                work_status = VALUES(work_status),
                pay_as_chief_judge = VALUES(pay_as_chief_judge),
                notes = VALUES(notes),
                updated_by_user_id = VALUES(updated_by_user_id)'
        );

        foreach ($validAssignments as $assignment) {
            $assignmentId = (int) $assignment['id'];
            $status = (string) ($statuses[$assignmentId] ?? 'not_set');
            if (!in_array($status, ['not_set', 'full_day', 'half_day', 'did_not_work'], true)) {
                $status = 'not_set';
            }
            $saveWork->execute([
                'assignment_id' => $assignmentId,
                'election_period_id' => (int) $assignment['election_period_id'],
                'worker_id' => (int) $assignment['worker_id'],
                'work_status' => $status,
                'pay_as_chief_judge' => in_array($assignmentId, $payAsChief, true) ? 1 : 0,
                'notes' => trim((string) ($notes[$assignmentId] ?? '')),
                'user_id' => $userId,
            ]);
        }

        $miles = (array) ($_POST['training_miles_round_trip'] ?? []);
        $mileageNotes = (array) ($_POST['mileage_notes'] ?? []);
        $saveMileage = db()->prepare(
            'INSERT INTO election_payroll_worker_mileage (
                election_period_id, worker_id, training_miles_round_trip, notes, updated_by_user_id
             ) VALUES (
                :election_period_id, :worker_id, :training_miles_round_trip, :notes, :user_id
             )
             ON DUPLICATE KEY UPDATE
                training_miles_round_trip = VALUES(training_miles_round_trip),
                notes = VALUES(notes),
                updated_by_user_id = VALUES(updated_by_user_id)'
        );
        foreach ($miles as $workerId => $mileValue) {
            $workerId = (int) $workerId;
            if ($workerId <= 0) {
                continue;
            }
            $saveMileage->execute([
                'election_period_id' => $selectedPeriodId,
                'worker_id' => $workerId,
                'training_miles_round_trip' => max(0, (float) $mileValue),
                'notes' => trim((string) ($mileageNotes[$workerId] ?? '')),
                'user_id' => $userId,
            ]);
        }

        audit_event('updated_payroll', 'election_period', (string) $selectedPeriodId);
        flash('success', 'Payroll review saved.');
        redirect_to('departments/election/payroll.php?election_period_id=' . $selectedPeriodId);
    }
}

$calculation = $selectedPeriodId > 0 ? election_payroll_calculation($selectedPeriodId) : ['settings' => [], 'assignment_rows' => [], 'summary_rows' => []];
$settings = $calculation['settings'];
$assignmentRows = $calculation['assignment_rows'];
$summaryRows = $calculation['summary_rows'];
$isLocked = (int) ($settings['is_locked'] ?? 0) === 1;

if (($_GET['format'] ?? '') === 'csv') {
    $filenameElection = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($selectedPeriod['name'] ?? 'election'));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="election-payroll-' . trim($filenameElection, '-') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Election', 'Worker Name', 'Mailing Address', 'City', 'State', 'Zip', 'Precincts', 'Positions', 'Election Day Pay', 'Completed Trainings', 'Training Pay', 'Training Miles', 'Mileage Pay', 'Total Pay']);
    foreach ($summaryRows as $row) {
        fputcsv($output, [
            $selectedPeriod['name'] ?? '',
            election_person_name($row),
            $row['mailing_address'] ?? '',
            $row['city'] ?? '',
            $row['state'] ?? '',
            $row['zip_code'] ?? '',
            $row['precincts'],
            $row['positions'],
            number_format((float) $row['election_day_pay'], 2, '.', ''),
            (int) $row['training_completed_count'],
            number_format((float) $row['training_pay'], 2, '.', ''),
            number_format((float) $row['training_miles_round_trip'], 2, '.', ''),
            number_format((float) $row['mileage_pay'], 2, '.', ''),
            number_format((float) $row['total_pay'], 2, '.', ''),
        ]);
    }
    fclose($output);
    exit;
}

$exportUrl = url('departments/election/payroll.php?' . http_build_query(['election_period_id' => $selectedPeriodId, 'format' => 'csv']));
$printUrl = url('departments/election/payroll-print.php?election_period_id=' . $selectedPeriodId);
$setupUrl = url('departments/election/payroll-setup.php?election_period_id=' . $selectedPeriodId);
$totalPayroll = array_sum(array_map(fn($row) => (float) $row['total_pay'], $summaryRows));

page_header('Payroll');
?>
<main class="shell">
    <section class="panel">
        <h1>Payroll</h1>
        <p>Review election-day work, completed training pay, and approved training mileage.</p>
        <?php election_navigation('payroll'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel roster-toolbar" style="margin-top: 18px;">
        <h1>Options</h1>
        <form class="form compact-form payroll-options-form" method="get">
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
            <div class="actions">
                <button type="submit">View payroll</button>
                <a class="button secondary" href="<?= e($setupUrl) ?>">Payroll setup</a>
                <a class="button secondary" href="<?= e($exportUrl) ?>">Export CSV</a>
                <a class="button secondary" href="<?= e($printUrl) ?>">Print PDF</a>
            </div>
        </form>
    </section>

    <section class="dashboard-stat-row election-home-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Payroll Summary</h2>
            <div class="grid dashboard-stat-grid election-home-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) count($summaryRows)) ?></h3>
                    <p>People included</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money($totalPayroll)) ?></h3>
                    <p>Estimated total</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e($isLocked ? 'Locked' : 'Open') ?></h3>
                    <p>Payroll status</p>
                </article>
            </div>
        </div>
    </section>

    <form method="post">
        <input type="hidden" name="action" value="save_payroll">
        <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">

        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>Election Day Work</h1>
                    <p class="muted">Mark whether each assigned worker worked a full day, half day, or did not work.</p>
                </div>
                <span class="badge <?= $isLocked ? 'badge-warning' : 'badge-success' ?>"><?= $isLocked ? 'Locked' : 'Editable' ?></span>
            </div>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Worker</th>
                        <th>Precinct</th>
                        <th>Position</th>
                        <th>Status</th>
                        <th>Pay</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignmentRows as $row): ?>
                        <tr>
                            <td data-label="Worker"><?= e(election_person_name($row)) ?></td>
                            <td data-label="Precinct"><?= e($row['precinct_name']) ?></td>
                            <td data-label="Position">
                                <?= e($row['position_name']) ?>
                                <?php if ((int) $row['is_assistant_chief'] === 1): ?>
                                    <br><label class="check-label compact-check-label">
                                        <input type="checkbox" name="pay_as_chief_judge[]" value="<?= e((string) $row['assignment_id']) ?>" <?= (int) $row['pay_as_chief_judge'] === 1 ? 'checked' : '' ?> <?= $isLocked ? 'disabled' : '' ?>>
                                        Pay as Chief Judge
                                    </label>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <select name="work_status[<?= e((string) $row['assignment_id']) ?>]" <?= $isLocked ? 'disabled' : '' ?>>
                                    <?php foreach ([
                                        'not_set' => 'Not set',
                                        'full_day' => 'Full day',
                                        'half_day' => 'Half day',
                                        'did_not_work' => 'Did not work',
                                    ] as $value => $label): ?>
                                        <option value="<?= e($value) ?>" <?= $row['work_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td data-label="Pay"><?= e(election_payroll_money((float) $row['calculated_day_pay'])) ?></td>
                            <td data-label="Notes">
                                <input name="assignment_notes[<?= e((string) $row['assignment_id']) ?>]" value="<?= e($row['payroll_notes']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$assignmentRows): ?>
                        <tr><td colspan="6">No assignments found for this election period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Worker Totals</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Worker</th>
                        <th>Positions</th>
                        <th>Election Day</th>
                        <th>Training</th>
                        <th>Mileage</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summaryRows as $row): ?>
                        <tr>
                            <td data-label="Worker">
                                <?= e(election_person_name($row)) ?><br>
                                <span class="meta"><?= e(trim(($row['mailing_address'] ?? '') . ' ' . ($row['city'] ?? '') . ' ' . ($row['state'] ?? '') . ' ' . ($row['zip_code'] ?? ''))) ?></span>
                            </td>
                            <td data-label="Positions"><?= e($row['positions']) ?></td>
                            <td data-label="Election Day"><?= e(election_payroll_money((float) $row['election_day_pay'])) ?></td>
                            <td data-label="Training">
                                <?= e(election_payroll_money((float) $row['training_pay'])) ?><br>
                                <span class="meta"><?= e((string) $row['training_completed_count']) ?> complete<?= (int) $row['training_driver_count'] > 0 ? ' / ' . e((string) $row['training_driver_count']) . ' driver' : '' ?></span>
                            </td>
                            <td data-label="Mileage">
                                <input type="number" step="0.01" min="0" name="training_miles_round_trip[<?= e((string) $row['worker_id']) ?>]" value="<?= e((string) $row['training_miles_round_trip']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                <input name="mileage_notes[<?= e((string) $row['worker_id']) ?>]" value="<?= e($row['mileage_notes']) ?>" placeholder="Mileage notes" <?= $isLocked ? 'disabled' : '' ?>>
                                <span class="meta"><?= e(election_payroll_money((float) $row['mileage_pay'])) ?></span>
                            </td>
                            <td data-label="Total"><strong><?= e(election_payroll_money((float) $row['total_pay'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$summaryRows): ?>
                        <tr><td colspan="6">No payroll records found for this election period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Finalize Payroll</h1>
            <p class="muted">Lock payroll after work status, training attendance, and mileage have been reviewed.</p>
            <div class="actions">
                <button type="submit" <?= $isLocked ? 'disabled' : '' ?>>Save payroll review</button>
            </div>
        </section>
    </form>

    <section class="panel" style="margin-top: 18px;">
        <h1>Payroll Lock</h1>
        <?php if ($isLocked): ?>
            <p>Payroll is locked<?= $settings['locked_at'] ? ' as of ' . e(format_display_date($settings['locked_at'])) : '' ?>.</p>
            <form method="post" class="actions">
                <input type="hidden" name="action" value="unlock_payroll">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <button type="submit" class="secondary">Unlock payroll</button>
            </form>
        <?php else: ?>
            <p>Locking payroll prevents accidental changes to work status, mileage, and rates for this election period.</p>
            <form method="post" class="actions">
                <input type="hidden" name="action" value="lock_payroll">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <button type="submit" class="secondary">Lock payroll</button>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
