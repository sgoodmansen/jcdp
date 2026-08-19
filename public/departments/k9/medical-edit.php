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
        redirect_to('departments/k9/medical-edit.php');
    }

    $statement = db()->prepare(
        'INSERT INTO k9_medical_visits (
            dog_id, visit_date, vet_office_name, doctor_name, reason_for_visit, notes,
            next_appointment_date, next_appointment_time, next_appointment_scheduled
        ) VALUES (
            :dog_id, :visit_date, :vet_office_name, :doctor_name, :reason_for_visit, :notes,
            :next_appointment_date, :next_appointment_time, :next_appointment_scheduled
        )'
    );
    $statement->execute([
        'dog_id' => (int) $selectedTeam['dog_id'],
        'visit_date' => $_POST['visit_date'] ?? date('Y-m-d'),
        'vet_office_name' => trim($_POST['vet_office_name'] ?? '') ?: null,
        'doctor_name' => trim($_POST['doctor_name'] ?? '') ?: null,
        'reason_for_visit' => trim($_POST['reason_for_visit'] ?? '') ?: null,
        'notes' => trim($_POST['notes'] ?? ''),
        'next_appointment_date' => trim($_POST['next_appointment_date'] ?? '') ?: null,
        'next_appointment_time' => trim($_POST['next_appointment_time'] ?? '') ?: null,
        'next_appointment_scheduled' => trim($_POST['next_appointment_scheduled'] ?? '') ?: null,
    ]);

    $visitId = (int) db()->lastInsertId();
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

    audit_event('created', 'k9_medical_visit', (string) $visitId);
    flash('success', 'K-9 medical visit saved.');
    redirect_to('departments/k9/activity.php?record_type=medical');
}

page_header('Add K-9 Medical Visit');
?>
<main class="shell">
    <section class="panel">
        <h1>Add Medical Visit</h1>
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
                        <option value="<?= e((string) $team['id']) ?>"><?= e($team['dog_name'] . ' - ' . $team['handler_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Visit date
                <input type="date" name="visit_date" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>
                Vet office
                <input name="vet_office_name">
            </label>
            <label>
                Doctor
                <input name="doctor_name">
            </label>
            <label class="span-2">
                Reason for visit
                <input name="reason_for_visit">
            </label>
            <label>
                Next appointment date
                <input type="date" name="next_appointment_date">
            </label>
            <label>
                Next appointment time
                <input type="time" name="next_appointment_time">
            </label>
            <label class="span-2">
                Next appointment status
                <input name="next_appointment_scheduled" placeholder="Scheduled, needed, not needed">
            </label>

            <fieldset class="span-2">
                <legend>Shots / vaccinations</legend>
                <div class="k9-shot-list" id="k9-shot-list">
                    <div class="k9-shot-row">
                        <label>
                            Description
                            <input name="shot_descriptions[]" placeholder="Rabies, bordetella, etc.">
                        </label>
                        <label>
                            Expiration date
                            <input type="date" name="shot_expirations[]">
                        </label>
                    </div>
                </div>
                <button type="button" class="secondary compact-button" id="k9-add-shot">Add another shot</button>
            </fieldset>

            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$teams ? 'disabled' : '' ?>>Save medical visit</button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<script>
    const shotList = document.getElementById('k9-shot-list');
    const addShotButton = document.getElementById('k9-add-shot');

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
