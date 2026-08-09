<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_payroll_setup();

[$periods, $selectedPeriodId, $selectedPeriod] = election_payroll_period_context();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPeriodId > 0) {
    if (election_payroll_period_is_locked($selectedPeriodId)) {
        flash('error', 'Payroll is locked. Unlock payroll from the Summary page before changing election-day pay.');
        redirect_to('departments/election/payroll-election-day.php?election_period_id=' . $selectedPeriodId);
    }

    $statuses = (array) ($_POST['work_status'] ?? []);
    $payAsChief = array_map('intval', (array) ($_POST['pay_as_chief_judge'] ?? []));
    $notes = (array) ($_POST['assignment_notes'] ?? []);
    $userId = current_user()['id'] ?? null;

    $assignmentStatement = db()->prepare(
        'SELECT id, election_period_id, worker_id
         FROM election_worker_assignments
         WHERE election_period_id = :election_period_id'
    );
    $assignmentStatement->execute(['election_period_id' => $selectedPeriodId]);

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

    foreach ($assignmentStatement->fetchAll() as $assignment) {
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

    audit_event('updated_payroll_election_day', 'election_period', (string) $selectedPeriodId);
    flash('success', 'Election-day pay saved.');
    redirect_to('departments/election/payroll-election-day.php?election_period_id=' . $selectedPeriodId);
}

$calculation = $selectedPeriodId > 0 ? election_payroll_calculation($selectedPeriodId) : ['settings' => [], 'assignment_rows' => []];
$settings = $calculation['settings'];
$assignmentRows = $calculation['assignment_rows'];
$isLocked = (int) ($settings['is_locked'] ?? 0) === 1;
$summaryUrl = url('departments/election/payroll.php?election_period_id=' . $selectedPeriodId);
$trainingUrl = url('departments/election/payroll-training.php?election_period_id=' . $selectedPeriodId);

page_header('Election Day Pay');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Day Pay</h1>
        <p>Mark whether each assigned worker worked a full day, half day, or did not work.</p>
        <?php election_navigation('payroll-election-day'); ?>

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
                <button type="submit">View election pay</button>
                <a class="button secondary" href="<?= e($trainingUrl) ?>">Training Pay</a>
                <a class="button secondary" href="<?= e($summaryUrl) ?>">Summary</a>
            </div>
        </form>
    </section>

    <form method="post">
        <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1><?= e($selectedPeriod['name'] ?? 'Election') ?></h1>
                    <p class="muted">Assistant Chief Judges can be paid as Chief Judge when they took over that responsibility.</p>
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
            <div class="actions" style="margin-top: 18px;">
                <button type="submit" <?= $isLocked ? 'disabled' : '' ?>>Save election-day pay</button>
            </div>
        </section>
    </form>
</main>
<?php page_footer(); ?>
