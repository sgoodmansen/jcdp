<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$isManager = k9_user_can_manage();
[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');
$teamSql = 'SELECT k9_teams.*, k9_dogs.dog_name, k9_handlers.handler_name
            FROM k9_teams
            INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            WHERE k9_teams.is_active = 1' . $teamWhere . '
            ORDER BY k9_dogs.dog_name, k9_handlers.handler_name';
$statement = db()->prepare($teamSql);
$statement->execute($teamParams);
$teams = $statement->fetchAll();
$vetOffices = k9_lookup_options('k9_vet_offices');
$doctorStatement = db()->query(
    'SELECT k9_vet_doctors.id, k9_vet_doctors.vet_office_id, k9_vet_doctors.name, k9_vet_offices.name AS vet_office_name
     FROM k9_vet_doctors
     INNER JOIN k9_vet_offices ON k9_vet_offices.id = k9_vet_doctors.vet_office_id
     WHERE k9_vet_doctors.is_active = 1 AND k9_vet_offices.is_active = 1
     ORDER BY k9_vet_offices.sort_order, k9_vet_offices.name, k9_vet_doctors.sort_order, k9_vet_doctors.name'
);
$vetDoctors = $doctorStatement->fetchAll();
$id = (int) ($_GET['id'] ?? 0);
$record = null;
$existingShots = [];

if ($id > 0) {
    $recordStatement = db()->prepare(
        'SELECT k9_medical_visits.*
         FROM k9_medical_visits
         INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_visits.dog_id
         INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
         WHERE k9_medical_visits.id = :id' . $teamWhere
    );
    $recordStatement->execute(array_merge($teamParams, ['id' => $id]));
    $record = $recordStatement->fetch();
    if (!$record) {
        http_response_code(404);
        page_header('Medical Visit Not Found');
        echo '<main class="shell"><section class="panel"><h1>Medical visit not found</h1><p>The selected medical visit could not be found.</p></section></main>';
        page_footer();
        exit;
    }

    $shotStatement = db()->prepare('SELECT * FROM k9_medical_shots WHERE medical_visit_id = :id ORDER BY id');
    $shotStatement->execute(['id' => $id]);
    $existingShots = $shotStatement->fetchAll();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $selectedTeam = null;
    foreach ($teams as $team) {
        if ((int) $team['id'] === $teamId) {
            $selectedTeam = $team;
            break;
        }
    }

    if (!$selectedTeam) {
        flash('error', 'Select a valid K-9 team.');
        redirect_to('departments/k9/medical-edit.php' . ($id > 0 ? '?id=' . $id : ''));
    }

    $vetOfficeId = (int) ($_POST['vet_office_id'] ?? 0);
    $selectedVetOffice = null;
    foreach ($vetOffices as $office) {
        if ((int) $office['id'] === $vetOfficeId) {
            $selectedVetOffice = $office;
            break;
        }
    }

    $vetDoctorId = (int) ($_POST['vet_doctor_id'] ?? 0);
    $selectedVetDoctor = null;
    foreach ($vetDoctors as $doctor) {
        if ((int) $doctor['id'] === $vetDoctorId && (!$selectedVetOffice || (int) $doctor['vet_office_id'] === (int) $selectedVetOffice['id'])) {
            $selectedVetDoctor = $doctor;
            break;
        }
    }

    $saveParams = [
        'dog_id' => (int) $selectedTeam['dog_id'],
        'vet_office_id' => $selectedVetOffice ? (int) $selectedVetOffice['id'] : null,
        'vet_doctor_id' => $selectedVetDoctor ? (int) $selectedVetDoctor['id'] : null,
        'visit_date' => $_POST['visit_date'] ?? date('Y-m-d'),
        'vet_office_name' => $selectedVetOffice['name'] ?? null,
        'doctor_name' => $selectedVetDoctor['name'] ?? null,
        'reason_for_visit' => trim($_POST['reason_for_visit'] ?? '') ?: null,
        'notes' => trim($_POST['notes'] ?? ''),
        'next_appointment_date' => trim($_POST['next_appointment_date'] ?? '') ?: null,
        'next_appointment_time' => trim($_POST['next_appointment_time'] ?? '') ?: null,
        'next_appointment_scheduled' => trim($_POST['next_appointment_scheduled'] ?? '') ?: null,
    ];

    if ($id > 0) {
        $statement = db()->prepare(
            'UPDATE k9_medical_visits
             SET dog_id = :dog_id, vet_office_id = :vet_office_id, vet_doctor_id = :vet_doctor_id,
                 visit_date = :visit_date, vet_office_name = :vet_office_name,
                 doctor_name = :doctor_name, reason_for_visit = :reason_for_visit, notes = :notes,
                 next_appointment_date = :next_appointment_date, next_appointment_time = :next_appointment_time,
                 next_appointment_scheduled = :next_appointment_scheduled
             WHERE id = :id'
        );
        $statement->execute(array_merge($saveParams, ['id' => $id]));
        $visitId = $id;
        db()->prepare('DELETE FROM k9_medical_shots WHERE medical_visit_id = :id')->execute(['id' => $id]);
    } else {
        $statement = db()->prepare(
            'INSERT INTO k9_medical_visits (
                dog_id, vet_office_id, vet_doctor_id, visit_date, vet_office_name, doctor_name, reason_for_visit, notes,
                next_appointment_date, next_appointment_time, next_appointment_scheduled
            ) VALUES (
                :dog_id, :vet_office_id, :vet_doctor_id, :visit_date, :vet_office_name, :doctor_name, :reason_for_visit, :notes,
                :next_appointment_date, :next_appointment_time, :next_appointment_scheduled
            )'
        );
        $statement->execute($saveParams);
        $visitId = (int) db()->lastInsertId();
    }

    $shotStatement = db()->prepare(
        'INSERT INTO k9_medical_shots (medical_visit_id, dog_id, shot_description, shot_expiration)
         VALUES (:medical_visit_id, :dog_id, :shot_description, :shot_expiration)'
    );
    foreach ($_POST['shot_descriptions'] ?? [] as $index => $description) {
        $description = trim((string) $description);
        if ($description === '') {
            continue;
        }
        $shotStatement->execute([
            'medical_visit_id' => $visitId,
            'dog_id' => (int) $selectedTeam['dog_id'],
            'shot_description' => $description,
            'shot_expiration' => trim($_POST['shot_expirations'][$index] ?? '') ?: null,
        ]);
    }

    audit_event($id > 0 ? 'updated' : 'created', 'k9_medical_visit', (string) $visitId);
    flash('success', $id > 0 ? 'K-9 medical visit updated.' : 'K-9 medical visit saved.');
    redirect_to('departments/k9/record-detail.php?type=medical&id=' . $visitId);
}

$shotRows = $existingShots ?: [['shot_description' => '', 'shot_expiration' => '']];
$selectedVetOfficeId = (int) ($record['vet_office_id'] ?? 0);
if ($selectedVetOfficeId === 0 && !empty($record['vet_office_name'])) {
    foreach ($vetOffices as $office) {
        if (strcasecmp((string) $office['name'], (string) $record['vet_office_name']) === 0) {
            $selectedVetOfficeId = (int) $office['id'];
            break;
        }
    }
}
$selectedVetDoctorId = (int) ($record['vet_doctor_id'] ?? 0);
if ($selectedVetDoctorId === 0 && !empty($record['doctor_name'])) {
    foreach ($vetDoctors as $doctor) {
        if (
            strcasecmp((string) $doctor['name'], (string) $record['doctor_name']) === 0
            && ($selectedVetOfficeId === 0 || (int) $doctor['vet_office_id'] === $selectedVetOfficeId)
        ) {
            $selectedVetDoctorId = (int) $doctor['id'];
            break;
        }
    }
}
page_header($id > 0 ? 'Edit K-9 Medical Visit' : 'Add K-9 Medical Visit');
?>
<main class="shell">
    <section class="panel">
        <h1><?= $id > 0 ? 'Edit Medical Visit' : 'Add Medical Visit' ?></h1>
        <p>Record vet visits, care notes, follow-up appointments, and shot expirations.</p>
        <?php k9_navigation('medical-edit'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$teams): ?>
            <div class="notice error"><?= $isManager ? 'Create an active K-9 team before entering medical visits.' : 'Your account is not connected to an active K-9 team yet.' ?></div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <label>
                K-9 team
                <select name="team_id" required>
                    <option value="">Select team</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= e((string) $team['id']) ?>" <?= (int) ($record['dog_id'] ?? 0) === (int) $team['dog_id'] ? 'selected' : '' ?>><?= e($team['dog_name'] . ' - ' . $team['handler_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Visit date
                <input type="date" name="visit_date" value="<?= e($record['visit_date'] ?? date('Y-m-d')) ?>" required>
            </label>
            <label>
                Vet office
                <select name="vet_office_id" id="k9-vet-office-select">
                    <option value="">Select vet office</option>
                    <?php foreach ($vetOffices as $office): ?>
                        <option value="<?= e((string) $office['id']) ?>" <?= $selectedVetOfficeId === (int) $office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Doctor
                <select name="vet_doctor_id" id="k9-vet-doctor-select">
                    <option value="">Select doctor</option>
                    <?php foreach ($vetDoctors as $doctor): ?>
                        <option value="<?= e((string) $doctor['id']) ?>" data-vet-office-id="<?= e((string) $doctor['vet_office_id']) ?>" <?= $selectedVetDoctorId === (int) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">
                Reason for visit
                <input name="reason_for_visit" value="<?= e($record['reason_for_visit'] ?? '') ?>">
            </label>
            <label>
                Next appointment date
                <input type="date" name="next_appointment_date" value="<?= e($record['next_appointment_date'] ?? '') ?>">
            </label>
            <label>
                Next appointment time
                <input type="time" name="next_appointment_time" value="<?= e($record['next_appointment_time'] ?? '') ?>">
            </label>
            <label class="span-2">
                Next appointment status
                <input name="next_appointment_scheduled" value="<?= e($record['next_appointment_scheduled'] ?? '') ?>" placeholder="Scheduled, needed, not needed">
            </label>

            <fieldset class="span-2">
                <legend>Shots / vaccinations</legend>
                <div class="k9-shot-list" id="k9-shot-list">
                    <?php foreach ($shotRows as $shotRow): ?>
                    <div class="k9-shot-row">
                        <label>
                            Description
                            <input name="shot_descriptions[]" value="<?= e($shotRow['shot_description'] ?? '') ?>" placeholder="Rabies, bordetella, etc.">
                        </label>
                        <label>
                            Expiration date
                            <input type="date" name="shot_expirations[]" value="<?= e($shotRow['shot_expiration'] ?? '') ?>">
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="secondary compact-button" id="k9-add-shot">Add another shot</button>
            </fieldset>

            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($record['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$teams ? 'disabled' : '' ?>><?= $id > 0 ? 'Save changes' : 'Save medical visit' ?></button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<script>
    const shotList = document.getElementById('k9-shot-list');
    const addShotButton = document.getElementById('k9-add-shot');
    const vetOfficeSelect = document.getElementById('k9-vet-office-select');
    const vetDoctorSelect = document.getElementById('k9-vet-doctor-select');

    const syncDoctors = () => {
        if (!vetOfficeSelect || !vetDoctorSelect) {
            return;
        }

        const selectedOfficeId = vetOfficeSelect.value;
        let selectedDoctorIsVisible = false;
        vetDoctorSelect.querySelectorAll('option').forEach((option) => {
            const officeId = option.dataset.vetOfficeId || '';
            const isVisible = option.value === '' || (selectedOfficeId !== '' && officeId === selectedOfficeId);
            option.hidden = !isVisible;
            if (option.selected && isVisible) {
                selectedDoctorIsVisible = true;
            }
        });

        if (!selectedDoctorIsVisible) {
            vetDoctorSelect.value = '';
        }
    };

    vetOfficeSelect?.addEventListener('change', syncDoctors);
    syncDoctors();

    addShotButton?.addEventListener('click', () => {
        const firstRow = shotList?.querySelector('.k9-shot-row');
        if (!firstRow || !shotList) {
            return;
        }

        const nextRow = firstRow.cloneNode(true);
        nextRow.querySelectorAll('input').forEach((field) => {
            field.value = '';
        });
        shotList.appendChild(nextRow);
    });
</script>
<?php page_footer(); ?>
