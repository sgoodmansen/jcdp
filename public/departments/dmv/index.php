<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$totalLienholders = (int) db()->query('SELECT COUNT(*) FROM dmv_lienholders')->fetchColumn();
$totalRequests = (int) db()->query('SELECT COUNT(*) FROM dmv_title_requests')->fetchColumn();
$recentRecords = db()->query(
    'SELECT dmv_title_requests.*, dmv_lienholders.company_name, users.first_name, users.last_name
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
     LEFT JOIN users ON users.id = dmv_title_requests.created_by
     ORDER BY dmv_title_requests.created_at DESC
     LIMIT 5'
)->fetchAll();

page_header('DMV');
?>
<main class="shell">
    <section class="panel">
        <h1>DMV Module</h1>
        <p>Create lienholder title-request letters and maintain reusable lienholder contact information.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('departments/dmv/title-request-create.php')) ?>">New title request</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/lienholder-create.php')) ?>">New lienholder</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/title-requests.php')) ?>">Title requests</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/lienholders.php')) ?>">Lienholders</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/report.php')) ?>">Reports</a>
        </div>
    </section>

    <section class="grid" style="margin-top: 18px;">
        <article class="card">
            <h2><?= e((string) $totalRequests) ?></h2>
            <p>Total title requests entered.</p>
        </article>
        <article class="card">
            <h2><?= e((string) $totalLienholders) ?></h2>
            <p>Lienholder contacts available for letters.</p>
        </article>
        <article class="card">
            <h2>Form Letters</h2>
            <p>Each request can generate a printable title request letter.</p>
        </article>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Recent Title Requests</h1>
        <?php if (!$recentRecords): ?>
            <p>No title requests have been entered yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Request Date</th>
                        <th>Registrant</th>
                        <th>Lienholder</th>
                        <th>Status</th>
                        <th>Entered By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRecords as $record): ?>
                        <tr>
                            <td><?= e($record['request_date']) ?></td>
                            <td><?= e($record['registrant_name']) ?></td>
                            <td><?= e($record['company_name']) ?></td>
                            <td><?= e($record['status']) ?></td>
                            <td><?= e(trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
