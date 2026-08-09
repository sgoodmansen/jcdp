<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_payroll_setup();

[$periods, $selectedPeriodId, $selectedPeriod] = election_payroll_period_context();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPeriodId > 0) {
    if (election_payroll_period_is_locked($selectedPeriodId)) {
        flash('error', 'Payroll is locked. Unlock payroll from the Summary page before changing training mileage.');
        redirect_to('departments/election/payroll-training.php?election_period_id=' . $selectedPeriodId);
    }

    $miles = (array) ($_POST['training_miles_round_trip'] ?? []);
    $mileageNotes = (array) ($_POST['mileage_notes'] ?? []);
    $userId = current_user()['id'] ?? null;
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

    audit_event('updated_payroll_training', 'election_period', (string) $selectedPeriodId);
    flash('success', 'Training pay saved.');
    redirect_to('departments/election/payroll-training.php?election_period_id=' . $selectedPeriodId);
}

$calculation = $selectedPeriodId > 0 ? election_payroll_calculation($selectedPeriodId) : ['settings' => [], 'summary_rows' => []];
$settings = $calculation['settings'];
$summaryRows = array_values(array_filter($calculation['summary_rows'], fn($row) => (int) $row['training_completed_count'] > 0 || (float) $row['training_miles_round_trip'] > 0));
$isLocked = (int) ($settings['is_locked'] ?? 0) === 1;
$summaryUrl = url('departments/election/payroll.php?election_period_id=' . $selectedPeriodId);
$electionDayUrl = url('departments/election/payroll-election-day.php?election_period_id=' . $selectedPeriodId);

page_header('Training Pay');
?>
<main class="shell">
    <section class="panel">
        <h1>Training Pay</h1>
        <p>Review completed training pay and approved round-trip training mileage.</p>
        <?php election_navigation('payroll-training'); ?>

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
                <button type="submit">View training pay</button>
                <a class="button secondary" href="<?= e($electionDayUrl) ?>">Election Day Pay</a>
                <a class="button secondary" href="<?= e($summaryUrl) ?>">Summary</a>
            </div>
        </form>
    </section>

    <section class="dashboard-stat-row election-home-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Training Rules</h2>
            <div class="grid dashboard-stat-grid election-home-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) ($settings['training_rate'] ?? 0))) ?></h3>
                    <p>Per completed class</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) ($settings['training_cap'] ?? 0))) ?></h3>
                    <p>Training cap</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(number_format((float) ($settings['mileage_rate'] ?? 0), 3)) ?></h3>
                    <p>Mileage rate</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(number_format((float) ($settings['mileage_minimum_miles'] ?? 20), 2)) ?></h3>
                    <p>Minimum miles</p>
                </article>
            </div>
        </div>
    </section>

    <form method="post">
        <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1><?= e($selectedPeriod['name'] ?? 'Election') ?></h1>
                    <p class="muted">Mileage is manually entered for approved drivers and pays only when round-trip miles are greater than <?= e(number_format((float) ($settings['mileage_minimum_miles'] ?? 20), 2)) ?>.</p>
                </div>
                <span class="badge <?= $isLocked ? 'badge-warning' : 'badge-success' ?>"><?= $isLocked ? 'Locked' : 'Editable' ?></span>
            </div>

            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Worker</th>
                        <th>Completed</th>
                        <th>Training Pay</th>
                        <th>Driver Classes</th>
                        <th>Round-trip Miles</th>
                        <th>Mileage Pay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summaryRows as $row): ?>
                        <tr>
                            <td data-label="Worker">
                                <?= e(election_person_name($row)) ?><br>
                                <span class="meta"><?= e($row['positions']) ?></span>
                            </td>
                            <td data-label="Completed"><?= e((string) $row['training_completed_count']) ?></td>
                            <td data-label="Training Pay"><?= e(election_payroll_money((float) $row['training_pay'])) ?></td>
                            <td data-label="Driver Classes"><?= e((string) $row['training_driver_count']) ?></td>
                            <td data-label="Round-trip Miles">
                                <input type="number" step="0.01" min="0" name="training_miles_round_trip[<?= e((string) $row['worker_id']) ?>]" value="<?= e((string) $row['training_miles_round_trip']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                                <input name="mileage_notes[<?= e((string) $row['worker_id']) ?>]" value="<?= e($row['mileage_notes']) ?>" placeholder="Mileage notes" <?= $isLocked ? 'disabled' : '' ?>>
                            </td>
                            <td data-label="Mileage Pay"><?= e(election_payroll_money((float) $row['mileage_pay'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$summaryRows): ?>
                        <tr><td colspan="6">No completed training found for this election period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="actions" style="margin-top: 18px;">
                <button type="submit" <?= $isLocked ? 'disabled' : '' ?>>Save training pay</button>
            </div>
        </section>
    </form>
</main>
<?php page_footer(); ?>
