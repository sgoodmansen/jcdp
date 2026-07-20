<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$summary = db()->query(
    'SELECT status, COUNT(*) AS record_count
     FROM dmv_title_requests
     GROUP BY status
     ORDER BY status'
)->fetchAll();

page_header('DMV Reports');
?>
<main class="shell">
    <section class="panel">
        <h1>DMV Reports</h1>
        <p>Starter report grouped by title request status. This page will become the home for the Access reports we recreate.</p>
        <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">Back to DMV</a>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Title Requests by Status</h1>
        <table class="table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Total Requests</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary as $row): ?>
                    <tr>
                        <td><?= e($row['status']) ?></td>
                        <td><?= e((string) $row['record_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$summary): ?>
                    <tr>
                        <td colspan="2">No title requests have been entered yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
