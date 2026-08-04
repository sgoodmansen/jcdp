<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_system_admin();

$tables = [
    'dare_class_lessons' => 'Class lesson records',
    'dare_class_students' => 'Class student assignments',
    'dare_classes' => 'Classes',
    'dare_students' => 'Students',
    'dare_teachers' => 'Teachers',
    'dare_schools' => 'Schools',
    'dare_officers' => 'DARE officers',
];

function dare_cleanup_count(string $table): int
{
    return (int) db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

function dare_cleanup_audit_count(): int
{
    return (int) db()->query(
        'SELECT COUNT(*)
         FROM audit_log
         WHERE entity_type IN (
            "dare_class",
            "dare_school",
            "dare_officer",
            "dare_teacher",
            "dare_lesson",
            "dare_setting"
         )'
    )->fetchColumn();
}

$counts = [];
foreach ($tables as $table => $label) {
    $counts[$table] = dare_cleanup_count($table);
}
$auditCount = dare_cleanup_audit_count();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmation = trim($_POST['confirmation'] ?? '');
    $removeAudit = isset($_POST['remove_audit']);

    if ($confirmation !== 'DELETE DARE TEST DATA') {
        flash('error', 'The confirmation text did not match. No DARE records were removed.');
        redirect_to('departments/dare/cleanup.php');
    }

    $deleted = [];

    db()->beginTransaction();
    try {
        if ($removeAudit) {
            $statement = db()->prepare(
                'DELETE FROM audit_log
                 WHERE entity_type IN (
                    "dare_class",
                    "dare_school",
                    "dare_officer",
                    "dare_teacher",
                    "dare_lesson",
                    "dare_setting"
                 )'
            );
            $statement->execute();
            $deleted['DARE audit history'] = $statement->rowCount();
        }

        foreach (array_keys($tables) as $table) {
            $statement = db()->prepare('DELETE FROM ' . $table);
            $statement->execute();
            $deleted[$tables[$table]] = $statement->rowCount();
        }

        audit_event('dare_cleanup_completed', 'dare_cleanup', 'manual', [
            'deleted' => $deleted,
            'removed_audit_history' => $removeAudit,
        ]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        flash('error', 'DARE cleanup failed. No records were removed.');
        redirect_to('departments/dare/cleanup.php');
    }

    flash('success', 'DARE cleanup completed.');
    redirect_to('departments/dare/cleanup.php');
}

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Schools & officers', 'href' => url('departments/dare/lookups.php')],
    ['label' => 'Lessons', 'href' => url('departments/dare/lessons.php')],
];

page_header('DARE Cleanup');
?>
<main class="shell">
    <section class="panel">
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <h1>DARE Cleanup</h1>
        <p>Remove existing DARE test records before importing Access data. This page is temporary and available only to system admins.</p>
        <?php dare_navigation('cleanup'); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Records To Remove</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Record Type</th>
                    <th>Records</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables as $table => $label): ?>
                    <tr>
                        <td data-label="Record Type"><?= e($label) ?></td>
                        <td data-label="Records"><?= e((string) $counts[$table]) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td data-label="Record Type">DARE audit history</td>
                    <td data-label="Records"><?= e((string) $auditCount) ?> optional</td>
                </tr>
            </tbody>
        </table>
        <p class="meta" style="margin-top: 14px;">This keeps DARE lesson templates and certificate settings. Portal users and department permissions are not removed.</p>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Confirm Cleanup</h1>
        <p>This action cannot be undone from the website. Type the confirmation phrase exactly before removing records.</p>
        <form class="form compact-form" method="post">
            <label class="span-2">
                Confirmation phrase
                <input name="confirmation" placeholder="DELETE DARE TEST DATA" autocomplete="off">
            </label>
            <label class="check-label span-2">
                <input type="checkbox" name="remove_audit" value="1">
                Also remove DARE audit history
            </label>
            <div class="actions span-2">
                <button type="submit">Remove DARE test records</button>
                <a class="button secondary" href="<?= e(url('departments/dare/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
