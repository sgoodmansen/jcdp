<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_login();

$user = current_user();

if ($user['role'] === 'system_admin') {
    $departments = db()->query('SELECT * FROM departments ORDER BY name')->fetchAll();
} else {
    $statement = db()->prepare('SELECT * FROM departments WHERE id = :id');
    $statement->execute(['id' => $user['department_id']]);
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
                <?php else: ?>
                    <span class="badge">Module placeholder</span>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php page_footer(); ?>
