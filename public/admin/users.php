<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departmentIds = (array) ($_POST['department_ids'] ?? []);
    $departmentId = $departmentIds ? (int) reset($departmentIds) : null;

    $statement = db()->prepare(
        'INSERT INTO users (department_id, first_name, last_name, email, password_hash, role)
         VALUES (:department_id, :first_name, :last_name, :email, :password_hash, :role)'
    );

    $statement->execute([
        'department_id' => $departmentId,
        'first_name' => preserve_name_case($_POST['first_name'] ?? ''),
        'last_name' => preserve_name_case($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password_hash' => password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT),
        'role' => $_POST['role'] ?? 'standard_user',
    ]);

    $userId = (int) db()->lastInsertId();
    sync_user_departments($userId, $departmentIds);

    audit_event('created', 'user', (string) $userId, [
        'email' => trim($_POST['email'] ?? ''),
        'role' => $_POST['role'] ?? 'standard_user',
        'department_ids' => array_values(array_map('intval', $departmentIds)),
    ]);

    flash('success', 'User account created.');
    redirect_to('admin/users.php');
}

$users = db()->query(
    'SELECT users.*, user_department_summary.department_names
     FROM users
     LEFT JOIN (
        SELECT user_departments.user_id, GROUP_CONCAT(departments.name ORDER BY departments.name SEPARATOR ", ") AS department_names
        FROM user_departments
        INNER JOIN departments ON departments.id = user_departments.department_id
        GROUP BY user_departments.user_id
     ) AS user_department_summary ON user_department_summary.user_id = users.id
     ORDER BY users.last_name, users.first_name'
)->fetchAll();

$departments = db()->query('SELECT * FROM departments ORDER BY name')->fetchAll();

page_header('Manage Users');
?>
<main class="shell">
    <section class="panel">
        <h1>Manage Users</h1>
        <p>Create employee accounts and assign the correct role and department.</p>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <label>
                First name
                <input name="first_name" required>
            </label>
            <label>
                Last name
                <input name="last_name" required>
            </label>
            <label>
                Email
                <input type="email" name="email" required>
            </label>
            <label>
                Temporary password
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label>
                Role
                <select name="role" required>
                    <option value="standard_user">Standard User</option>
                    <option value="department_admin">Department Supervisor</option>
                    <option value="system_admin">IT System Admin</option>
                </select>
            </label>
            <fieldset class="form-fieldset span-2">
                <legend>Departments</legend>
                <div class="checkbox-grid">
                    <?php foreach ($departments as $department): ?>
                        <label class="check-option">
                            <input type="checkbox" name="department_ids[]" value="<?= e((string) $department['id']) ?>">
                            <?= e($department['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="actions span-2">
                <button type="submit">Create user</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Existing Users</h1>
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Departments</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $portalUser): ?>
                    <tr>
                        <td><?= e($portalUser['first_name'] . ' ' . $portalUser['last_name']) ?></td>
                        <td><?= e($portalUser['email']) ?></td>
                        <td><?= e(status_badge($portalUser['role'])) ?></td>
                        <td><?= e($portalUser['department_names'] ?? 'None') ?></td>
                        <td><?= $portalUser['is_active'] ? 'Active' : 'Inactive' ?></td>
                        <td>
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('admin/user-edit.php?id=' . $portalUser['id'])) ?>" title="Edit user" aria-label="Edit user">✎</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
