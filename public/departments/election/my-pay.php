<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();
election_require_payroll_setup();

$worker = current_election_worker();
$assignment = current_election_assignment();
if ($worker && !$assignment) {
    redirect_to('departments/election/select-assignment.php');
}

if (!$worker || !$assignment) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You must be signed in as an election worker to view this page.</p></section></main>';
    page_footer();
    exit;
}

$periodId = (int) $assignment['election_period_id'];
$calculation = election_payroll_calculation($periodId);
$settings = $calculation['settings'];
$summaryRows = $calculation['summary_rows'];
$assignmentRows = array_values(array_filter(
    $calculation['assignment_rows'],
    fn($row) => (int) $row['worker_id'] === (int) $worker['id']
));

$payRow = null;
foreach ($summaryRows as $row) {
    if ((int) $row['worker_id'] === (int) $worker['id']) {
        $payRow = $row;
        break;
    }
}

$isLocked = (int) ($settings['is_locked'] ?? 0) === 1;

page_header('My Pay Estimate');
?>
<main class="shell">
    <section class="panel">
        <h1>My Pay Estimate</h1>
        <p><?= e(election_person_name($worker)) ?> - <?= e($assignment['election_name']) ?></p>
        <?php election_navigation('my-pay'); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Estimate</h1>
                <p class="muted">
                    This amount is an estimate until payroll is finalized by the Election Supervisor.
                    <?= $isLocked ? 'Payroll has been locked for this election period.' : 'Payroll is still being reviewed.' ?>
                </p>
            </div>
            <span class="badge <?= $isLocked ? 'badge-success' : 'badge-muted' ?>"><?= $isLocked ? 'Finalized' : 'Estimate' ?></span>
        </div>

        <?php if ($payRow): ?>
            <div class="grid dashboard-stat-grid election-home-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) $payRow['election_day_pay'])) ?></h3>
                    <p>Election day pay</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) $payRow['training_pay'])) ?></h3>
                    <p>Training pay</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) $payRow['mileage_pay'])) ?></h3>
                    <p>Mileage pay</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(election_payroll_money((float) $payRow['total_pay'])) ?></h3>
                    <p>Estimated total</p>
                </article>
            </div>
        <?php else: ?>
            <p>No pay estimate is available for this election assignment yet.</p>
        <?php endif; ?>
    </section>

    <?php if ($payRow): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Details</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Details</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td data-label="Category">Election day</td>
                        <td data-label="Details">
                            <?= e($payRow['positions'] ?: 'No position listed') ?>
                            <?php if ($payRow['precincts']): ?>
                                <br><span class="meta"><?= e($payRow['precincts']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Amount"><?= e(election_payroll_money((float) $payRow['election_day_pay'])) ?></td>
                    </tr>
                    <tr>
                        <td data-label="Category">Training</td>
                        <td data-label="Details">
                            <?= e((string) $payRow['training_completed_count']) ?> completed class<?= (int) $payRow['training_completed_count'] === 1 ? '' : 'es' ?>
                            <br><span class="meta">Training pay is capped at <?= e(election_payroll_money((float) $settings['training_cap'])) ?>.</span>
                        </td>
                        <td data-label="Amount"><?= e(election_payroll_money((float) $payRow['training_pay'])) ?></td>
                    </tr>
                    <tr>
                        <td data-label="Category">Mileage</td>
                        <td data-label="Details">
                            <?= e(number_format((float) $payRow['training_miles_round_trip'], 2)) ?> approved round-trip training miles
                            <?php if ($payRow['mileage_notes']): ?>
                                <br><span class="meta"><?= e($payRow['mileage_notes']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Amount"><?= e(election_payroll_money((float) $payRow['mileage_pay'])) ?></td>
                    </tr>
                    <tr>
                        <td data-label="Category"><strong>Total</strong></td>
                        <td data-label="Details">Estimated total pay</td>
                        <td data-label="Amount"><strong><?= e(election_payroll_money((float) $payRow['total_pay'])) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Election Day Status</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Precinct</th>
                        <th>Position</th>
                        <th>Status</th>
                        <th>Pay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignmentRows as $row): ?>
                        <?php
                        $statusLabels = [
                            'not_set' => 'Not entered yet',
                            'full_day' => 'Full day',
                            'half_day' => 'Half day',
                            'did_not_work' => 'Did not work',
                        ];
                        ?>
                        <tr>
                            <td data-label="Precinct"><?= e($row['precinct_name']) ?></td>
                            <td data-label="Position"><?= e((int) $row['pay_as_chief_judge'] === 1 ? 'Chief Judge' : $row['position_name']) ?></td>
                            <td data-label="Status"><?= e($statusLabels[$row['work_status']] ?? 'Not entered yet') ?></td>
                            <td data-label="Pay"><?= e(election_payroll_money((float) $row['calculated_day_pay'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
