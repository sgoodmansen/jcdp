<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);
if (!in_array($type, ['training', 'deployment', 'medical', 'expense'], true) || $id <= 0) {
    http_response_code(404);
    page_header('K-9 Record Not Found');
    echo '<main class="shell"><section class="panel"><h1>Record not found</h1><p>The selected K-9 record could not be found.</p></section></main>';
    page_footer();
    exit;
}

[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');
$params = array_merge($teamParams, ['id' => $id]);
$record = null;
$title = 'K-9 Record';
$editUrl = '';

if ($type === 'training' || $type === 'deployment') {
    $expectedType = $type === 'deployment' ? 'Deployed' : 'Training';
    $statement = db()->prepare(
        'SELECT k9_activity_logs.*,
                k9_dogs.dog_name,
                k9_handlers.handler_name,
                k9_activity_types.name AS activity_type,
                k9_training_areas.name AS training_area,
                k9_locations.name AS location_name,
                k9_indications.name AS indication,
                k9_incident_types.name AS incident_type,
                k9_assisting_agencies.name AS assisting_agency,
                k9_deployment_outcomes.name AS deployment_outcome
         FROM k9_activity_logs
         INNER JOIN k9_teams ON k9_teams.id = k9_activity_logs.team_id
         INNER JOIN k9_dogs ON k9_dogs.id = k9_activity_logs.dog_id
         INNER JOIN k9_handlers ON k9_handlers.id = k9_activity_logs.handler_id
         LEFT JOIN k9_activity_types ON k9_activity_types.id = k9_activity_logs.activity_type_id
         LEFT JOIN k9_training_areas ON k9_training_areas.id = k9_activity_logs.training_area_id
         LEFT JOIN k9_locations ON k9_locations.id = k9_activity_logs.location_id
         LEFT JOIN k9_indications ON k9_indications.id = k9_activity_logs.indication_id
         LEFT JOIN k9_incident_types ON k9_incident_types.id = k9_activity_logs.incident_type_id
         LEFT JOIN k9_assisting_agencies ON k9_assisting_agencies.id = k9_activity_logs.assisting_agency_id
         LEFT JOIN k9_deployment_outcomes ON k9_deployment_outcomes.id = k9_activity_logs.deployment_outcome_id
         WHERE k9_activity_logs.id = :id
           AND k9_activity_types.name = :activity_type' . $teamWhere
    );
    $params['activity_type'] = $expectedType;
    $statement->execute($params);
    $record = $statement->fetch();
    $title = $type === 'deployment' ? 'Deployment Detail' : 'Training Detail';
    $editUrl = $type === 'deployment'
        ? url('departments/k9/deployment-edit.php?id=' . $id)
        : url('departments/k9/activity-edit.php?id=' . $id);
} elseif ($type === 'medical') {
    $statement = db()->prepare(
        'SELECT k9_medical_visits.*,
                k9_dogs.dog_name,
                k9_handlers.handler_name
         FROM k9_medical_visits
         INNER JOIN k9_dogs ON k9_dogs.id = k9_medical_visits.dog_id
         INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
         INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
         WHERE k9_medical_visits.id = :id' . $teamWhere
    );
    $statement->execute($params);
    $record = $statement->fetch();
    $title = 'Medical Visit Detail';
    $editUrl = url('departments/k9/medical-edit.php?id=' . $id);
} else {
    $statement = db()->prepare(
        'SELECT k9_expenses.*,
                k9_dogs.dog_name,
                k9_handlers.handler_name,
                k9_expense_categories.name AS expense_category
         FROM k9_expenses
         INNER JOIN k9_dogs ON k9_dogs.id = k9_expenses.dog_id
         INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
         INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
         LEFT JOIN k9_expense_categories ON k9_expense_categories.id = k9_expenses.expense_category_id
         WHERE k9_expenses.id = :id' . $teamWhere
    );
    $statement->execute($params);
    $record = $statement->fetch();
    $title = 'Expense Detail';
    $editUrl = url('departments/k9/expense-edit.php?id=' . $id);
}

if (!$record) {
    http_response_code(404);
    page_header('K-9 Record Not Found');
    echo '<main class="shell"><section class="panel"><h1>Record not found</h1><p>The selected K-9 record could not be found.</p></section></main>';
    page_footer();
    exit;
}

$shots = [];
if ($type === 'medical') {
    $shotStatement = db()->prepare('SELECT * FROM k9_medical_shots WHERE medical_visit_id = :id ORDER BY shot_description');
    $shotStatement->execute(['id' => $id]);
    $shots = $shotStatement->fetchAll();
}

$detailRow = static function (string $label, string $value): void {
    ?>
    <div class="k9-detail-item">
        <dt><?= e($label) ?></dt>
        <dd><?= nl2br(e($value)) ?></dd>
    </div>
    <?php
};

page_header($title);
?>
<main class="shell">
    <section class="panel">
        <div class="section-heading-row">
            <div>
                <h1><?= e($title) ?></h1>
                <p><?= e($record['dog_name']) ?> - <?= e($record['handler_name']) ?></p>
            </div>
            <div class="actions">
                <a class="button" href="<?= e($editUrl) ?>">Edit</a>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php?record_type=' . urlencode($type))) ?>">Back to log</a>
            </div>
        </div>
        <?php k9_navigation($type === 'training' ? 'activity-edit' : ($type === 'deployment' ? 'deployment-edit' : ($type === 'medical' ? 'medical-edit' : 'expense-edit'))); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <dl class="k9-detail-list">
                <?php if ($type === 'training'): ?>
                    <?php $detailRow('Date', format_display_date($record['activity_date'])); ?>
                    <?php $detailRow('Training area', $record['training_area'] ?: 'Not set'); ?>
                    <?php $detailRow('Location', $record['location_name'] ?: 'Not set'); ?>
                    <?php $detailRow('Indication', $record['indication'] ?: 'Not set'); ?>
                    <?php $detailRow('Hours', number_format((float) $record['training_hours'], 2)); ?>
                    <?php $detailRow('POST training', (int) $record['is_post_training'] === 1 ? 'Yes' : 'No'); ?>
                    <?php $detailRow('Packaging', $record['packaging'] ?: ''); ?>
                    <?php $detailRow('Location of hide', $record['location_of_hide'] ?: ''); ?>
                    <?php $detailRow('Delay', $record['delay_description'] ?: ''); ?>
                    <?php $detailRow('Search time', $record['search_time'] ?: ''); ?>
                    <?php $detailRow("Dog's performance", $record['dog_performance'] ?: ''); ?>
                    <?php $detailRow('Problems / corrections', $record['problems_corrections'] ?: ''); ?>
                    <?php $detailRow('Additional notes', $record['notes'] ?: ''); ?>
                <?php elseif ($type === 'deployment'): ?>
                    <?php $detailRow('Date', format_display_date($record['activity_date'])); ?>
                    <?php $detailRow('Incident number', $record['incident_number'] ?: ''); ?>
                    <?php $detailRow('Incident type', $record['incident_type'] ?: 'Not set'); ?>
                    <?php $detailRow('Assisting agency', $record['assisting_agency'] ?: 'Not set'); ?>
                    <?php $detailRow('K-9 indication', $record['indication'] ?: 'Not set'); ?>
                    <?php $detailRow('Outcome', $record['deployment_outcome'] ?: 'Not set'); ?>
                    <?php $detailRow('Hours', number_format((float) $record['training_hours'], 2)); ?>
                    <?php $detailRow('Narrative / notes', $record['notes'] ?: ''); ?>
                <?php elseif ($type === 'medical'): ?>
                    <?php $detailRow('Visit date', format_display_date($record['visit_date'])); ?>
                    <?php $detailRow('Vet office', $record['vet_office_name'] ?: ''); ?>
                    <?php $detailRow('Doctor', $record['doctor_name'] ?: ''); ?>
                    <?php $detailRow('Reason', $record['reason_for_visit'] ?: ''); ?>
                    <?php $detailRow('Next appointment', trim(($record['next_appointment_date'] ? format_display_date($record['next_appointment_date']) : '') . ' ' . ($record['next_appointment_time'] ?: ''))); ?>
                    <?php $detailRow('Next appointment status', $record['next_appointment_scheduled'] ?: ''); ?>
                    <?php $detailRow('Notes', $record['notes'] ?: ''); ?>
                <?php else: ?>
                    <?php $detailRow('Expense date', format_display_date($record['expense_date'])); ?>
                    <?php $detailRow('Category', $record['expense_category'] ?: 'Not set'); ?>
                    <?php $detailRow('Amount', k9_money($record['amount'])); ?>
                    <?php $detailRow('Vendor', $record['vendor'] ?: ''); ?>
                    <?php $detailRow('Notes', $record['notes'] ?: ''); ?>
                <?php endif; ?>
        </dl>
    </section>

    <?php if ($type === 'medical'): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Shots / Vaccinations</h1>
            <table class="table mobile-card-table">
                <thead><tr><th>Description</th><th>Expiration</th></tr></thead>
                <tbody>
                    <?php foreach ($shots as $shot): ?>
                        <tr>
                            <td data-label="Description"><?= e($shot['shot_description']) ?></td>
                            <td data-label="Expiration"><?= e($shot['shot_expiration'] ? format_display_date($shot['shot_expiration']) : '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$shots): ?>
                        <tr><td colspan="2">No shots were entered for this visit.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
