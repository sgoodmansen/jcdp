<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_manager();

$lookupConfigs = [
    'activity_types' => ['label' => 'Activity Types', 'table' => 'k9_activity_types'],
    'training_areas' => ['label' => 'Training Areas', 'table' => 'k9_training_areas'],
    'indications' => ['label' => 'Indications', 'table' => 'k9_indications'],
    'locations' => ['label' => 'Locations', 'table' => 'k9_locations'],
    'training_aids' => ['label' => 'Training Aids', 'table' => 'k9_training_aids'],
    'vet_offices' => ['label' => 'Vet Offices', 'table' => 'k9_vet_offices'],
    'vet_doctors' => ['label' => 'Doctors', 'table' => 'k9_vet_doctors'],
    'expense_categories' => ['label' => 'Expense Categories', 'table' => 'k9_expense_categories'],
    'incident_types' => ['label' => 'Incident Types', 'table' => 'k9_incident_types'],
    'assisting_agencies' => ['label' => 'Assisting Agencies', 'table' => 'k9_assisting_agencies'],
    'deployment_outcomes' => ['label' => 'Deployment Outcomes', 'table' => 'k9_deployment_outcomes'],
];
$trainingAidCategories = ['Bite suit', 'Toy', 'Treat', 'Drug', 'Other'];

$selectedLookup = $_GET['list'] ?? 'locations';
if (!isset($lookupConfigs[$selectedLookup])) {
    $selectedLookup = 'locations';
}
$selectedConfig = $lookupConfigs[$selectedLookup];
$editId = max(0, (int) ($_GET['edit'] ?? 0));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedLookup = $_POST['list'] ?? $selectedLookup;
    if (!isset($lookupConfigs[$postedLookup])) {
        redirect_to('departments/k9/setup.php');
    }
    $postedConfig = $lookupConfigs[$postedLookup];
    $table = $postedConfig['table'];
    $postedEditId = max(0, (int) ($_POST['edit_id'] ?? 0));
    $name = trim($_POST['name'] ?? '');
    $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 100));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        flash('error', 'Enter a name before saving.');
        redirect_to('departments/k9/setup.php?list=' . urlencode($postedLookup));
    }

    try {
        if ($postedLookup === 'locations') {
            $values = [
                'name' => $name,
                'address_description' => trim($_POST['address_description'] ?? ''),
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ];

            if ($postedEditId > 0) {
                $statement = db()->prepare(
                    "UPDATE $table
                     SET name = :name, address_description = :address_description, sort_order = :sort_order, is_active = :is_active
                     WHERE id = :id"
                );
                $statement->execute($values + ['id' => $postedEditId]);
            } else {
                $statement = db()->prepare(
                    "INSERT INTO $table (name, address_description, sort_order, is_active)
                     VALUES (:name, :address_description, :sort_order, :is_active)
                     ON DUPLICATE KEY UPDATE address_description = VALUES(address_description), sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
                );
                $statement->execute($values);
            }
        } elseif ($postedLookup === 'training_aids') {
            $values = [
                'name' => $name,
                'category' => trim($_POST['category'] ?? ''),
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ];

            if ($postedEditId > 0) {
                $statement = db()->prepare(
                    "UPDATE $table
                     SET name = :name, category = :category, sort_order = :sort_order, is_active = :is_active
                     WHERE id = :id"
                );
                $statement->execute($values + ['id' => $postedEditId]);
            } else {
                $statement = db()->prepare(
                    "INSERT INTO $table (name, category, sort_order, is_active)
                     VALUES (:name, :category, :sort_order, :is_active)
                     ON DUPLICATE KEY UPDATE category = VALUES(category), sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
                );
                $statement->execute($values);
            }
        } elseif ($postedLookup === 'vet_doctors') {
            $vetOfficeId = (int) ($_POST['vet_office_id'] ?? 0);
            $officeStatement = db()->prepare('SELECT id FROM k9_vet_offices WHERE id = :id LIMIT 1');
            $officeStatement->execute(['id' => $vetOfficeId]);
            if (!$officeStatement->fetchColumn()) {
                flash('error', 'Select a vet office before saving the doctor.');
                redirect_to('departments/k9/setup.php?list=' . urlencode($postedLookup) . ($postedEditId > 0 ? '&edit=' . $postedEditId : ''));
            }

            $values = [
                'vet_office_id' => $vetOfficeId,
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ];

            if ($postedEditId > 0) {
                $statement = db()->prepare(
                    "UPDATE $table
                     SET vet_office_id = :vet_office_id, name = :name, sort_order = :sort_order, is_active = :is_active
                     WHERE id = :id"
                );
                $statement->execute($values + ['id' => $postedEditId]);
            } else {
                $statement = db()->prepare(
                    "INSERT INTO $table (vet_office_id, name, sort_order, is_active)
                     VALUES (:vet_office_id, :name, :sort_order, :is_active)
                     ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
                );
                $statement->execute($values);
            }
        } else {
            $values = [
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ];

            if ($postedEditId > 0) {
                $statement = db()->prepare(
                    "UPDATE $table
                     SET name = :name, sort_order = :sort_order, is_active = :is_active
                     WHERE id = :id"
                );
                $statement->execute($values + ['id' => $postedEditId]);
            } else {
                $statement = db()->prepare(
                    "INSERT INTO $table (name, sort_order, is_active)
                     VALUES (:name, :sort_order, :is_active)
                     ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
                );
                $statement->execute($values);
            }
        }
    } catch (PDOException $exception) {
        flash('error', 'That name is already being used. Choose a different name or edit the existing value.');
        redirect_to('departments/k9/setup.php?list=' . urlencode($postedLookup) . ($postedEditId > 0 ? '&edit=' . $postedEditId : ''));
    }

    flash('success', $postedConfig['label'] . ' saved.');
    redirect_to('departments/k9/setup.php?list=' . urlencode($postedLookup));
}

$vetOffices = k9_lookup_options('k9_vet_offices', 'name', false);
$rows = k9_lookup_options($selectedConfig['table'], 'name', false);
if ($selectedLookup === 'vet_doctors') {
    $rows = db()->query(
        'SELECT k9_vet_doctors.*, k9_vet_offices.name AS vet_office_name
         FROM k9_vet_doctors
         INNER JOIN k9_vet_offices ON k9_vet_offices.id = k9_vet_doctors.vet_office_id
         ORDER BY k9_vet_offices.sort_order, k9_vet_offices.name, k9_vet_doctors.sort_order, k9_vet_doctors.name'
    )->fetchAll();
}
$editRow = null;
if ($editId > 0) {
    foreach ($rows as $row) {
        if ((int) $row['id'] === $editId) {
            $editRow = $row;
            break;
        }
    }

    if (!$editRow) {
        flash('error', 'The selected value could not be found.');
        redirect_to('departments/k9/setup.php?list=' . urlencode($selectedLookup));
    }
}

page_header('K-9 Setup');
?>
<main class="shell">
    <section class="panel">
        <h1>K-9 Setup</h1>
        <p>Manage dropdown lists used by K-9 handlers and supervisors.</p>
        <?php k9_navigation('setup'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1><?= e($selectedConfig['label']) ?></h1>
                <p class="muted"><?= $editRow ? 'Edit this value and save your changes.' : 'Add values or reactivate existing values as the K-9 program changes.' ?></p>
            </div>
            <form method="get">
                <label>
                    Setup list
                    <select name="list" onchange="this.form.submit()">
                        <?php foreach ($lookupConfigs as $key => $lookup): ?>
                            <option value="<?= e($key) ?>" <?= $selectedLookup === $key ? 'selected' : '' ?>><?= e($lookup['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>

        <form class="form compact-form" method="post">
            <input type="hidden" name="list" value="<?= e($selectedLookup) ?>">
            <input type="hidden" name="edit_id" value="<?= e((string) ($editRow['id'] ?? 0)) ?>">
            <label>
                Name
                <input name="name" value="<?= e($editRow['name'] ?? '') ?>" required>
            </label>
            <?php if ($selectedLookup === 'locations'): ?>
                <label>
                    Address or description
                    <input name="address_description" value="<?= e($editRow['address_description'] ?? '') ?>">
                </label>
            <?php endif; ?>
            <?php if ($selectedLookup === 'training_aids'): ?>
                <label>
                    Category
                    <select name="category">
                        <option value="">Select category</option>
                        <?php foreach ($trainingAidCategories as $category): ?>
                            <option value="<?= e($category) ?>" <?= ($editRow['category'] ?? '') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <?php if ($selectedLookup === 'vet_doctors'): ?>
                <label>
                    Vet office
                    <select name="vet_office_id" required>
                        <option value="">Select vet office</option>
                        <?php foreach ($vetOffices as $office): ?>
                            <option value="<?= e((string) $office['id']) ?>" <?= (int) ($editRow['vet_office_id'] ?? 0) === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label>
                Sort order
                <input type="number" name="sort_order" min="0" value="<?= e((string) ($editRow['sort_order'] ?? 100)) ?>">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_active" value="1" <?= !$editRow || (int) $editRow['is_active'] === 1 ? 'checked' : '' ?>>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>Active<small>Show this value in dropdown lists.</small></span>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $editRow ? 'Save changes' : 'Save value' ?></button>
                <?php if ($editRow): ?>
                    <a class="button secondary" href="<?= e(url('departments/k9/setup.php?list=' . urlencode($selectedLookup))) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Current Values</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <?php if ($selectedLookup === 'locations'): ?>
                        <th>Description</th>
                    <?php elseif ($selectedLookup === 'training_aids'): ?>
                        <th>Category</th>
                    <?php elseif ($selectedLookup === 'vet_doctors'): ?>
                        <th>Vet Office</th>
                    <?php endif; ?>
                    <th>Sort</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td data-label="Name"><?= e($row['name']) ?></td>
                        <?php if ($selectedLookup === 'locations'): ?>
                            <td data-label="Description"><?= e($row['address_description'] ?? '') ?></td>
                        <?php elseif ($selectedLookup === 'training_aids'): ?>
                            <td data-label="Category"><?= e($row['category'] ?? '') ?></td>
                        <?php elseif ($selectedLookup === 'vet_doctors'): ?>
                            <td data-label="Vet Office"><?= e($row['vet_office_name'] ?? '') ?></td>
                        <?php endif; ?>
                        <td data-label="Sort"><?= e((string) $row['sort_order']) ?></td>
                        <td data-label="Status"><?= (int) $row['is_active'] === 1 ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                        <td data-label="Actions">
                            <a class="button secondary compact-button" href="<?= e(url('departments/k9/setup.php?list=' . urlencode($selectedLookup) . '&edit=' . (int) $row['id'])) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= in_array($selectedLookup, ['locations', 'training_aids', 'vet_doctors'], true) ? '5' : '4' ?>">No values have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
