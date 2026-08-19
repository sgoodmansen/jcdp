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

$deploymentActivityTypeId = k9_lookup_id_by_name('k9_activity_types', 'Deployed');
$indications = k9_lookup_options('k9_indications');
$incidentTypes = k9_lookup_options('k9_incident_types');
$agencies = k9_lookup_options('k9_assisting_agencies');
$outcomes = k9_lookup_options('k9_deployment_outcomes');

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
        redirect_to('departments/k9/deployment-edit.php');
    }

    $statement = db()->prepare(
        'INSERT INTO k9_activity_logs (
            team_id, dog_id, handler_id, activity_date, activity_type_id, location_id, training_area_id, indication_id,
            training_hours, is_post_training, incident_number, incident_type_id, assisting_agency_id, arrest_made,
            deployment_outcome_id, notes, created_by_user_id
        ) VALUES (
            :team_id, :dog_id, :handler_id, :activity_date, :activity_type_id, :location_id, NULL, :indication_id,
            :training_hours, 0, :incident_number, :incident_type_id, :assisting_agency_id, :arrest_made,
            :deployment_outcome_id, :notes, :created_by_user_id
        )'
    );
    $statement->execute([
        'team_id' => $teamId,
        'dog_id' => (int) $selectedTeam['dog_id'],
        'handler_id' => (int) $selectedTeam['handler_id'],
        'activity_date' => $_POST['activity_date'] ?? date('Y-m-d'),
        'activity_type_id' => $deploymentActivityTypeId,
        'location_id' => null,
        'indication_id' => (int) ($_POST['indication_id'] ?? 0) ?: null,
        'training_hours' => k9_decimal($_POST['deployment_hours'] ?? '0'),
        'incident_number' => trim($_POST['incident_number'] ?? '') ?: null,
        'incident_type_id' => (int) ($_POST['incident_type_id'] ?? 0) ?: null,
        'assisting_agency_id' => (int) ($_POST['assisting_agency_id'] ?? 0) ?: null,
        'arrest_made' => 0,
        'deployment_outcome_id' => (int) ($_POST['deployment_outcome_id'] ?? 0) ?: null,
        'notes' => trim($_POST['notes'] ?? ''),
        'created_by_user_id' => current_user()['id'] ?? null,
    ]);

    $activityId = (int) db()->lastInsertId();
    audit_event('created', 'k9_deployment_log', (string) $activityId);
    flash('success', 'K-9 deployment saved.');
    redirect_to('departments/k9/activity.php');
}

page_header('Add K-9 Deployment');
?>
<main class="shell">
    <section class="panel">
        <h1>Add Deployment</h1>
        <p>Record K-9 deployment details separately from training activity.</p>
        <?php k9_navigation('deployment-edit'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$teams): ?>
            <div class="notice error"><?= $isManager ? 'Create an active K-9 team before entering deployments.' : 'Your account is not connected to an active K-9 team yet.' ?></div>
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
                Deployment date
                <input type="date" name="activity_date" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>
                Incident number
                <input name="incident_number">
            </label>
            <label>
                Incident type
                <select name="incident_type_id">
                    <option value="">Select incident type</option>
                    <?php foreach ($incidentTypes as $type): ?>
                        <option value="<?= e((string) $type['id']) ?>"><?= e($type['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Assisting agency
                <select name="assisting_agency_id">
                    <option value="">Select agency</option>
                    <?php foreach ($agencies as $agency): ?>
                        <option value="<?= e((string) $agency['id']) ?>"><?= e($agency['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                K-9 indication
                <select name="indication_id">
                    <option value="">Select indication</option>
                    <?php foreach ($indications as $indication): ?>
                        <option value="<?= e((string) $indication['id']) ?>"><?= e($indication['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Outcome
                <select name="deployment_outcome_id">
                    <option value="">Select outcome</option>
                    <?php foreach ($outcomes as $outcome): ?>
                        <option value="<?= e((string) $outcome['id']) ?>"><?= e($outcome['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Deployment hours
                <input name="deployment_hours" inputmode="decimal" placeholder="0.75">
            </label>
            <label class="span-2">
                Narrative / notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$teams ? 'disabled' : '' ?>>Save deployment</button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
