<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$statement = db()->prepare('SELECT * FROM dmv_title_requests WHERE id = :id');
$statement->execute(['id' => $id]);
$request = $statement->fetch();

if (!$request) {
    http_response_code(404);
    page_header('Title request not found');
    echo '<main class="shell"><section class="panel"><h1>Title request not found</h1><p>The selected title request could not be found.</p></section></main>';
    page_footer();
    exit;
}

$lienholdersStatement = db()->prepare(
    'SELECT id, company_name, is_active
     FROM dmv_lienholders
     WHERE is_active = 1 OR id = :current_lienholder_id
     ORDER BY company_name'
);
$lienholdersStatement->execute(['current_lienholder_id' => $request['lienholder_id']]);
$lienholders = $lienholdersStatement->fetchAll();
$vehicleMakes = db()->query(
    'SELECT id, name FROM dmv_vehicle_makes WHERE is_active = 1 ORDER BY name'
)->fetchAll();
$vehicleModels = [];

if (!empty($request['vehicle_make_id'])) {
    $modelStatement = db()->prepare(
        'SELECT id, name FROM dmv_vehicle_models WHERE make_id = :make_id AND is_active = 1 ORDER BY name'
    );
    $modelStatement->execute(['make_id' => $request['vehicle_make_id']]);
    $vehicleModels = $modelStatement->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleMakeId = (int) ($_POST['vehicle_make_id'] ?? 0);
    $vehicleModelId = (int) ($_POST['vehicle_model_id'] ?? 0);
    $vehicleMake = '';
    $vehicleModel = '';
    $vin = normalize_vin($_POST['vin'] ?? '');

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
        'UPDATE dmv_title_requests
         SET lienholder_id = :lienholder_id,
             request_date = :request_date,
             registrant_name = :registrant_name,
             registrant_name_2 = :registrant_name_2,
             registrant_address = :registrant_address,
             registrant_city = :registrant_city,
             registrant_state = :registrant_state,
             registrant_zip_code = :registrant_zip_code,
             registrant_phone = :registrant_phone,
             vehicle_year = :vehicle_year,
             vehicle_make_id = :vehicle_make_id,
             vehicle_model_id = :vehicle_model_id,
             vehicle_make = :vehicle_make,
             vehicle_model = :vehicle_model,
             vin = :vin,
             status = :status,
             notes = :notes
         WHERE id = :id'
    );

    $statement->execute([
        'id' => $id,
        'lienholder_id' => (int) ($_POST['lienholder_id'] ?? 0),
        'request_date' => $_POST['request_date'] ?? date('Y-m-d'),
        'registrant_name' => title_case_name($_POST['registrant_name'] ?? ''),
        'registrant_name_2' => title_case_name($_POST['registrant_name_2'] ?? '') ?: null,
        'registrant_address' => title_case_address($_POST['registrant_address'] ?? ''),
        'registrant_city' => title_case_name($_POST['registrant_city'] ?? ''),
        'registrant_state' => trim($_POST['registrant_state'] ?? ''),
        'registrant_zip_code' => trim($_POST['registrant_zip_code'] ?? ''),
        'registrant_phone' => trim($_POST['registrant_phone'] ?? ''),
        'vehicle_year' => trim($_POST['vehicle_year'] ?? ''),
        'vehicle_make_id' => $vehicleMakeId > 0 ? $vehicleMakeId : null,
        'vehicle_model_id' => $vehicleModelId > 0 ? $vehicleModelId : null,
        'vehicle_make' => $vehicleMake,
        'vehicle_model' => $vehicleModel,
        'vin' => $vin,
        'status' => $_POST['status'] ?? 'draft',
        'notes' => trim($_POST['notes'] ?? ''),
    ]);

    audit_event('updated', 'dmv_title_request', (string) $id, [
        'registrant_name' => title_case_name($_POST['registrant_name'] ?? ''),
        'previous_registrant_name' => $request['registrant_name'],
        'status' => $_POST['status'] ?? 'draft',
        'previous_status' => $request['status'],
    ]);

    flash('success', 'Title request updated.');
    redirect_to('departments/dmv/letter.php?id=' . $id);
}

$actions = [
    ['label' => 'View letter', 'href' => url('departments/dmv/letter.php?id=' . $request['id']), 'primary' => true],
    ['label' => 'Title requests', 'href' => url('departments/dmv/title-requests.php')],
    ['label' => 'DMV home', 'href' => url('departments/dmv/index.php')],
];

page_header('Edit Title Request');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit Title Request</h1>
        <p>Update the registrant, lienholder, vehicle, or status information for this request.</p>

        <?php page_actions($actions); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $request['id']) ?>">
            <label>
                Lienholder
                <select name="lienholder_id" required>
                    <option value="">Select lienholder</option>
                    <?php foreach ($lienholders as $lienholder): ?>
                        <option value="<?= e((string) $lienholder['id']) ?>" <?= (int) $request['lienholder_id'] === (int) $lienholder['id'] ? 'selected' : '' ?>>
                            <?= e($lienholder['company_name']) ?><?= (int) $lienholder['is_active'] === 0 ? ' (Inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Request date
                <input type="date" name="request_date" value="<?= e($request['request_date']) ?>" required>
            </label>
            <label>
                Registrant name
                <input name="registrant_name" value="<?= e($request['registrant_name']) ?>" required>
            </label>
            <label>
                Additional registrant name
                <span class="clearable-field">
                    <input name="registrant_name_2" value="<?= e($request['registrant_name_2']) ?>">
                    <button type="button" class="field-clear-button" data-clear-field="registrant_name_2" title="Clear additional registrant name" aria-label="Clear additional registrant name">×</button>
                </span>
            </label>
            <label class="span-2">
                Registrant address
                <input name="registrant_address" value="<?= e($request['registrant_address']) ?>" required>
            </label>
            <label>
                City
                <input name="registrant_city" value="<?= e($request['registrant_city']) ?>" required>
            </label>
            <label>
                State
                <select name="registrant_state" required>
                    <?php state_options($request['registrant_state']); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="registrant_zip_code" value="<?= e($request['registrant_zip_code']) ?>" required>
            </label>
            <label>
                Phone
                <input name="registrant_phone" class="phone-input" inputmode="tel" placeholder="(208) 555-1234" value="<?= e($request['registrant_phone']) ?>">
            </label>
            <label>
                VIN
                <input name="vin" class="vin-input" title="Standard VINs are 17 letters or numbers and do not include I, O, or Q." value="<?= e(normalize_vin($request['vin'])) ?>" data-vin-check-url="<?= e(url('departments/dmv/vin-check.php')) ?>" data-current-request-id="<?= e((string) $request['id']) ?>">
                <span class="field-warning vin-warning" aria-live="polite"></span>
            </label>
            <label>
                Vehicle year
                <input name="vehicle_year" value="<?= e($request['vehicle_year']) ?>">
            </label>
            <label>
                Vehicle make
                <select name="vehicle_make_id" id="vehicle-make" required>
                    <option value="">Select make</option>
                    <?php foreach ($vehicleMakes as $make): ?>
                        <option value="<?= e((string) $make['id']) ?>" <?= (int) $request['vehicle_make_id'] === (int) $make['id'] ? 'selected' : '' ?>>
                            <?= e($make['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Vehicle model
                <select name="vehicle_model_id" id="vehicle-model" required <?= empty($vehicleModels) ? 'disabled' : '' ?>>
                    <option value=""><?= empty($vehicleModels) ? 'Select make first' : 'Select model' ?></option>
                    <?php foreach ($vehicleModels as $model): ?>
                        <option value="<?= e((string) $model['id']) ?>" <?= (int) $request['vehicle_model_id'] === (int) $model['id'] ? 'selected' : '' ?>>
                            <?= e($model['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Status
                <select name="status">
                    <?php foreach (['draft' => 'Draft', 'sent' => 'Sent', 'received' => 'Received', 'closed' => 'Closed'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $request['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($request['notes']) ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/letter.php?id=' . $request['id'])) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<script>
    const vehicleMake = document.getElementById('vehicle-make');
    const vehicleModel = document.getElementById('vehicle-model');

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
<script src="<?= e(url('assets/forms.js?v=20260730a')) ?>"></script>
<?php page_footer(); ?>
