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
        dmv_lienholders.company_name,
        dmv_lienholders.contact_name,
        dmv_lienholders.mailing_address,
        dmv_lienholders.city AS lienholder_city,
        dmv_lienholders.state AS lienholder_state,
        dmv_lienholders.zip_code AS lienholder_zip_code,
        dmv_lienholders.phone AS lienholder_phone,
        dmv_lienholders.phone_extension AS lienholder_phone_extension,
        dmv_lienholders.fax AS lienholder_fax,
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
    page_header('Letter not found');
    echo '<main class="shell"><section class="panel"><h1>Letter not found</h1><p>The selected title request could not be found.</p></section></main>';
    page_footer();
    exit;
}

$clerkName = trim(($request['clerk_first_name'] ?? '') . ' ' . ($request['clerk_last_name'] ?? ''));
$clerkEmail = $request['clerk_email'] ?? '';

if ($clerkName === '') {
    $clerkName = 'Jefferson County DMV';
}

$registrantNames = $request['registrant_name'];
if (!empty($request['registrant_name_2'])) {
    $registrantNames .= ' and ' . $request['registrant_name_2'];
}
$actions = [
    ['label' => 'Download PDF', 'href' => url('departments/dmv/letter-pdf.php?id=' . $request['id']), 'primary' => true],
    ['label' => 'Edit request', 'href' => url('departments/dmv/title-request-edit.php?id=' . $request['id'])],
    ['label' => 'Request details', 'href' => url('departments/dmv/title-request-detail.php?id=' . $request['id'])],
    ['label' => 'Back to requests', 'href' => url('departments/dmv/title-requests.php')],
];

page_header('Title Request Letter');
?>
<main class="shell">
    <section class="panel">
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
        <div class="page-toolbar">
            <div class="letter-action-row">
                <button type="button" class="button desktop-print-button" onclick="window.print()">Print letter</button>
                <?php page_actions($actions); ?>
            </div>
            <aside class="status-panel" aria-label="Request status">
                <div class="status-panel-header">
                    <strong>Request Status</strong>
                    <span class="badge"><?= e(ucfirst($request['status'])) ?></span>
                </div>
                <div class="actions status-actions">
                    <?php foreach (['sent' => 'Mark as Sent', 'received' => 'Mark as Received', 'closed' => 'Close Request'] as $newStatus => $label): ?>
                        <?php if ($request['status'] !== $newStatus): ?>
                            <form method="post" action="<?= e(url('departments/dmv/status-update.php')) ?>">
                                <input type="hidden" name="id" value="<?= e((string) $request['id']) ?>">
                                <input type="hidden" name="status" value="<?= e($newStatus) ?>">
                                <button type="submit" class="secondary"><?= e($label) ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>
    </section>

    <section class="panel letter dmv-letter" style="margin-top: 18px;">
        <header class="letter-header">
            <img src="<?= e(url('assets/jefferson-county-logo.png')) ?>" alt="Jefferson County Idaho seal">
            <div>
                <h1>Jefferson County Assessor</h1>
                <p>PO Box 538 Rigby, ID 83442</p>
                <p>Phone: (208) 745-9228</p>
                <p>Fax: (208) 745-5240</p>
                <p>Assessor: Jessica Roach</p>
            </div>
        </header>

        <div class="letter-address-row">
            <p>
                <?= e($request['company_name']) ?><br>
                <?php if ($request['contact_name']): ?>
                    ATTN: <?= e($request['contact_name']) ?><br>
                <?php endif; ?>
                <?= e($request['mailing_address']) ?><br>
                <?= e($request['lienholder_city']) ?> <?= e($request['lienholder_state']) ?> <?= e($request['lienholder_zip_code']) ?><br>
                <?php if ($request['lienholder_fax']): ?>
                    Fax <?= e($request['lienholder_fax']) ?><br>
                <?php endif; ?>
                <?php if ($request['lienholder_phone']): ?>
                    Phone <?= e($request['lienholder_phone']) ?><?= $request['lienholder_phone_extension'] ? ' ext. ' . e($request['lienholder_phone_extension']) : '' ?>
                <?php endif; ?>
            </p>
            <p><?= e(date('n/j/Y', strtotime($request['request_date']))) ?></p>
        </div>

        <p>
            The following person has made an application to register the following vehicle in Idaho. It is necessary that
            the present title be transferred to an Idaho title which will be returned to you as lienholder.
        </p>

        <p>
            Please, mail the ORIGINAL PAPER TITLE and a copy of this letter to the address shown above. The LIEN will be
            recorded on the Idaho title and mailed to you.
        </p>

        <p>
            The Idaho title showing your lien will be forwarded to you immediately upon issuance by the Idaho
            Transportation Department, Boise, Idaho.
        </p>

        <div class="letter-details">
            <dl class="letter-detail-list">
                <dt>Owner</dt>
                <dd><?= e($registrantNames) ?></dd>
                <dt>Address</dt>
                <dd><?= e($request['registrant_address']) ?></dd>
                <dt aria-hidden="true"></dt>
                <dd><?= e($request['registrant_city']) ?> <?= e($request['registrant_state']) ?> <?= e($request['registrant_zip_code']) ?></dd>
                <?php if ($request['registrant_phone']): ?>
                    <dt>Phone</dt>
                    <dd><?= e($request['registrant_phone']) ?></dd>
                <?php endif; ?>
            </dl>
            <dl class="letter-detail-list vehicle-detail-list">
                <dt>Year</dt>
                <dd><?= e($request['vehicle_year']) ?></dd>
                <dt>Make</dt>
                <dd><?= e($request['vehicle_make']) ?></dd>
                <dt>Model</dt>
                <dd><?= e($request['vehicle_model']) ?></dd>
                <dt>VIN</dt>
                <dd><?= e(normalize_vin($request['vin'])) ?></dd>
            </dl>
        </div>

        <p>
            Sincerely,<br><br>
            <?= e($clerkName) ?><br>
            <?php if ($clerkEmail): ?>
                <?= e($clerkEmail) ?><br>
            <?php endif; ?>
            Motor Vehicle Titles and Registration<br>
            210 Courthouse Way, Suite 150<br>
            PO Box 538<br>
            Rigby, ID 83442
        </p>
    </section>
</main>
<?php page_footer(); ?>
