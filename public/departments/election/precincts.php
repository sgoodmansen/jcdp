<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();

$editPrecinctId = (int) ($_GET['edit_precinct'] ?? 0);
$editPrecinct = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_precinct') {
    $precinctId = (int) ($_POST['precinct_id'] ?? 0);
    $params = [
        'name' => trim($_POST['name'] ?? ''),
        'location_name' => title_case_company($_POST['location_name'] ?? ''),
        'street_address' => title_case_address($_POST['street_address'] ?? ''),
        'city' => title_case_name($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'zip_code' => trim($_POST['zip_code'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($precinctId > 0) {
        $params['id'] = $precinctId;
        $statement = db()->prepare(
            'UPDATE election_precincts
             SET name = :name,
                 location_name = :location_name,
                 street_address = :street_address,
                 city = :city,
                 state = :state,
                 zip_code = :zip_code,
                 notes = :notes,
                 is_active = :is_active
             WHERE id = :id'
        );
        $statement->execute($params);
        audit_event('updated', 'election_precinct', (string) $precinctId, ['name' => $params['name']]);
    } else {
        $statement = db()->prepare(
            'INSERT INTO election_precincts (name, location_name, street_address, city, state, zip_code, notes, is_active)
             VALUES (:name, :location_name, :street_address, :city, :state, :zip_code, :notes, 1)'
        );
        unset($params['is_active']);
        $statement->execute($params);
        $precinctId = (int) db()->lastInsertId();
        audit_event('created', 'election_precinct', (string) $precinctId, ['name' => $params['name']]);
    }

    flash('success', 'Precinct saved.');
    redirect_to('departments/election/precincts.php');
}

$precincts = election_precincts(false);

if ($editPrecinctId > 0) {
    $statement = db()->prepare('SELECT * FROM election_precincts WHERE id = :id');
    $statement->execute(['id' => $editPrecinctId]);
    $editPrecinct = $statement->fetch() ?: null;
}

page_header('Election Precincts');
?>
<main class="shell">
    <section class="panel">
        <h1>Precincts</h1>
        <p>Manage precinct names, polling locations, and active status.</p>
        <?php election_navigation('precincts'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1><?= $editPrecinct ? 'Edit Precinct' : 'Add Precinct' ?></h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_precinct">
            <input type="hidden" name="precinct_id" value="<?= e((string) ($editPrecinct['id'] ?? 0)) ?>">
            <label>
                Precinct name
                <input name="name" value="<?= e($editPrecinct['name'] ?? '') ?>" required>
            </label>
            <label>
                Location name
                <input name="location_name" value="<?= e($editPrecinct['location_name'] ?? '') ?>" placeholder="Elementary School" required>
            </label>
            <label>
                Street address
                <input name="street_address" value="<?= e($editPrecinct['street_address'] ?? ($editPrecinct['building_address'] ?? '')) ?>" required>
            </label>
            <label>
                City
                <input name="city" value="<?= e($editPrecinct['city'] ?? '') ?>" required>
            </label>
            <label>
                State
                <select name="state" required>
                    <?php state_options($editPrecinct['state'] ?? 'ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code" value="<?= e($editPrecinct['zip_code'] ?? '') ?>" required>
            </label>
            <?php if ($editPrecinct): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) $editPrecinct['is_active'] === 1 ? 'checked' : '' ?>>
                    Active precinct
                </label>
            <?php endif; ?>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($editPrecinct['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $editPrecinct ? 'Save precinct' : 'Add precinct' ?></button>
                <?php if ($editPrecinct): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/precincts.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Precincts</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Precinct</th>
                    <th>Election location</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($precincts as $precinct): ?>
                    <tr>
                        <td data-label="Precinct"><?= e($precinct['name']) ?></td>
                        <td data-label="Election location"><?= nl2br(e(election_precinct_location($precinct))) ?></td>
                        <td data-label="Status"><?= (int) $precinct['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                        <td data-label="Actions"><a class="button secondary compact-button" href="<?= e(url('departments/election/precincts.php?edit_precinct=' . $precinct['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$precincts): ?>
                    <tr><td colspan="4">No precincts have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
