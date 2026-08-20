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

$trainingActivityTypeId = k9_lookup_id_by_name('k9_activity_types', 'Training');
$trainingAreas = k9_lookup_options('k9_training_areas');
$indications = k9_lookup_options('k9_indications');
$locations = k9_lookup_options('k9_locations');
$trainingAids = k9_lookup_options('k9_training_aids');
$id = (int) ($_GET['id'] ?? 0);
$record = null;
$existingAids = [];

if ($id > 0) {
    $recordStatement = db()->prepare(
        'SELECT k9_activity_logs.*
         FROM k9_activity_logs
         INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
         LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
         WHERE k9_activity_logs.id = :id
           AND k9_activity_types.name = "Training"' . $teamWhere
    );
    $recordStatement->execute(array_merge($teamParams, ['id' => $id]));
    $record = $recordStatement->fetch();
    if (!$record) {
        http_response_code(404);
        page_header('Training Not Found');
        echo '<main class="shell"><section class="panel"><h1>Training not found</h1><p>The selected training record could not be found.</p></section></main>';
        page_footer();
        exit;
    }

    $aidStatement = db()->prepare('SELECT * FROM k9_activity_log_aids WHERE activity_log_id = :id ORDER BY id');
    $aidStatement->execute(['id' => $id]);
    $existingAids = $aidStatement->fetchAll();
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
        redirect_to('departments/k9/activity-edit.php' . ($id > 0 ? '?id=' . $id : ''));
    }

    $saveParams = [
        'team_id' => $teamId,
        'dog_id' => (int) $selectedTeam['dog_id'],
        'handler_id' => (int) $selectedTeam['handler_id'],
        'activity_date' => $_POST['activity_date'] ?? date('Y-m-d'),
        'activity_type_id' => $trainingActivityTypeId,
        'location_id' => (int) ($_POST['location_id'] ?? 0) ?: null,
        'training_area_id' => (int) ($_POST['training_area_id'] ?? 0) ?: null,
        'indication_id' => (int) ($_POST['indication_id'] ?? 0) ?: null,
        'training_hours' => k9_decimal($_POST['training_hours'] ?? '0'),
        'is_post_training' => isset($_POST['is_post_training']) ? 1 : 0,
        'incident_number' => null,
        'incident_type_id' => null,
        'assisting_agency_id' => null,
        'arrest_made' => 0,
        'deployment_outcome_id' => null,
        'notes' => trim($_POST['notes'] ?? ''),
        'created_by_user_id' => current_user()['id'] ?? null,
    ];

    if ($id > 0) {
        $statement = db()->prepare(
            'UPDATE k9_activity_logs
             SET team_id = :team_id, dog_id = :dog_id, handler_id = :handler_id, activity_date = :activity_date,
                 activity_type_id = :activity_type_id, location_id = :location_id, training_area_id = :training_area_id,
                 indication_id = :indication_id, training_hours = :training_hours, is_post_training = :is_post_training,
                 notes = :notes
             WHERE id = :id'
        );
        $statement->execute([
            'team_id' => $saveParams['team_id'],
            'dog_id' => $saveParams['dog_id'],
            'handler_id' => $saveParams['handler_id'],
            'activity_date' => $saveParams['activity_date'],
            'activity_type_id' => $saveParams['activity_type_id'],
            'location_id' => $saveParams['location_id'],
            'training_area_id' => $saveParams['training_area_id'],
            'indication_id' => $saveParams['indication_id'],
            'training_hours' => $saveParams['training_hours'],
            'is_post_training' => $saveParams['is_post_training'],
            'notes' => $saveParams['notes'],
            'id' => $id,
        ]);
        $activityId = $id;
        db()->prepare('DELETE FROM k9_activity_log_aids WHERE activity_log_id = :id')->execute(['id' => $id]);
    } else {
        $statement = db()->prepare(
            'INSERT INTO k9_activity_logs (
                team_id, dog_id, handler_id, activity_date, activity_type_id, location_id, training_area_id, indication_id,
                training_hours, is_post_training, incident_number, incident_type_id, assisting_agency_id, arrest_made,
                deployment_outcome_id, notes, created_by_user_id
            ) VALUES (
                :team_id, :dog_id, :handler_id, :activity_date, :activity_type_id, :location_id, :training_area_id, :indication_id,
                :training_hours, :is_post_training, :incident_number, :incident_type_id, :assisting_agency_id, :arrest_made,
                :deployment_outcome_id, :notes, :created_by_user_id
            )'
        );
        $statement->execute($saveParams);
        $activityId = (int) db()->lastInsertId();
    }

    $aidStatement = db()->prepare(
        'INSERT INTO k9_activity_log_aids (activity_log_id, training_aid_id, amount_grams)
         VALUES (:activity_log_id, :training_aid_id, :amount_grams)'
    );
    foreach ($_POST['training_aid_ids'] ?? [] as $index => $aidId) {
        $aidId = (int) $aidId;
        if ($aidId <= 0) {
            continue;
        }
        $aidStatement->execute([
            'activity_log_id' => $activityId,
            'training_aid_id' => $aidId,
            'amount_grams' => k9_decimal($_POST['aid_grams'][$index] ?? '0'),
        ]);
    }

    audit_event($id > 0 ? 'updated' : 'created', 'k9_activity_log', (string) $activityId);
    flash('success', $id > 0 ? 'K-9 training updated.' : 'K-9 training saved.');
    redirect_to('departments/k9/record-detail.php?type=training&id=' . $activityId);
}

$aidRows = $existingAids ?: [['training_aid_id' => '', 'amount_grams' => '']];
page_header($id > 0 ? 'Edit K-9 Training' : 'Add K-9 Training');
?>
<main class="shell">
    <section class="panel">
        <h1><?= $id > 0 ? 'Edit Training' : 'Add Training' ?></h1>
        <p>Record K-9 training activity.</p>
        <?php k9_navigation('activity-edit'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$teams): ?>
            <div class="notice error"><?= $isManager ? 'Create an active K-9 team before entering activity.' : 'Your account is not connected to an active K-9 team yet.' ?></div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <label>
                K-9 team
                <select name="team_id" required>
                    <option value="">Select team</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= e((string) $team['id']) ?>" <?= (int) ($record['team_id'] ?? 0) === (int) $team['id'] ? 'selected' : '' ?>><?= e($team['dog_name'] . ' - ' . $team['handler_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Training date
                <input type="date" name="activity_date" value="<?= e($record['activity_date'] ?? date('Y-m-d')) ?>" required>
            </label>
            <label>
                Training area
                <select name="training_area_id">
                    <option value="">Select area</option>
                    <?php foreach ($trainingAreas as $area): ?>
                        <option value="<?= e((string) $area['id']) ?>" <?= (int) ($record['training_area_id'] ?? 0) === (int) $area['id'] ? 'selected' : '' ?>><?= e($area['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Location
                <select name="location_id">
                    <option value="">Select location</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= e((string) $location['id']) ?>" <?= (int) ($record['location_id'] ?? 0) === (int) $location['id'] ? 'selected' : '' ?>><?= e($location['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Indication
                <select name="indication_id">
                    <option value="">Select indication</option>
                    <?php foreach ($indications as $indication): ?>
                        <option value="<?= e((string) $indication['id']) ?>" <?= (int) ($record['indication_id'] ?? 0) === (int) $indication['id'] ? 'selected' : '' ?>><?= e($indication['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Training hours
                <input name="training_hours" inputmode="decimal" value="<?= e(isset($record['training_hours']) ? number_format((float) $record['training_hours'], 2, '.', '') : '') ?>" placeholder="1.50">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_post_training" value="1" <?= (int) ($record['is_post_training'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>
                    POST training
                    <small>Include this activity in POST training totals.</small>
                </span>
            </label>

            <fieldset class="span-2">
                <legend>Training aids</legend>
                <div class="k9-aid-list" id="k9-aid-list">
                    <?php foreach ($aidRows as $aidRow): ?>
                    <div class="k9-aid-row">
                        <label>
                            Training aid
                            <select name="training_aid_ids[]" data-k9-aid-select>
                                <option value="">No aid selected</option>
                                <?php foreach ($trainingAids as $aid): ?>
                                    <option value="<?= e((string) $aid['id']) ?>" data-requires-grams="<?= strtolower((string) ($aid['category'] ?? '')) === 'drug' ? '1' : '0' ?>" <?= (int) ($aidRow['training_aid_id'] ?? 0) === (int) $aid['id'] ? 'selected' : '' ?>><?= e($aid['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label data-k9-grams-field hidden>
                            Grams used
                            <input name="aid_grams[]" inputmode="decimal" value="<?= e(isset($aidRow['amount_grams']) ? number_format((float) $aidRow['amount_grams'], 2, '.', '') : '') ?>" placeholder="0.00">
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="secondary compact-button" id="k9-add-aid">Add another aid</button>
            </fieldset>

            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($record['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$teams ? 'disabled' : '' ?>><?= $id > 0 ? 'Save changes' : 'Save training' ?></button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<script>
    const aidList = document.getElementById('k9-aid-list');
    const addAidButton = document.getElementById('k9-add-aid');

    const updateAidRow = (row) => {
        const select = row.querySelector('[data-k9-aid-select]');
        const gramsField = row.querySelector('[data-k9-grams-field]');
        const gramsInput = gramsField?.querySelector('input');
        const selected = select?.selectedOptions?.[0];
        const requiresGrams = selected?.dataset?.requiresGrams === '1';
        if (gramsField) {
            gramsField.hidden = !requiresGrams;
        }
        if (gramsInput && !requiresGrams) {
            gramsInput.value = '';
        }
    };

    const wireAidRow = (row) => {
        const select = row.querySelector('[data-k9-aid-select]');
        select?.addEventListener('change', () => updateAidRow(row));
        updateAidRow(row);
    };

    aidList?.querySelectorAll('.k9-aid-row').forEach(wireAidRow);

    addAidButton?.addEventListener('click', () => {
        const firstRow = aidList?.querySelector('.k9-aid-row');
        if (!firstRow || !aidList) {
            return;
        }

        const nextRow = firstRow.cloneNode(true);
        nextRow.querySelectorAll('select, input').forEach((field) => {
            field.value = '';
        });
        aidList.appendChild(nextRow);
        wireAidRow(nextRow);
    });
</script>
<?php page_footer(); ?>
