<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();

$editPositionId = (int) ($_GET['edit_position'] ?? 0);
$editPosition = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_position') {
    $positionId = (int) ($_POST['position_id'] ?? 0);
    $params = [
        'name' => trim($_POST['name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        'is_chief_judge' => isset($_POST['is_chief_judge']) ? 1 : 0,
        'is_assistant_chief_judge' => isset($_POST['is_assistant_chief_judge']) ? 1 : 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($positionId > 0) {
        $params['id'] = $positionId;
        $statement = db()->prepare(
            'UPDATE election_positions
             SET name = :name, description = :description, sort_order = :sort_order,
                 is_chief_judge = :is_chief_judge, is_assistant_chief_judge = :is_assistant_chief_judge,
                 is_active = :is_active
             WHERE id = :id'
        );
        $statement->execute($params);
        audit_event('updated', 'election_position', (string) $positionId, ['name' => $params['name']]);
    } else {
        $statement = db()->prepare(
            'INSERT INTO election_positions (name, description, sort_order, is_chief_judge, is_assistant_chief_judge, is_active)
             VALUES (:name, :description, :sort_order, :is_chief_judge, :is_assistant_chief_judge, 1)'
        );
        unset($params['is_active']);
        $statement->execute($params);
        $positionId = (int) db()->lastInsertId();
        audit_event('created', 'election_position', (string) $positionId, ['name' => $params['name']]);
    }

    flash('success', 'Position saved.');
    redirect_to('departments/election/positions.php');
}

$positions = election_positions(false);

if ($editPositionId > 0) {
    $statement = db()->prepare('SELECT * FROM election_positions WHERE id = :id');
    $statement->execute(['id' => $editPositionId]);
    $editPosition = $statement->fetch() ?: null;
}

page_header('Election Positions');
?>
<main class="shell">
    <section class="panel">
        <h1>Positions</h1>
        <p>Manage worker positions and Chief Judge permission flags.</p>
        <?php election_navigation('positions'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1><?= $editPosition ? 'Edit Position' : 'Add Position' ?></h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_position">
            <input type="hidden" name="position_id" value="<?= e((string) ($editPosition['id'] ?? 0)) ?>">
            <label>
                Position name
                <input name="name" value="<?= e($editPosition['name'] ?? '') ?>" required>
            </label>
            <label>
                Sort order
                <input type="number" name="sort_order" value="<?= e((string) ($editPosition['sort_order'] ?? 90)) ?>" min="0">
            </label>
            <label class="check-label">
                <input type="checkbox" name="is_chief_judge" <?= (int) ($editPosition['is_chief_judge'] ?? 0) === 1 ? 'checked' : '' ?>>
                Chief Judge permissions
            </label>
            <label class="check-label">
                <input type="checkbox" name="is_assistant_chief_judge" <?= (int) ($editPosition['is_assistant_chief_judge'] ?? 0) === 1 ? 'checked' : '' ?>>
                Assistant Chief Judge permissions
            </label>
            <?php if ($editPosition): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) $editPosition['is_active'] === 1 ? 'checked' : '' ?>>
                    Active position
                </label>
            <?php endif; ?>
            <label class="span-2">
                Description
                <textarea name="description"><?= e($editPosition['description'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $editPosition ? 'Save position' : 'Add position' ?></button>
                <?php if ($editPosition): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/positions.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Positions</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Permissions</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($positions as $position): ?>
                    <tr>
                        <td data-label="Position"><?= e($position['name']) ?></td>
                        <td data-label="Permissions">
                            <?= (int) $position['is_chief_judge'] === 1 || (int) $position['is_assistant_chief_judge'] === 1 ? 'Chief Judge worker management' : 'Worker access' ?>
                        </td>
                        <td data-label="Status"><?= (int) $position['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                        <td data-label="Actions"><a class="button secondary compact-button" href="<?= e(url('departments/election/positions.php?edit_position=' . $position['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$positions): ?>
                    <tr><td colspan="4">No positions have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
