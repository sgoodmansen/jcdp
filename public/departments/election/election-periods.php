<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();

$editPeriodId = (int) ($_GET['edit_period'] ?? 0);
$editPeriod = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_period') {
        $periodId = (int) ($_POST['period_id'] ?? 0);
        $params = [
            'name' => trim($_POST['name'] ?? ''),
            'starts_on' => $_POST['starts_on'] ?? date('Y-m-d'),
            'ends_on' => $_POST['ends_on'] ?? date('Y-m-d'),
            'notes' => trim($_POST['notes'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($periodId > 0) {
            $params['id'] = $periodId;
            $statement = db()->prepare(
                'UPDATE election_periods
                 SET name = :name, starts_on = :starts_on, ends_on = :ends_on, notes = :notes, is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($params);
            audit_event('updated', 'election_period', (string) $periodId, ['name' => $params['name']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO election_periods (name, starts_on, ends_on, notes, is_active)
                 VALUES (:name, :starts_on, :ends_on, :notes, 1)'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $periodId = (int) db()->lastInsertId();
            audit_event('created', 'election_period', (string) $periodId, ['name' => $params['name']]);
        }

        flash('success', 'Election period saved.');
        redirect_to('departments/election/election-periods.php');
    }
}

$periods = db()->query('SELECT * FROM election_periods ORDER BY starts_on DESC, name')->fetchAll();

if ($editPeriodId > 0) {
    $statement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
    $statement->execute(['id' => $editPeriodId]);
    $editPeriod = $statement->fetch() ?: null;
}

page_header('Election Periods');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Periods</h1>
        <p>Add election periods, edit dates, and close completed elections.</p>
        <?php election_navigation('election-periods'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1><?= $editPeriod ? 'Edit Election Period' : 'Add Election Period' ?></h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_period">
            <input type="hidden" name="period_id" value="<?= e((string) ($editPeriod['id'] ?? 0)) ?>">
            <label>
                Election name
                <input name="name" value="<?= e($editPeriod['name'] ?? '') ?>" placeholder="2026 General Election" required>
            </label>
            <label>
                Starts on
                <input type="date" name="starts_on" value="<?= e($editPeriod['starts_on'] ?? date('Y-m-d')) ?>" required>
            </label>
            <label>
                Ends on
                <input type="date" name="ends_on" value="<?= e($editPeriod['ends_on'] ?? date('Y-m-d')) ?>" required>
            </label>
            <?php if ($editPeriod): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) $editPeriod['is_active'] === 1 ? 'checked' : '' ?>>
                    Active election
                </label>
            <?php endif; ?>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($editPeriod['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $editPeriod ? 'Save election' : 'Add election' ?></button>
                <?php if ($editPeriod): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/election-periods.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Election Periods</h1>
        <table class="table mobile-card-table election-period-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($periods as $period): ?>
                    <tr>
                        <td data-label="Name"><?= e($period['name']) ?></td>
                        <td data-label="Dates"><?= e(format_display_date($period['starts_on'])) ?> to <?= e(format_display_date($period['ends_on'])) ?></td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $period['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $period['is_active'] === 1 ? 'Active' : 'Closed' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions election-period-actions">
                                <a class="button secondary compact-button" href="<?= e(url('departments/election/election-periods.php?edit_period=' . $period['id'])) ?>">Edit</a>
                                <?php if ((int) $period['is_active'] === 1): ?>
                                    <a class="button secondary compact-button" href="<?= e(url('departments/election/close-period.php?id=' . (int) $period['id'])) ?>">Close election</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$periods): ?>
                    <tr><td colspan="4">No election periods have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
