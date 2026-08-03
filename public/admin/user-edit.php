<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$statement = db()->prepare('SELECT * FROM users WHERE id = :id');
$statement->execute(['id' => $id]);
$portalUser = $statement->fetch();

if (!$portalUser) {
    http_response_code(404);
    page_header('User not found');
    echo '<main class="shell"><section class="panel"><h1>User not found</h1><p>The selected user could not be found.</p></section></main>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departmentIds = (array) ($_POST['department_ids'] ?? []);
    $departmentId = $departmentIds ? (int) reset($departmentIds) : null;
    $previousDepartmentIds = department_ids_for_user($id);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $password = trim($_POST['password'] ?? '');

    if ($password !== '') {
        $statement = db()->prepare(
            'UPDATE users
             SET department_id = :department_id,
                 first_name = :first_name,
                 last_name = :last_name,
                 email = :email,
                 password_hash = :password_hash,
                 role = :role,
                 is_active = :is_active
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'department_id' => $departmentId,
            'first_name' => preserve_name_case($_POST['first_name'] ?? ''),
            'last_name' => preserve_name_case($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $_POST['role'] ?? 'standard_user',
            'is_active' => $isActive,
        ]);
    } else {
        $statement = db()->prepare(
            'UPDATE users
             SET department_id = :department_id,
                 first_name = :first_name,
                 last_name = :last_name,
                 email = :email,
                 role = :role,
                 is_active = :is_active
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'department_id' => $departmentId,
            'first_name' => preserve_name_case($_POST['first_name'] ?? ''),
            'last_name' => preserve_name_case($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'role' => $_POST['role'] ?? 'standard_user',
            'is_active' => $isActive,
        ]);
    }

    sync_user_departments($id, $departmentIds);

    audit_event('updated', 'user', (string) $id, [
        'email' => trim($_POST['email'] ?? ''),
        'previous_email' => $portalUser['email'],
        'role' => $_POST['role'] ?? 'standard_user',
        'previous_role' => $portalUser['role'],
        'department_ids' => array_values(array_map('intval', $departmentIds)),
        'previous_department_ids' => $previousDepartmentIds,
        'is_active' => $isActive,
        'previous_is_active' => $portalUser['is_active'],
        'password_reset' => $password !== '',
    ]);

    flash('success', 'User account updated.');
    redirect_to('admin/users.php');
}

$departments = db()->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$assignedDepartmentIds = department_ids_for_user($id);

page_header('Edit User');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit User</h1>
        <p>Update account details, role, department, active status, or reset a temporary password.</p>

        <div class="actions" style="margin-bottom: 18px;">
            <a class="button secondary" href="<?= e(url('admin/users.php')) ?>">Manage users</a>
            <a class="button secondary" href="<?= e(url('admin/audit-log.php')) ?>">Audit log</a>
        </div>

        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $portalUser['id']) ?>">
            <label>
                First name
                <input name="first_name" value="<?= e($portalUser['first_name']) ?>" required>
            </label>
            <label>
                Last name
                <input name="last_name" value="<?= e($portalUser['last_name']) ?>" required>
            </label>
            <label class="span-2">
                Email
                <input type="email" name="email" value="<?= e($portalUser['email']) ?>" required>
            </label>
            <label>
                Role
                <select name="role" required>
                    <?php foreach (['standard_user' => 'Standard User', 'department_admin' => 'Department Supervisor', 'system_admin' => 'IT System Admin'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $portalUser['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <fieldset class="form-fieldset span-2">
                <legend>Departments</legend>
                <div class="checkbox-grid">
                    <?php foreach ($departments as $department): ?>
                        <label class="check-option">
                            <input type="checkbox" name="department_ids[]" value="<?= e((string) $department['id']) ?>" <?= in_array((int) $department['id'], $assignedDepartmentIds, true) ? 'checked' : '' ?>>
                            <?= e($department['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <label>
                Temporary password
                <input type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep current password">
            </label>
            <label class="check-label">
                <input type="checkbox" name="is_active" <?= $portalUser['is_active'] ? 'checked' : '' ?>>
                Active user
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('admin/users.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
