<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

$user = current_user();

if ($user['role'] === 'system_admin') {
    $departments = db()->query('SELECT * FROM departments ORDER BY name')->fetchAll();
} else {
    $statement = db()->prepare(
        'SELECT departments.*
         FROM departments
         INNER JOIN user_departments ON user_departments.department_id = departments.id
         WHERE user_departments.user_id = :user_id
         ORDER BY departments.name'
    );
    $statement->execute(['user_id' => $user['id']]);
    $departments = $statement->fetchAll();
}

page_header('Departments');
?>
<main class="shell">
    <section class="panel">
        <h1>Department Tools</h1>
        <p>Select a module to begin data entry, record lookup, or reports.</p>
    </section>

    <section class="grid" style="margin-top: 18px;">
        <?php foreach ($departments as $department): ?>
            <article class="card">
                <h2><?= e($department['name']) ?></h2>
                <p><?= e($department['description']) ?></p>
                <?php if ($department['slug'] === 'dmv'): ?>
                    <a class="button" href="<?= e(url('departments/dmv/index.php')) ?>">Open DMV</a>
                <?php elseif ($department['slug'] === 'dare'): ?>
                    <a class="button" href="<?= e(url('departments/dare/index.php')) ?>">Open DARE</a>
                <?php else: ?>
                    <span class="badge">Module placeholder</span>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$departments): ?>
            <article class="card">
                <h2>No Departments Assigned</h2>
                <p>Ask an administrator to assign your account to a department.</p>
            </article>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
