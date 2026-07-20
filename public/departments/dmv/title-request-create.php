<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$user = current_user();
$lienholders = db()->query('SELECT id, company_name FROM dmv_lienholders WHERE is_active = 1 ORDER BY company_name')->fetchAll();
$vehicleMakes = db()->query(
    'SELECT id, name FROM dmv_vehicle_makes WHERE is_active = 1 ORDER BY name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleMakeId = (int) ($_POST['vehicle_make_id'] ?? 0);
    $vehicleModelId = (int) ($_POST['vehicle_model_id'] ?? 0);
    $vehicleMake = '';
    $vehicleModel = '';

    if ($vehicleMakeId > 0) {
        $makeStatement = db()->prepare('SELECT name FROM dmv_vehicle_makes WHERE id = :id AND is_active = 1');
        $makeStatement->execute(['id' => $vehicleMakeId]);
        $vehicleMake = (string) $makeStatement->fetchColumn();
    }

    if ($vehicleModelId > 0) {
        $modelStatement = db()->prepare(
            'SELECT name FROM dmv_vehicle_models WHERE id = :id AND make_id = :make_id AND is_active = 1'
        );
        $modelStatement->execute([
            'id' => $vehicleModelId,
            'make_id' => $vehicleMakeId,
        ]);
        $vehicleModel = (string) $modelStatement->fetchColumn();
    }

    $statement = db()->prepare(
        'INSERT INTO dmv_title_requests
            (lienholder_id, created_by, request_date, registrant_name, registrant_name_2, registrant_address, registrant_city, registrant_state, registrant_zip_code, registrant_phone, vehicle_year, vehicle_make_id, vehicle_model_id, vehicle_make, vehicle_model, vin, status, notes)
         VALUES
            (:lienholder_id, :created_by, :request_date, :registrant_name, :registrant_name_2, :registrant_address, :registrant_city, :registrant_state, :registrant_zip_code, :registrant_phone, :vehicle_year, :vehicle_make_id, :vehicle_model_id, :vehicle_make, :vehicle_model, :vin, :status, :notes)'
    );

    $statement->execute([
        'lienholder_id' => (int) ($_POST['lienholder_id'] ?? 0),
        'created_by' => $user['id'],
        'request_date' => $_POST['request_date'] ?? date('Y-m-d'),
        'registrant_name' => trim($_POST['registrant_name'] ?? ''),
        'registrant_name_2' => trim($_POST['registrant_name_2'] ?? ''),
        'registrant_address' => trim($_POST['registrant_address'] ?? ''),
        'registrant_city' => trim($_POST['registrant_city'] ?? ''),
        'registrant_state' => trim($_POST['registrant_state'] ?? ''),
        'registrant_zip_code' => trim($_POST['registrant_zip_code'] ?? ''),
        'registrant_phone' => trim($_POST['registrant_phone'] ?? ''),
        'vehicle_year' => trim($_POST['vehicle_year'] ?? ''),
        'vehicle_make_id' => $vehicleMakeId > 0 ? $vehicleMakeId : null,
        'vehicle_model_id' => $vehicleModelId > 0 ? $vehicleModelId : null,
        'vehicle_make' => $vehicleMake,
        'vehicle_model' => $vehicleModel,
        'vin' => trim($_POST['vin'] ?? ''),
        'status' => $_POST['status'] ?? 'draft',
        'notes' => trim($_POST['notes'] ?? ''),
    ]);

    $requestId = (int) db()->lastInsertId();
    audit_event('created', 'dmv_title_request', (string) $requestId, [
        'registrant_name' => trim($_POST['registrant_name'] ?? ''),
        'lienholder_id' => (int) ($_POST['lienholder_id'] ?? 0),
        'status' => $_POST['status'] ?? 'draft',
    ]);

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

        <form class="form compact-form" method="post">
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
            <label id="second-registrant-field" class="optional-field" style="display: none;">
                Additional registrant name
                <input name="registrant_name_2">
            </label>
            <button type="button" class="button secondary" id="add-registrant-button">Add another registrant</button>
            <label class="span-2">
                Registrant address
                <input name="registrant_address" required>
            </label>
            <label>
                City
                <input name="registrant_city" required>
            </label>
            <label>
                State
                <select name="registrant_state" required>
                    <?php state_options('ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="registrant_zip_code" required>
            </label>
            <label>
                Phone
                <input name="registrant_phone" class="phone-input" inputmode="tel" placeholder="(208) 555-1234">
            </label>
            <label>
                VIN
                <input name="vin">
            </label>
            <label>
                Vehicle year
                <input name="vehicle_year">
            </label>
            <label>
                Vehicle make
                <select name="vehicle_make_id" id="vehicle-make" required>
                    <option value="">Select make</option>
                    <?php foreach ($vehicleMakes as $make): ?>
                        <option value="<?= e((string) $make['id']) ?>"><?= e($make['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Vehicle model
                <select name="vehicle_model_id" id="vehicle-model" required disabled>
                    <option value="">Select make first</option>
                </select>
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
            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Create letter</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<script>
    const addRegistrantButton = document.getElementById('add-registrant-button');
    const secondRegistrantField = document.getElementById('second-registrant-field');
    const vehicleMake = document.getElementById('vehicle-make');
    const vehicleModel = document.getElementById('vehicle-model');

    addRegistrantButton?.addEventListener('click', () => {
        secondRegistrantField.style.display = 'grid';
        addRegistrantButton.style.display = 'none';
        secondRegistrantField.querySelector('input')?.focus();
    });

    vehicleMake?.addEventListener('change', async () => {
        vehicleModel.innerHTML = '<option value="">Loading models...</option>';
        vehicleModel.disabled = true;

        if (!vehicleMake.value) {
            vehicleModel.innerHTML = '<option value="">Select make first</option>';
            return;
        }

        const response = await fetch(`<?= e(url('departments/dmv/models.php')) ?>?make_id=${vehicleMake.value}`);
        const models = await response.json();

        vehicleModel.innerHTML = '<option value="">Select model</option>';
        models.forEach((model) => {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = model.name;
            vehicleModel.appendChild(option);
        });
        vehicleModel.disabled = false;
    });
</script>
<script src="<?= e(url('assets/forms.js?v=20260720')) ?>"></script>
<?php page_footer(); ?>
