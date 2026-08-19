<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$id = (int) ($_GET['id'] ?? 0);
$statement = db()->prepare('SELECT * FROM sheriff_training_officers WHERE id = :id');
$statement->execute(['id' => $id]);
$officer = $statement->fetch();

if (!$officer) {
    http_response_code(404);
    page_header('Officer not found');
    echo '<main class="shell"><section class="panel"><h1>Officer not found</h1><p>The selected officer could not be found.</p></section></main>';
    page_footer();
    exit;
}

$statement = db()->prepare(
    'SELECT sheriff_training_requests.*,
            sheriff_training_fiscal_years.label AS fiscal_year_label
     FROM sheriff_training_requests
     INNER JOIN sheriff_training_fiscal_years ON sheriff_training_fiscal_years.id = sheriff_training_requests.fiscal_year_id
     WHERE sheriff_training_requests.officer_id = :officer_id
     ORDER BY sheriff_training_requests.start_date DESC, sheriff_training_requests.created_at DESC'
);
$statement->execute(['officer_id' => $id]);
$requests = $statement->fetchAll();

page_header('Officer Training History');
?>
<main class="shell">
    <section class="panel">
        <h1><?= e($officer['first_name'] . ' ' . $officer['last_name']) ?></h1>
        <p><?= e(trim(($officer['rank_title'] ?? '') . (($officer['division'] ?? '') ? ' - ' . $officer['division'] : '')) ?: 'Officer training history') ?></p>
        <?php sheriff_training_navigation('officers'); ?>
        <?php page_actions([
            ['label' => 'New request', 'href' => url('departments/sheriff-training/request-edit.php?officer_id=' . $officer['id']), 'primary' => true],
            ['label' => 'Edit officer', 'href' => url('departments/sheriff-training/officer-edit.php?id=' . $officer['id'])],
            ['label' => 'Officer list', 'href' => url('departments/sheriff-training/officers.php')],
        ]); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Training History</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Training</th>
                    <th>Payment FY</th>
                    <th>Status</th>
                    <th>Cost Used</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($request['start_date'])) ?></td>
                        <td data-label="Training"><?= e($request['class_name']) ?><br><span class="meta"><?= e($request['provider'] ?: 'Provider not set') ?></span></td>
                        <td data-label="Payment FY"><?= e($request['fiscal_year_label']) ?></td>
                        <td data-label="Status"><span class="badge <?= e(sheriff_training_status_badge_class($request['status'])) ?>"><?= e(sheriff_training_status_label($request['status'])) ?></span></td>
                        <td data-label="Cost Used"><?= e(sheriff_training_money(sheriff_training_effective_training_cost($request) + sheriff_training_effective_lodging_cost($request))) ?></td>
                        <td data-label="Actions"><a class="button compact-button secondary" href="<?= e(url('departments/sheriff-training/request-detail.php?id=' . $request['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?>
                    <tr><td colspan="6">No training requests have been entered for this officer.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
