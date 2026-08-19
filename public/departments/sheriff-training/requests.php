<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$query = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'pending';
$fiscalYearId = (int) ($_GET['fiscal_year_id'] ?? 0);
if (!array_key_exists($status, sheriff_training_status_options()) && $status !== 'all') {
    $status = 'pending';
}

$years = db()->query('SELECT id, label FROM sheriff_training_fiscal_years ORDER BY fiscal_year DESC')->fetchAll();
$sql = 'SELECT sheriff_training_requests.*,
               sheriff_training_officers.first_name,
               sheriff_training_officers.last_name,
               sheriff_training_fiscal_years.label AS fiscal_year_label
        FROM sheriff_training_requests
        INNER JOIN sheriff_training_officers ON sheriff_training_officers.id = sheriff_training_requests.officer_id
        INNER JOIN sheriff_training_fiscal_years ON sheriff_training_fiscal_years.id = sheriff_training_requests.fiscal_year_id
        WHERE 1 = 1';
$params = [];

if ($query !== '') {
    $sql .= ' AND (sheriff_training_requests.class_name LIKE :query
              OR sheriff_training_requests.provider LIKE :query
              OR sheriff_training_officers.first_name LIKE :query
              OR sheriff_training_officers.last_name LIKE :query)';
    $params['query'] = '%' . $query . '%';
}
if ($status !== 'all') {
    $sql .= ' AND sheriff_training_requests.status = :status';
    $params['status'] = $status;
}
if ($fiscalYearId > 0) {
    $sql .= ' AND sheriff_training_requests.fiscal_year_id = :fiscal_year_id';
    $params['fiscal_year_id'] = $fiscalYearId;
}

$sql .= ' ORDER BY sheriff_training_requests.start_date DESC, sheriff_training_requests.created_at DESC';
$statement = db()->prepare($sql);
$statement->execute($params);
$requests = $statement->fetchAll();

page_header('Sheriff Training Requests');
?>
<main class="shell">
    <section class="panel">
        <h1>Training Requests</h1>
        <p>Review paper-submitted training requests and track the budget year that pays for each training.</p>
        <?php sheriff_training_navigation('requests'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <h1>Requests</h1>
            <a class="button" href="<?= e(url('departments/sheriff-training/request-edit.php')) ?>">New request</a>
        </div>
        <form class="form compact-form" method="get" style="margin-bottom: 18px;">
            <label>
                Search
                <input name="q" value="<?= e($query) ?>" placeholder="Officer, training, or provider">
            </label>
            <label>
                Status
                <select name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <?php foreach (sheriff_training_status_options() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Payment fiscal year
                <select name="fiscal_year_id">
                    <option value="">All fiscal years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= e((string) $year['id']) ?>" <?= $fiscalYearId === (int) $year['id'] ? 'selected' : '' ?>><?= e($year['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Filter</button>
                <a class="button secondary" href="<?= e(url('departments/sheriff-training/requests.php')) ?>">Clear</a>
            </div>
        </form>

        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Officer</th>
                    <th>Training</th>
                    <th>Training Dates</th>
                    <th>Payment FY</th>
                    <th>Status</th>
                    <th>Budget Cost</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td data-label="Officer"><?= e($request['last_name'] . ', ' . $request['first_name']) ?></td>
                        <td data-label="Training"><?= e($request['class_name']) ?><br><span class="meta"><?= e($request['provider'] ?: 'Provider not set') ?></span></td>
                        <td data-label="Training Dates"><?= e(format_display_date($request['start_date'])) ?><?= $request['end_date'] && $request['end_date'] !== $request['start_date'] ? ' to ' . e(format_display_date($request['end_date'])) : '' ?></td>
                        <td data-label="Payment FY"><?= e($request['fiscal_year_label']) ?></td>
                        <td data-label="Status"><span class="badge <?= e(sheriff_training_status_badge_class($request['status'])) ?>"><?= e(sheriff_training_status_label($request['status'])) ?></span></td>
                        <td data-label="Budget Cost"><?= e(sheriff_training_money(sheriff_training_effective_training_cost($request) + sheriff_training_effective_lodging_cost($request))) ?></td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <?php
                                $primaryActionLabel = match ($request['status']) {
                                    'pending' => 'Review',
                                    'approved' => 'Complete',
                                    default => 'View',
                                };
                                $primaryActionHref = $request['status'] === 'approved'
                                    ? url('departments/sheriff-training/request-detail.php?id=' . $request['id'] . '#complete-training')
                                    : url('departments/sheriff-training/request-detail.php?id=' . $request['id']);
                                ?>
                                <a class="button compact-button" href="<?= e($primaryActionHref) ?>"><?= e($primaryActionLabel) ?></a>
                                <?php if ($request['status'] === 'approved'): ?>
                                    <a class="button secondary compact-button" href="<?= e(url('departments/sheriff-training/request-detail.php?id=' . $request['id'])) ?>">Review</a>
                                <?php endif; ?>
                                <a class="icon-link" href="<?= e(url('departments/sheriff-training/request-edit.php?id=' . $request['id'])) ?>" title="Edit request" aria-label="Edit request">&#9998;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?>
                    <tr><td colspan="7">No training requests matched the selected filter.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
