<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_manager('dare');

$schoolId = (int) ($_GET['id'] ?? $_POST['school_id'] ?? 0);

if ($schoolId <= 0) {
    http_response_code(404);
    exit('School not found.');
}

$statement = db()->prepare('SELECT * FROM dare_schools WHERE id = :id');
$statement->execute(['id' => $schoolId]);
$school = $statement->fetch();

if (!$school) {
    http_response_code(404);
    exit('School not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $params = [
        'id' => $schoolId,
        'name' => title_case_company($_POST['name'] ?? ''),
        'address' => title_case_address($_POST['address'] ?? ''),
        'city' => title_case_name($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'zip_code' => trim($_POST['zip_code'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    $statement = db()->prepare(
        'UPDATE dare_schools
         SET name = :name,
             address = :address,
             city = :city,
             state = :state,
             zip_code = :zip_code,
             is_active = :is_active
         WHERE id = :id'
    );
    $statement->execute($params);

    audit_event('updated', 'dare_school', (string) $schoolId, [
        'name' => $params['name'],
        'is_active' => $params['is_active'],
    ]);

    flash('success', 'School updated.');
    redirect_to('departments/dare/lookups.php');
}

$actions = [
    ['label' => 'Schools & Officers', 'href' => url('departments/dare/lookups.php'), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header('Edit School');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit School</h1>
        <p>Update school contact information or mark the school inactive.</p>
        <?php page_actions($actions); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <form class="form compact-form" method="post">
            <input type="hidden" name="school_id" value="<?= e((string) $school['id']) ?>">
            <label>
                School name
                <input name="name" value="<?= e($school['name']) ?>" required>
            </label>
            <label>
                Address
                <input name="address" value="<?= e($school['address']) ?>">
            </label>
            <label>
                City
                <input name="city" value="<?= e($school['city']) ?>">
            </label>
            <label>
                State
                <select name="state">
                    <?php state_options($school['state'] ?: 'ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code" value="<?= e($school['zip_code']) ?>">
            </label>
            <label class="check-label compact-check">
                <input type="checkbox" name="is_active" value="1" <?= (int) $school['is_active'] === 1 ? 'checked' : '' ?>>
                Active school
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/dare/lookups.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
