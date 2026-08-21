<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_payroll_setup();

$selectedPeriodId = (int) ($_GET['election_period_id'] ?? 0);
$periodStatement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
$periodStatement->execute(['id' => $selectedPeriodId]);
$selectedPeriod = $periodStatement->fetch();

if (!$selectedPeriod) {
    http_response_code(404);
    page_header('Election period not found');
    echo '<main class="shell"><section class="panel"><h1>Election period not found</h1><p>The selected election period could not be found.</p></section></main>';
    page_footer();
    exit;
}

$calculation = election_payroll_calculation($selectedPeriodId);
$settings = $calculation['settings'];
$summaryRows = $calculation['summary_rows'];
$totalPayroll = array_sum(array_map(fn($row) => (float) $row['total_pay'], $summaryRows));
$autoPrint = ($_GET['auto_print'] ?? '') === '1';

page_header((string) $selectedPeriod['name'] . ' Payroll Summary');
?>
<?php if ($autoPrint): ?>
    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
<?php endif; ?>
<main class="shell">
    <section class="panel print-hidden">
        <h1>Payroll Print</h1>
        <p>Print this report or choose Save as PDF in the print window.</p>
        <?php election_navigation('payroll'); ?>
        <div class="actions">
            <button type="button" onclick="window.print()">Print PDF</button>
            <a class="button secondary" href="<?= e(url('departments/election/payroll.php?election_period_id=' . $selectedPeriodId)) ?>">Back to payroll</a>
        </div>
    </section>

    <section class="panel printable-roster" style="margin-top: 18px;">
        <div class="roster-header">
            <div>
                <p class="meta"><?= e(format_display_date(date('Y-m-d'))) ?></p>
                <h1><?= e($selectedPeriod['name']) ?> Payroll Summary</h1>
                <p>
                    Training: <?= e(election_payroll_money((float) $settings['training_rate'])) ?> per completed class,
                    cap <?= e(election_payroll_money((float) $settings['training_cap'])) ?>.
                    Mileage: <?= e(number_format((float) $settings['mileage_rate'], 3)) ?> per mile over <?= e(number_format((float) ($settings['mileage_minimum_miles'] ?? 20), 2)) ?> round-trip miles.
                </p>
            </div>
            <div>
                <p><strong>Total payroll</strong></p>
                <h2><?= e(election_payroll_money($totalPayroll)) ?></h2>
                <p><?= e((string) count($summaryRows)) ?> people</p>
            </div>
        </div>

        <table class="table roster-table payroll-print-table">
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
                        <td><?= e(election_person_name($row)) ?></td>
                        <td>
                            <?= e($row['mailing_address'] ?: 'No address') ?><br>
                            <?= e(trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? '') . ' ' . ($row['zip_code'] ?? ''), ', ')) ?>
                        </td>
                        <td><?= e($row['positions']) ?></td>
                        <td><?= e(election_payroll_money((float) $row['election_day_pay'])) ?></td>
                        <td>
                            <?= e(election_payroll_money((float) $row['training_pay'])) ?><br>
                            <span class="meta"><?= e((string) $row['training_completed_count']) ?> complete</span>
                        </td>
                        <td>
                            <?= e(election_payroll_money((float) $row['mileage_pay'])) ?><br>
                            <span class="meta"><?= e(number_format((float) $row['training_miles_round_trip'], 2)) ?> miles</span>
                        </td>
                        <td><strong><?= e(election_payroll_money((float) $row['total_pay'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$summaryRows): ?>
                    <tr><td colspan="7">No payroll records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
