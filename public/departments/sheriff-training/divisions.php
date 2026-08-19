<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$editId = (int) ($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0);
$editDivision = null;

if ($editId > 0) {
    $statement = db()->prepare('SELECT * FROM sheriff_training_divisions WHERE id = :id');
    $statement->execute(['id' => $editId]);
    $editDivision = $statement->fetch();
    if (!$editDivision) {
        flash('error', 'Division not found.');
        redirect_to('departments/sheriff-training/divisions.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        flash('error', 'Division name is required.');
        redirect_to('departments/sheriff-training/divisions.php' . ($editId > 0 ? '?edit_id=' . $editId : ''));
    }

    $duplicateStatement = db()->prepare('SELECT id FROM sheriff_training_divisions WHERE name = :name AND id <> :id LIMIT 1');
    $duplicateStatement->execute(['name' => $name, 'id' => $editId]);
    if ($duplicateStatement->fetchColumn()) {
        flash('error', 'That division name already exists.');
        redirect_to('departments/sheriff-training/divisions.php' . ($editId > 0 ? '?edit_id=' . $editId : ''));
    }

    if ($editId > 0) {
        $oldName = (string) $editDivision['name'];
        db()->beginTransaction();

        $statement = db()->prepare(
            'UPDATE sheriff_training_divisions
             SET name = :name,
                 sort_order = :sort_order,
                 is_active = :is_active
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $editId,
            'name' => $name,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);

        if ($oldName !== $name) {
            $statement = db()->prepare(
                'UPDATE sheriff_training_officers
                 SET division = :new_name
                 WHERE division = :old_name'
            );
            $statement->execute([
                'new_name' => $name,
                'old_name' => $oldName,
            ]);
        }

        db()->commit();
        audit_event('updated', 'sheriff_training_division', (string) $editId, [
            'name' => $name,
            'old_name' => $oldName,
        ]);
        flash('success', 'Division updated.');
    } else {
        $statement = db()->prepare(
            'INSERT INTO sheriff_training_divisions (name, sort_order, is_active)
             VALUES (:name, :sort_order, :is_active)'
        );
        $statement->execute([
            'name' => $name,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
        $divisionId = (int) db()->lastInsertId();
        audit_event('created', 'sheriff_training_division', (string) $divisionId, ['name' => $name]);
        flash('success', 'Division added.');
    }

    redirect_to('departments/sheriff-training/divisions.php');
}

$divisions = db()->query(
    'SELECT sheriff_training_divisions.*,
            COALESCE(officer_summary.officer_count, 0) AS officer_count
     FROM sheriff_training_divisions
     LEFT JOIN (
        SELECT division, COUNT(*) AS officer_count
        FROM sheriff_training_officers
        WHERE division IS NOT NULL AND division <> ""
        GROUP BY division
     ) officer_summary ON officer_summary.division = sheriff_training_divisions.name
     ORDER BY sheriff_training_divisions.sort_order, sheriff_training_divisions.name'
)->fetchAll();

$formDivision = $editDivision ?: [
    'id' => 0,
    'name' => '',
    'sort_order' => 0,
    'is_active' => 1,
];

page_header('Sheriff Training Divisions');
?>
<main class="shell">
    <section class="panel">
        <h1>Divisions</h1>
        <p>Manage the division names available when adding or editing Sheriff Training officers.</p>
        <?php sheriff_training_navigation('divisions'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1><?= $editDivision ? 'Edit Division' : 'Add Division' ?></h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="edit_id" value="<?= e((string) $formDivision['id']) ?>">
            <label>
                Division name
                <input name="name" value="<?= e($formDivision['name']) ?>" required>
            </label>
            <label>
                Sort order
                <input type="number" name="sort_order" min="0" value="<?= e((string) $formDivision['sort_order']) ?>">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_active" value="1" <?= (int) $formDivision['is_active'] === 1 ? 'checked' : '' ?>>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>
                    Active division
                    <small>Show this division when assigning officers.</small>
                </span>
            </label>
            <div class="actions">
                <button type="submit"><?= $editDivision ? 'Save division' : 'Add division' ?></button>
                <?php if ($editDivision): ?>
                    <a class="button secondary" href="<?= e(url('departments/sheriff-training/divisions.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Division List</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Division</th>
                    <th>Sort Order</th>
                    <th>Officers</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($divisions as $division): ?>
                    <tr>
                        <td data-label="Division"><?= e($division['name']) ?></td>
                        <td data-label="Sort Order"><?= e((string) $division['sort_order']) ?></td>
                        <td data-label="Officers"><?= e((string) (int) $division['officer_count']) ?></td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $division['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $division['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <a class="button compact-button secondary" href="<?= e(url('departments/sheriff-training/divisions.php?edit_id=' . $division['id'])) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$divisions): ?>
                    <tr><td colspan="5">No divisions have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
