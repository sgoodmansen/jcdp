<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$user = current_user();
$lienholders = db()->query('SELECT id, company_name FROM dmv_lienholders ORDER BY company_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statement = db()->prepare(
        'INSERT INTO dmv_title_requests
            (lienholder_id, created_by, request_date, registrant_name, registrant_address, registrant_city, registrant_state, registrant_zip_code, vehicle_year, vehicle_make, vehicle_model, vin, status, notes)
         VALUES
            (:lienholder_id, :created_by, :request_date, :registrant_name, :registrant_address, :registrant_city, :registrant_state, :registrant_zip_code, :vehicle_year, :vehicle_make, :vehicle_model, :vin, :status, :notes)'
    );

    $statement->execute([
        'lienholder_id' => (int) ($_POST['lienholder_id'] ?? 0),
        'created_by' => $user['id'],
        'request_date' => $_POST['request_date'] ?? date('Y-m-d'),
        'registrant_name' => trim($_POST['registrant_name'] ?? ''),
        'registrant_address' => trim($_POST['registrant_address'] ?? ''),
        'registrant_city' => trim($_POST['registrant_city'] ?? ''),
        'registrant_state' => trim($_POST['registrant_state'] ?? ''),
        'registrant_zip_code' => trim($_POST['registrant_zip_code'] ?? ''),
        'vehicle_year' => trim($_POST['vehicle_year'] ?? ''),
        'vehicle_make' => trim($_POST['vehicle_make'] ?? ''),
        'vehicle_model' => trim($_POST['vehicle_model'] ?? ''),
        'vin' => trim($_POST['vin'] ?? ''),
        'status' => $_POST['status'] ?? 'draft',
        'notes' => trim($_POST['notes'] ?? ''),
    ]);

    $requestId = (int) db()->lastInsertId();
    flash('success', 'Title request created.');
    redirect_to('departments/dmv/letter.php?id=' . $requestId);
}

page_header('New Title Request');
?>
<main class="shell">
    <section class="panel">
        <h1>New Title Request</h1>
        <p>Create a record for the individual registering the vehicle, then generate the lienholder letter.</p>

        <?php if (!$lienholders): ?>
            <div class="notice error">Create at least one lienholder before creating a title request.</div>
        <?php endif; ?>

        <form class="form" method="post">
            <label>
                Lienholder
                <select name="lienholder_id" required>
                    <option value="">Select lienholder</option>
                    <?php foreach ($lienholders as $lienholder): ?>
                        <option value="<?= e((string) $lienholder['id']) ?>"><?= e($lienholder['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Request date
                <input type="date" name="request_date" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>
                Registrant name
                <input name="registrant_name" required>
            </label>
            <label>
                Registrant address
                <input name="registrant_address" required>
            </label>
            <label>
                City
                <input name="registrant_city" required>
            </label>
            <label>
                State
                <input name="registrant_state" required>
            </label>
            <label>
                ZIP code
                <input name="registrant_zip_code" required>
            </label>
            <label>
                Vehicle year
                <input name="vehicle_year">
            </label>
            <label>
                Vehicle make
                <input name="vehicle_make">
            </label>
            <label>
                Vehicle model
                <input name="vehicle_model">
            </label>
            <label>
                VIN
                <input name="vin">
            </label>
            <label>
                Status
                <select name="status">
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="received">Received</option>
                    <option value="closed">Closed</option>
                </select>
            </label>
            <label>
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions">
                <button type="submit">Create letter</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
