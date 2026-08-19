<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_manager();

$lookupConfigs = [
    'activity_types' => ['label' => 'Activity Types', 'table' => 'k9_activity_types'],
    'training_areas' => ['label' => 'Training Areas', 'table' => 'k9_training_areas'],
    'indications' => ['label' => 'Indications', 'table' => 'k9_indications'],
    'locations' => ['label' => 'Locations', 'table' => 'k9_locations'],
    'training_aids' => ['label' => 'Training Aids', 'table' => 'k9_training_aids'],
    'expense_categories' => ['label' => 'Expense Categories', 'table' => 'k9_expense_categories'],
    'incident_types' => ['label' => 'Incident Types', 'table' => 'k9_incident_types'],
    'assisting_agencies' => ['label' => 'Assisting Agencies', 'table' => 'k9_assisting_agencies'],
    'deployment_outcomes' => ['label' => 'Deployment Outcomes', 'table' => 'k9_deployment_outcomes'],
];

$selectedLookup = $_GET['list'] ?? 'locations';
if (!isset($lookupConfigs[$selectedLookup])) {
    $selectedLookup = 'locations';
}
$selectedConfig = $lookupConfigs[$selectedLookup];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedLookup = $_POST['list'] ?? $selectedLookup;
    if (!isset($lookupConfigs[$postedLookup])) {
        redirect_to('departments/k9/setup.php');
    }
    $postedConfig = $lookupConfigs[$postedLookup];
    $table = $postedConfig['table'];
    $name = trim($_POST['name'] ?? '');
    $sortOrder = max(0, (int) ($_POST['sort_order'] ?? 100));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        flash('error', 'Enter a name before saving.');
        redirect_to('departments/k9/setup.php?list=' . urlencode($postedLookup));
    }

    if ($postedLookup === 'locations') {
        $statement = db()->prepare(
            "INSERT INTO $table (name, address_description, sort_order, is_active)
             VALUES (:name, :address_description, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE address_description = VALUES(address_description), sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
        );
        $statement->execute([
            'name' => $name,
            'address_description' => trim($_POST['address_description'] ?? ''),
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    } elseif ($postedLookup === 'training_aids') {
        $statement = db()->prepare(
            "INSERT INTO $table (name, category, sort_order, is_active)
             VALUES (:name, :category, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE category = VALUES(category), sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
        );
        $statement->execute([
            'name' => $name,
            'category' => trim($_POST['category'] ?? ''),
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    } else {
        $statement = db()->prepare(
            "INSERT INTO $table (name, sort_order, is_active)
             VALUES (:name, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = VALUES(is_active)"
        );
        $statement->execute([
            'name' => $name,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ]);
    }

    flash('success', $postedConfig['label'] . ' saved.');
    redirect_to('departments/k9/setup.php?list=' . urlencode($postedLookup));
}

$rows = k9_lookup_options($selectedConfig['table'], 'name', false);

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
                <p class="muted">Add values or reactivate existing values as the K-9 program changes.</p>
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
            <label>
                Name
                <input name="name" required>
            </label>
            <?php if ($selectedLookup === 'locations'): ?>
                <label>
                    Address or description
                    <input name="address_description">
                </label>
            <?php endif; ?>
            <?php if ($selectedLookup === 'training_aids'): ?>
                <label>
                    Category
                    <select name="category">
                        <option value="">Select category</option>
                        <option value="Bite suit">Bite suit</option>
                        <option value="Toy">Toy</option>
                        <option value="Treat">Treat</option>
                        <option value="Drug">Drug</option>
                        <option value="Other">Other</option>
                    </select>
                </label>
            <?php endif; ?>
            <label>
                Sort order
                <input type="number" name="sort_order" min="0" value="100">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_active" value="1" checked>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>Active<small>Show this value in dropdown lists.</small></span>
            </label>
            <div class="actions span-2">
                <button type="submit">Save value</button>
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
                    <?php endif; ?>
                    <th>Sort</th>
                    <th>Status</th>
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
                        <?php endif; ?>
                        <td data-label="Sort"><?= e((string) $row['sort_order']) ?></td>
                        <td data-label="Status"><?= (int) $row['is_active'] === 1 ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Inactive</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $selectedLookup === 'locations' || $selectedLookup === 'training_aids' ? '4' : '3' ?>">No values have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
