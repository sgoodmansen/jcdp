<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statement = db()->prepare(
        'INSERT INTO users (department_id, first_name, last_name, email, password_hash, role)
         VALUES (:department_id, :first_name, :last_name, :email, :password_hash, :role)'
    );

    $departmentId = $_POST['department_id'] !== '' ? (int) $_POST['department_id'] : null;
    $statement->execute([
        'department_id' => $departmentId,
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password_hash' => password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT),
        'role' => $_POST['role'] ?? 'standard_user',
    ]);

    flash('success', 'User account created.');
    redirect_to('admin/users.php');
}

$users = db()->query(
    'SELECT users.*, departments.name AS department_name
     FROM users
     LEFT JOIN departments ON departments.id = users.department_id
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

        <form class="form" method="post">
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
                <input type="password" name="password" required>
            </label>
            <label>
                Role
                <select name="role" required>
                    <option value="standard_user">Standard User</option>
                    <option value="department_admin">Department Admin</option>
                    <option value="system_admin">IT System Admin</option>
                </select>
            </label>
            <label>
                Department
                <select name="department_id">
                    <option value="">No department / IT admin</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>"><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Create user</button>
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
                    <th>Department</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $portalUser): ?>
                    <tr>
                        <td><?= e($portalUser['first_name'] . ' ' . $portalUser['last_name']) ?></td>
                        <td><?= e($portalUser['email']) ?></td>
                        <td><?= e(status_badge($portalUser['role'])) ?></td>
                        <td><?= e($portalUser['department_name'] ?? 'None') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
