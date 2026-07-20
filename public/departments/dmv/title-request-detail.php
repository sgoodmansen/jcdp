<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$id = (int) ($_GET['id'] ?? 0);
$statement = db()->prepare(
    'SELECT
        dmv_title_requests.id,
        dmv_title_requests.status,
        dmv_title_requests.request_date,
        dmv_title_requests.registrant_name,
        dmv_title_requests.registrant_name_2,
        dmv_title_requests.registrant_address,
        dmv_title_requests.registrant_city,
        dmv_title_requests.registrant_state,
        dmv_title_requests.registrant_zip_code,
        dmv_title_requests.registrant_phone,
        dmv_title_requests.vehicle_year,
        COALESCE(dmv_vehicle_makes.name, dmv_title_requests.vehicle_make) AS vehicle_make,
        COALESCE(dmv_vehicle_models.name, dmv_title_requests.vehicle_model) AS vehicle_model,
        dmv_title_requests.vin,
        dmv_title_requests.notes,
        dmv_title_requests.created_at,
        dmv_title_requests.updated_at,
        dmv_lienholders.company_name,
        dmv_lienholders.contact_name,
        dmv_lienholders.mailing_address,
        dmv_lienholders.city AS lienholder_city,
        dmv_lienholders.state AS lienholder_state,
        dmv_lienholders.zip_code AS lienholder_zip_code,
        dmv_lienholders.phone AS lienholder_phone,
        dmv_lienholders.phone_extension AS lienholder_phone_extension,
        dmv_lienholders.fax AS lienholder_fax,
        dmv_lienholders.email AS lienholder_email,
        users.first_name AS clerk_first_name,
        users.last_name AS clerk_last_name,
        users.email AS clerk_email
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
     LEFT JOIN users ON users.id = dmv_title_requests.created_by
     LEFT JOIN dmv_vehicle_makes ON dmv_vehicle_makes.id = dmv_title_requests.vehicle_make_id
     LEFT JOIN dmv_vehicle_models ON dmv_vehicle_models.id = dmv_title_requests.vehicle_model_id
     WHERE dmv_title_requests.id = :id'
);
$statement->execute(['id' => $id]);
$request = $statement->fetch();

if (!$request) {
    http_response_code(404);
    page_header('Title request not found');
    echo '<main class="shell"><section class="panel"><h1>Title request not found</h1><p>The selected title request could not be found.</p></section></main>';
    page_footer();
    exit;
}

$clerkName = trim(($request['clerk_first_name'] ?? '') . ' ' . ($request['clerk_last_name'] ?? ''));
if ($clerkName === '') {
    $clerkName = 'Unknown clerk';
}

$registrantNames = $request['registrant_name'];
if (!empty($request['registrant_name_2'])) {
    $registrantNames .= ' and ' . $request['registrant_name_2'];
}

page_header('Title Request Detail');
?>
<main class="shell">
    <section class="panel">
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <h1>Title Request Detail</h1>
        <p><?= e($registrantNames) ?> - <?= e(ucfirst($request['status'])) ?></p>

        <div class="actions">
            <a class="button" href="<?= e(url('departments/dmv/letter.php?id=' . $request['id'])) ?>">View letter</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/title-request-edit.php?id=' . $request['id'])) ?>">Edit request</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/title-requests.php')) ?>">Title requests</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">DMV home</a>
        </div>

        <p class="meta" style="margin-top: 12px;">Current status: <?= e(ucfirst($request['status'])) ?></p>
        <div class="actions" style="margin-top: 12px;">
            <?php foreach (['sent' => 'Mark as Sent', 'received' => 'Mark as Received', 'closed' => 'Close Request'] as $newStatus => $label): ?>
                <?php if ($request['status'] !== $newStatus): ?>
                    <form method="post" action="<?= e(url('departments/dmv/status-update.php')) ?>">
                        <input type="hidden" name="id" value="<?= e((string) $request['id']) ?>">
                        <input type="hidden" name="status" value="<?= e($newStatus) ?>">
                        <input type="hidden" name="return_to" value="detail">
                        <button type="submit" class="secondary"><?= e($label) ?></button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="detail-grid" style="margin-top: 18px;">
        <article class="panel detail-panel">
            <h2>Registrant</h2>
            <dl class="detail-list">
                <dt>Name</dt>
                <dd><?= e($registrantNames) ?></dd>
                <dt>Address</dt>
                <dd><?= e($request['registrant_address']) ?><br><?= e($request['registrant_city']) ?>, <?= e($request['registrant_state']) ?> <?= e($request['registrant_zip_code']) ?></dd>
                <dt>Phone</dt>
                <dd><?= e($request['registrant_phone'] ?: 'Not provided') ?></dd>
            </dl>
        </article>

        <article class="panel detail-panel">
            <h2>Vehicle</h2>
            <dl class="detail-list">
                <dt>Year</dt>
                <dd><?= e($request['vehicle_year'] ?: 'Not provided') ?></dd>
                <dt>Make</dt>
                <dd><?= e($request['vehicle_make'] ?: 'Not provided') ?></dd>
                <dt>Type</dt>
                <dd><?= e($request['vehicle_model'] ?: 'Not provided') ?></dd>
                <dt>VIN</dt>
                <dd><?= e($request['vin'] ?: 'Not provided') ?></dd>
            </dl>
        </article>

        <article class="panel detail-panel">
            <h2>Lienholder</h2>
            <dl class="detail-list">
                <dt>Name</dt>
                <dd><?= e($request['company_name']) ?></dd>
                <dt>ATTN</dt>
                <dd><?= e($request['contact_name'] ?: 'Not provided') ?></dd>
                <dt>Address</dt>
                <dd><?= e($request['mailing_address']) ?><br><?= e($request['lienholder_city']) ?>, <?= e($request['lienholder_state']) ?> <?= e($request['lienholder_zip_code']) ?></dd>
                <dt>Email</dt>
                <dd><?= e($request['lienholder_email'] ?: 'Not provided') ?></dd>
                <dt>Phone / Fax</dt>
                <dd>
                    <?= e($request['lienholder_phone'] ?: 'No phone') ?><?= $request['lienholder_phone_extension'] ? ' ext. ' . e($request['lienholder_phone_extension']) : '' ?>
                    / <?= e($request['lienholder_fax'] ?: 'No fax') ?>
                </dd>
            </dl>
        </article>

        <article class="panel detail-panel">
            <h2>Request</h2>
            <dl class="detail-list">
                <dt>Request date</dt>
                <dd><?= e($request['request_date']) ?></dd>
                <dt>Status</dt>
                <dd><?= e(ucfirst($request['status'])) ?></dd>
                <dt>Created by</dt>
                <dd><?= e($clerkName) ?><br><?= e($request['clerk_email'] ?: '') ?></dd>
                <dt>Created</dt>
                <dd><?= e($request['created_at']) ?></dd>
                <dt>Last updated</dt>
                <dd><?= e($request['updated_at']) ?></dd>
            </dl>
        </article>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Notes</h1>
        <p><?= e($request['notes'] ?: 'No notes entered.') ?></p>
    </section>
</main>
<?php page_footer(); ?>
