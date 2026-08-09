<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_payroll_setup();

[$periods, $selectedPeriodId, $selectedPeriod] = election_payroll_period_context();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPeriodId > 0) {
    $action = $_POST['action'] ?? '';
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
    }

    redirect_to('departments/election/payroll.php?election_period_id=' . $selectedPeriodId);
}

$calculation = $selectedPeriodId > 0 ? election_payroll_calculation($selectedPeriodId) : ['settings' => [], 'summary_rows' => []];
$settings = $calculation['settings'];
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
$electionDayUrl = url('departments/election/payroll-election-day.php?election_period_id=' . $selectedPeriodId);
$trainingUrl = url('departments/election/payroll-training.php?election_period_id=' . $selectedPeriodId);
$totalPayroll = array_sum(array_map(fn($row) => (float) $row['total_pay'], $summaryRows));
$totalElectionDay = array_sum(array_map(fn($row) => (float) $row['election_day_pay'], $summaryRows));
$totalTraining = array_sum(array_map(fn($row) => (float) $row['training_pay'], $summaryRows));
$totalMileage = array_sum(array_map(fn($row) => (float) $row['mileage_pay'], $summaryRows));

page_header('Payroll Summary');
?>
<main class="shell">
    <section class="panel">
        <h1>Payroll Summary</h1>
        <p>Review final totals, export payroll, print the report, and lock the election period payroll.</p>
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
                <button type="submit">View summary</button>
                <a class="button secondary" href="<?= e($electionDayUrl) ?>">Election Day Pay</a>
                <a class="button secondary" href="<?= e($trainingUrl) ?>">Training Pay</a>
                <a class="button secondary" href="<?= e($setupUrl) ?>">Setup</a>
                <a class="button secondary" href="<?= e($exportUrl) ?>">Export CSV</a>
                <a class="button secondary" href="<?= e($printUrl) ?>">Print PDF</a>
            </div>
        </form>
    </section>

    <section class="dashboard-stat-row election-home-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group summary-stat-group">
            <h2><?= e($selectedPeriod['name'] ?? 'Payroll') ?></h2>
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

    <section class="dashboard-stat-row election-home-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Pay Breakdown</h2>
            <div class="grid dashboard-stat-grid election-home-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money($totalElectionDay)) ?></h3>
                    <p>Election day pay</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money($totalTraining)) ?></h3>
                    <p>Training pay</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money($totalMileage)) ?></h3>
                    <p>Mileage pay</p>
                </article>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Worker Totals</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Worker</th>
                    <th>Mailing Address</th>
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
                        <td data-label="Worker"><?= e(election_person_name($row)) ?></td>
                        <td data-label="Mailing Address">
                            <?= e($row['mailing_address'] ?: 'No address') ?><br>
                            <span class="meta"><?= e(trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? '') . ' ' . ($row['zip_code'] ?? ''), ', ')) ?></span>
                        </td>
                        <td data-label="Positions"><?= e($row['positions']) ?></td>
                        <td data-label="Election Day"><?= e(election_payroll_money((float) $row['election_day_pay'])) ?></td>
                        <td data-label="Training">
                            <?= e(election_payroll_money((float) $row['training_pay'])) ?><br>
                            <span class="meta"><?= e((string) $row['training_completed_count']) ?> complete</span>
                        </td>
                        <td data-label="Mileage">
                            <?= e(election_payroll_money((float) $row['mileage_pay'])) ?><br>
                            <span class="meta"><?= e(number_format((float) $row['training_miles_round_trip'], 2)) ?> miles</span>
                        </td>
                        <td data-label="Total"><strong><?= e(election_payroll_money((float) $row['total_pay'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$summaryRows): ?>
                    <tr><td colspan="7">No payroll records found for this election period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

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
            <p>Locking payroll prevents accidental changes to election-day pay, training mileage, and rates for this election period.</p>
            <form method="post" class="actions">
                <input type="hidden" name="action" value="lock_payroll">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <button type="submit" class="secondary">Lock payroll</button>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
