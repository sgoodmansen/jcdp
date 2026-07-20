<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$id = (int) ($_GET['id'] ?? 0);
$statement = db()->prepare(
    'SELECT dmv_title_requests.*, dmv_lienholders.*
     FROM dmv_title_requests
     INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
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

page_header('Title Request Letter');
?>
<main class="shell">
    <section class="panel">
        <div class="actions">
            <button onclick="window.print()">Print letter</button>
            <a class="button secondary" href="<?= e(url('departments/dmv/title-requests.php')) ?>">Back to requests</a>
        </div>
    </section>

    <section class="panel letter" style="margin-top: 18px;">
        <p class="meta"><?= e(date('F j, Y', strtotime($request['request_date']))) ?></p>

        <p>
            <?= e($request['company_name']) ?><br>
            <?php if ($request['contact_name']): ?>
                Attn: <?= e($request['contact_name']) ?><br>
            <?php endif; ?>
            <?= e($request['mailing_address']) ?><br>
            <?= e($request['city']) ?>, <?= e($request['state']) ?> <?= e($request['zip_code']) ?>
        </p>

        <p>Re: Request for Vehicle Title</p>

        <p>To Whom It May Concern:</p>

        <p>
            Jefferson County is requesting the vehicle title information for
            <?= e($request['registrant_name']) ?>, whose address is
            <?= e($request['registrant_address']) ?>,
            <?= e($request['registrant_city']) ?>,
            <?= e($request['registrant_state']) ?>
            <?= e($request['registrant_zip_code']) ?>.
        </p>

        <p>
            Vehicle:
            <?= e(trim($request['vehicle_year'] . ' ' . $request['vehicle_make'] . ' ' . $request['vehicle_model'])) ?>
            <?php if ($request['vin']): ?>
                <br>VIN: <?= e($request['vin']) ?>
            <?php endif; ?>
        </p>

        <p>
            Please provide the title or required release documentation so the vehicle registration can be completed.
        </p>

        <p>
            Sincerely,<br><br>
            Jefferson County DMV
        </p>
    </section>
</main>
<?php page_footer(); ?>
