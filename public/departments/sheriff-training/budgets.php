<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $fiscalYear = (int) ($_POST['fiscal_year'] ?? 0);
    $trainingBudget = sheriff_training_decimal($_POST['training_budget'] ?? '0');
    $lodgingBudget = sheriff_training_decimal($_POST['lodging_budget'] ?? '0');
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $dates = sheriff_training_fiscal_year_dates($fiscalYear);

    if ($fiscalYear < 2000 || $fiscalYear > 2100) {
        flash('error', 'Enter a valid fiscal year.');
        redirect_to('departments/sheriff-training/budgets.php');
    }

    if ($id > 0) {
        $statement = db()->prepare(
            'UPDATE sheriff_training_fiscal_years
             SET fiscal_year = :fiscal_year,
                 label = :label,
                 starts_on = :starts_on,
                 ends_on = :ends_on,
                 training_budget = :training_budget,
                 lodging_budget = :lodging_budget,
                 is_active = :is_active,
                 notes = :notes
             WHERE id = :id'
        );
        $params = ['id' => $id];
    } else {
        $statement = db()->prepare(
            'INSERT INTO sheriff_training_fiscal_years
                (fiscal_year, label, starts_on, ends_on, training_budget, lodging_budget, is_active, notes)
             VALUES
                (:fiscal_year, :label, :starts_on, :ends_on, :training_budget, :lodging_budget, :is_active, :notes)
             ON DUPLICATE KEY UPDATE
                training_budget = VALUES(training_budget),
                lodging_budget = VALUES(lodging_budget),
                is_active = VALUES(is_active),
                notes = VALUES(notes)'
        );
        $params = [];
    }

    $statement->execute($params + [
        'fiscal_year' => $fiscalYear,
        'label' => 'FY ' . $fiscalYear,
        'starts_on' => $dates['starts_on'],
        'ends_on' => $dates['ends_on'],
        'training_budget' => $trainingBudget,
        'lodging_budget' => $lodgingBudget,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'notes' => $notes,
    ]);

    audit_event($id > 0 ? 'updated' : 'created', 'sheriff_training_fiscal_year', (string) ($id ?: $fiscalYear));
    flash('success', 'Fiscal budget saved.');
    redirect_to('departments/sheriff-training/budgets.php');
}

$years = db()->query('SELECT * FROM sheriff_training_fiscal_years ORDER BY fiscal_year DESC')->fetchAll();
$editId = (int) ($_GET['edit_id'] ?? 0);
$editYear = null;
foreach ($years as $year) {
    if ((int) $year['id'] === $editId) {
        $editYear = $year;
        break;
    }
}

$nextFiscalYear = sheriff_training_fiscal_year_for_date();
if ($years) {
    $nextFiscalYear = max($nextFiscalYear, (int) $years[0]['fiscal_year'] + 1);
}

$formYear = $editYear ?: [
    'id' => 0,
    'fiscal_year' => $nextFiscalYear,
    'training_budget' => '0.00',
    'lodging_budget' => '0.00',
    'is_active' => 1,
    'notes' => '',
];

page_header('Sheriff Training Budgets');
?>
<main class="shell">
    <section class="panel">
        <h1>Fiscal Budgets</h1>
        <p>Set annual training and lodging budgets. Fiscal years run October 1 through September 30.</p>
        <?php sheriff_training_navigation('budgets'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1><?= $editYear ? 'Edit Fiscal Budget' : 'Add Fiscal Budget' ?></h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $formYear['id']) ?>">
            <label>
                Fiscal year ending
                <input type="number" name="fiscal_year" min="2000" max="2100" value="<?= e((string) $formYear['fiscal_year']) ?>" required>
            </label>
            <label>
                Training class budget
                <input name="training_budget" inputmode="decimal" value="<?= e((string) $formYear['training_budget']) ?>" required>
            </label>
            <label>
                Lodging budget
                <input name="lodging_budget" inputmode="decimal" value="<?= e((string) $formYear['lodging_budget']) ?>" required>
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_active" value="1" <?= (int) $formYear['is_active'] === 1 ? 'checked' : '' ?>>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>
                    Active budget year
                    <small>Show this fiscal year when entering training requests.</small>
                </span>
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($formYear['notes']) ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save budget</button>
                <?php if ($editYear): ?>
                    <a class="button secondary" href="<?= e(url('departments/sheriff-training/budgets.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Budget Years</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Fiscal Year</th>
                    <th>Dates</th>
                    <th>Training</th>
                    <th>Lodging</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($years as $year): ?>
                    <?php $summary = sheriff_training_budget_summary((int) $year['id']); ?>
                    <tr>
                        <td data-label="Fiscal Year"><?= e($year['label']) ?><?= (int) $year['is_active'] === 0 ? ' (Inactive)' : '' ?></td>
                        <td data-label="Dates"><?= e(format_display_date($year['starts_on'])) ?> to <?= e(format_display_date($year['ends_on'])) ?></td>
                        <td data-label="Training"><?= e(sheriff_training_money($summary['training_used'])) ?> used / <?= e(sheriff_training_money($year['training_budget'])) ?><br><span class="meta"><?= e(sheriff_training_money($summary['training_remaining'])) ?> remaining</span></td>
                        <td data-label="Lodging"><?= e(sheriff_training_money($summary['lodging_used'])) ?> used / <?= e(sheriff_training_money($year['lodging_budget'])) ?><br><span class="meta"><?= e(sheriff_training_money($summary['lodging_remaining'])) ?> remaining</span></td>
                        <td data-label="Actions"><a class="button compact-button secondary" href="<?= e(url('departments/sheriff-training/budgets.php?edit_id=' . $year['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$years): ?>
                    <tr><td colspan="5">No fiscal budgets have been entered yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
