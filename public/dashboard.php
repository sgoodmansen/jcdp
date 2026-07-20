<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$user = current_user();

page_header('Dashboard');
?>
<main class="shell">
    <section class="panel">
        <h1>Welcome, <?= e($user['first_name']) ?></h1>
        <p>
            <?= e(status_badge($user['role'])) ?>
            <?php if ($user['department_name']): ?>
                for <?= e($user['department_name']) ?>
            <?php endif; ?>
        </p>
        <a class="button" href="<?= e(url('departments/index.php')) ?>">Open department tools</a>
        <?php if ($user['role'] === 'system_admin'): ?>
            <a class="button secondary" href="<?= e(url('admin/users.php')) ?>">Manage users</a>
        <?php endif; ?>
    </section>

    <section class="grid" style="margin-top: 18px;">
        <article class="card">
            <h2>Data Entry</h2>
            <p>Department forms will live inside each module.</p>
        </article>
        <article class="card">
            <h2>Reports</h2>
            <p>Required Access reports can be rebuilt as web reports and exports.</p>
        </article>
        <article class="card">
            <h2>Administration</h2>
            <p>IT admins manage users, roles, departments, and future import tools.</p>
        </article>
    </section>
</main>
<?php page_footer(); ?>
