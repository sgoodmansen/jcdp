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
        redirect_to('departments/k9/activity-edit.php');
    }

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
    $statement->execute([
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
    ]);

    $activityId = (int) db()->lastInsertId();
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

    audit_event('created', 'k9_activity_log', (string) $activityId);
    flash('success', 'K-9 training saved.');
    redirect_to('departments/k9/activity.php');
}

page_header('Add K-9 Training');
?>
<main class="shell">
    <section class="panel">
        <h1>Add Training</h1>
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
                        <option value="<?= e((string) $team['id']) ?>"><?= e($team['dog_name'] . ' - ' . $team['handler_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Training date
                <input type="date" name="activity_date" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>
                Training area
                <select name="training_area_id">
                    <option value="">Select area</option>
                    <?php foreach ($trainingAreas as $area): ?>
                        <option value="<?= e((string) $area['id']) ?>"><?= e($area['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Location
                <select name="location_id">
                    <option value="">Select location</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= e((string) $location['id']) ?>"><?= e($location['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Indication
                <select name="indication_id">
                    <option value="">Select indication</option>
                    <?php foreach ($indications as $indication): ?>
                        <option value="<?= e((string) $indication['id']) ?>"><?= e($indication['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Training hours
                <input name="training_hours" inputmode="decimal" placeholder="1.50">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_post_training" value="1">
                <span class="toggle-track" aria-hidden="true"></span>
                <span>
                    POST training
                    <small>Include this activity in POST training totals.</small>
                </span>
            </label>

            <fieldset class="span-2">
                <legend>Training aids</legend>
                <div class="k9-aid-list" id="k9-aid-list">
                    <div class="k9-aid-row">
                        <label>
                            Training aid
                            <select name="training_aid_ids[]" data-k9-aid-select>
                                <option value="">No aid selected</option>
                                <?php foreach ($trainingAids as $aid): ?>
                                    <option value="<?= e((string) $aid['id']) ?>" data-requires-grams="<?= strtolower((string) ($aid['category'] ?? '')) === 'drug' ? '1' : '0' ?>"><?= e($aid['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label data-k9-grams-field hidden>
                            Grams used
                            <input name="aid_grams[]" inputmode="decimal" placeholder="0.00">
                        </label>
                    </div>
                </div>
                <button type="button" class="secondary compact-button" id="k9-add-aid">Add another aid</button>
            </fieldset>

            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$teams ? 'disabled' : '' ?>>Save training</button>
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
