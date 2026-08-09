<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_payroll_setup();

$periods = db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll();
$positions = election_positions(false);

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

if ($selectedPeriodId > 0) {
    election_payroll_ensure_period_defaults($selectedPeriodId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPeriodId > 0) {
    $action = $_POST['action'] ?? '';
    $isLocked = election_payroll_period_is_locked($selectedPeriodId);

    if ($isLocked) {
        flash('error', 'Payroll is locked for this election period. Unlock payroll before changing rates.');
        redirect_to('departments/election/payroll-setup.php?election_period_id=' . $selectedPeriodId);
    }

    if ($action === 'copy_rates') {
        $copyFromPeriodId = (int) ($_POST['copy_from_period_id'] ?? 0);
        if ($copyFromPeriodId > 0 && $copyFromPeriodId !== $selectedPeriodId) {
            election_payroll_ensure_period_defaults($copyFromPeriodId);

            $statement = db()->prepare(
                'UPDATE election_payroll_settings target_settings
                 INNER JOIN election_payroll_settings source_settings ON source_settings.election_period_id = :source_period_id
                 SET target_settings.training_rate = source_settings.training_rate,
                     target_settings.training_cap = source_settings.training_cap,
                     target_settings.mileage_rate = source_settings.mileage_rate,
                     target_settings.mileage_minimum_miles = source_settings.mileage_minimum_miles,
                     target_settings.courthouse_address = source_settings.courthouse_address,
                     target_settings.updated_by_user_id = :user_id
                 WHERE target_settings.election_period_id = :target_period_id'
            );
            $statement->execute([
                'source_period_id' => $copyFromPeriodId,
                'target_period_id' => $selectedPeriodId,
                'user_id' => current_user()['id'] ?? null,
            ]);

            $rates = db()->prepare('SELECT * FROM election_payroll_position_rates WHERE election_period_id = :election_period_id');
            $rates->execute(['election_period_id' => $copyFromPeriodId]);
            $saveRate = db()->prepare(
                'INSERT INTO election_payroll_position_rates (election_period_id, position_id, full_day_rate, updated_by_user_id)
                 VALUES (:election_period_id, :position_id, :full_day_rate, :user_id)
                 ON DUPLICATE KEY UPDATE full_day_rate = VALUES(full_day_rate), updated_by_user_id = VALUES(updated_by_user_id)'
            );
            foreach ($rates->fetchAll() as $rate) {
                $saveRate->execute([
                    'election_period_id' => $selectedPeriodId,
                    'position_id' => (int) $rate['position_id'],
                    'full_day_rate' => (float) $rate['full_day_rate'],
                    'user_id' => current_user()['id'] ?? null,
                ]);
            }

            audit_event('copied_payroll_rates', 'election_period', (string) $selectedPeriodId, ['from_period_id' => $copyFromPeriodId]);
            flash('success', 'Payroll rates copied.');
        }

        redirect_to('departments/election/payroll-setup.php?election_period_id=' . $selectedPeriodId);
    }

    if ($action === 'save_rates') {
        $statement = db()->prepare(
            'UPDATE election_payroll_settings
                 SET training_rate = :training_rate,
                     training_cap = :training_cap,
                     mileage_rate = :mileage_rate,
                     mileage_minimum_miles = :mileage_minimum_miles,
                     courthouse_address = :courthouse_address,
                     updated_by_user_id = :user_id
             WHERE election_period_id = :election_period_id'
        );
        $statement->execute([
            'training_rate' => max(0, (float) ($_POST['training_rate'] ?? 0)),
            'training_cap' => max(0, (float) ($_POST['training_cap'] ?? 0)),
            'mileage_rate' => max(0, (float) ($_POST['mileage_rate'] ?? 0)),
            'mileage_minimum_miles' => max(0, (float) ($_POST['mileage_minimum_miles'] ?? 20)),
            'courthouse_address' => trim($_POST['courthouse_address'] ?? ELECTION_PAYROLL_COURTHOUSE_ADDRESS),
            'user_id' => current_user()['id'] ?? null,
            'election_period_id' => $selectedPeriodId,
        ]);

        $saveRate = db()->prepare(
            'INSERT INTO election_payroll_position_rates (election_period_id, position_id, full_day_rate, updated_by_user_id)
             VALUES (:election_period_id, :position_id, :full_day_rate, :user_id)
             ON DUPLICATE KEY UPDATE full_day_rate = VALUES(full_day_rate), updated_by_user_id = VALUES(updated_by_user_id)'
        );
        foreach ($positions as $position) {
            $positionId = (int) $position['id'];
            $saveRate->execute([
                'election_period_id' => $selectedPeriodId,
                'position_id' => $positionId,
                'full_day_rate' => max(0, (float) ($_POST['position_rates'][$positionId] ?? 0)),
                'user_id' => current_user()['id'] ?? null,
            ]);
        }

        audit_event('updated_payroll_rates', 'election_period', (string) $selectedPeriodId);
        flash('success', 'Payroll setup saved.');
        redirect_to('departments/election/payroll-setup.php?election_period_id=' . $selectedPeriodId);
    }
}

$selectedPeriod = null;
foreach ($periods as $period) {
    if ((int) $period['id'] === $selectedPeriodId) {
        $selectedPeriod = $period;
        break;
    }
}

$settings = $selectedPeriodId > 0 ? election_payroll_settings($selectedPeriodId) : [];
$rateStatement = db()->prepare('SELECT * FROM election_payroll_position_rates WHERE election_period_id = :election_period_id');
$rateStatement->execute(['election_period_id' => $selectedPeriodId]);
$ratesByPosition = [];
foreach ($rateStatement->fetchAll() as $rate) {
    $ratesByPosition[(int) $rate['position_id']] = $rate;
}

page_header('Payroll Setup');
?>
<main class="shell">
    <section class="panel">
        <h1>Payroll Setup</h1>
        <p>Set election-day rates, training pay, and mileage settings for each election period.</p>
        <?php election_navigation('payroll-setup'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Election Period</h1>
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
            <div class="actions">
                <button type="submit">View setup</button>
                <a class="button secondary" href="<?= e(url('departments/election/payroll.php?election_period_id=' . $selectedPeriodId)) ?>">Open summary</a>
            </div>
        </form>
    </section>

    <?php if ($selectedPeriod): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>Rates</h1>
                    <p class="muted"><?= e($selectedPeriod['name']) ?><?= (int) ($settings['is_locked'] ?? 0) === 1 ? ' - payroll locked' : '' ?></p>
                </div>
                <span class="badge <?= (int) ($settings['is_locked'] ?? 0) === 1 ? 'badge-warning' : 'badge-success' ?>">
                    <?= (int) ($settings['is_locked'] ?? 0) === 1 ? 'Locked' : 'Editable' ?>
                </span>
            </div>

            <form class="form compact-form" method="post">
                <input type="hidden" name="action" value="save_rates">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <label>
                    Training pay per completed class
                    <input type="number" step="0.01" min="0" name="training_rate" value="<?= e((string) ($settings['training_rate'] ?? '20.00')) ?>" required>
                </label>
                <label>
                    Training pay maximum
                    <input type="number" step="0.01" min="0" name="training_cap" value="<?= e((string) ($settings['training_cap'] ?? '60.00')) ?>" required>
                </label>
                <label>
                    Mileage rate
                    <input type="number" step="0.001" min="0" name="mileage_rate" value="<?= e((string) ($settings['mileage_rate'] ?? '0.000')) ?>" required>
                </label>
                <label>
                    Minimum round-trip miles for mileage pay
                    <input type="number" step="0.01" min="0" name="mileage_minimum_miles" value="<?= e((string) ($settings['mileage_minimum_miles'] ?? '20.00')) ?>" required>
                </label>
                <label>
                    Courthouse address
                    <input name="courthouse_address" value="<?= e((string) ($settings['courthouse_address'] ?? ELECTION_PAYROLL_COURTHOUSE_ADDRESS)) ?>" required>
                </label>

                <div class="span-2">
                    <h2>Election Day Full-Day Rates</h2>
                    <table class="table mobile-card-table">
                        <thead>
                            <tr>
                                <th>Position</th>
                                <th>Full-day rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($positions as $position): ?>
                                <?php $rate = $ratesByPosition[(int) $position['id']]['full_day_rate'] ?? election_payroll_default_position_rate((string) $position['name']); ?>
                                <tr>
                                    <td data-label="Position"><?= e($position['name']) ?></td>
                                    <td data-label="Full-day rate">
                                        <input type="number" step="0.01" min="0" name="position_rates[<?= e((string) $position['id']) ?>]" value="<?= e((string) $rate) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="actions span-2">
                    <button type="submit" <?= (int) ($settings['is_locked'] ?? 0) === 1 ? 'disabled' : '' ?>>Save payroll setup</button>
                </div>
            </form>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Copy From Previous Election</h1>
            <form class="form compact-form" method="post">
                <input type="hidden" name="action" value="copy_rates">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <label>
                    Copy rates from
                    <select name="copy_from_period_id" required>
                        <option value="">Select election</option>
                        <?php foreach ($periods as $period): ?>
                            <?php if ((int) $period['id'] !== $selectedPeriodId): ?>
                                <option value="<?= e((string) $period['id']) ?>"><?= e($period['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="actions">
                    <button type="submit" class="secondary" <?= (int) ($settings['is_locked'] ?? 0) === 1 ? 'disabled' : '' ?>>Copy rates</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
