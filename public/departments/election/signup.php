<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$classId = (int) ($_POST['class_id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$currentWorker = current_election_worker();
$currentAssignment = current_election_assignment();
$portalUser = current_user();

$assignmentStatement = db()->prepare(
    'SELECT election_worker_assignments.*,
            election_workers.first_name,
            election_workers.last_name,
            election_workers.is_active AS worker_is_active,
            election_positions.is_chief_judge,
            election_positions.is_assistant_chief_judge,
            CASE WHEN election_precinct_roles.assignment_id IS NULL THEN 0 ELSE 1 END AS is_assistant_chief_judge_extra
     FROM election_worker_assignments
     INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
     INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
     LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
        AND election_precinct_roles.role_key = "' . ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE . '"
     WHERE election_worker_assignments.id = :id
       AND election_worker_assignments.is_active = 1'
);
$assignmentStatement->execute([
    'id' => $assignmentId,
]);
$assignment = $assignmentStatement->fetch();
$workerId = (int) ($assignment['worker_id'] ?? 0);
$worker = $assignment && (int) $assignment['worker_is_active'] === 1 ? $assignment : null;

$classStatement = db()->prepare(
    'SELECT election_training_classes.*
     FROM election_training_classes
     WHERE id = :id
       AND is_cancelled = 0'
);
$classStatement->execute(['id' => $classId]);
$class = $classStatement->fetch();

if (!$worker || !$assignment || !$class) {
    flash('error', 'Unable to sign up for that class.');
    redirect_to('departments/election/classes.php');
}

if ((int) $assignment['election_period_id'] !== (int) $class['election_period_id']) {
    flash('error', 'That worker assignment is not eligible for this election class.');
    redirect_to('departments/election/classes.php');
}

$canRegister = false;
if (can_manage_election_module()) {
    $canRegister = true;
} elseif ($currentWorker) {
    $isSelf = (int) $currentWorker['id'] === (int) $worker['id'];
    $isChief = $currentAssignment && election_assignment_has_chief_permissions($currentAssignment);
    $sameScope = $currentAssignment
        && (int) $currentAssignment['precinct_id'] === (int) $assignment['precinct_id']
        && (int) $currentAssignment['election_period_id'] === (int) $assignment['election_period_id'];
    $canRegister = $isSelf || ($isChief && $sameScope);
}

$allowedPositionIds = election_class_allowed_position_ids($classId);
if (!$canRegister || !array_intersect(election_assignment_training_position_ids($assignment), $allowedPositionIds)) {
    flash('error', 'That worker is not eligible for this class.');
    redirect_to('departments/election/classes.php');
}

$existingStatement = db()->prepare(
    'SELECT election_training_classes.id, election_training_classes.class_title
     FROM election_training_registrations
     INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
     WHERE election_training_registrations.assignment_id = :assignment_id
       AND election_training_classes.election_period_id = :election_period_id
       AND election_training_classes.id <> :class_id
     LIMIT 1'
);
$existingStatement->execute([
    'assignment_id' => $assignmentId,
    'election_period_id' => (int) $class['election_period_id'],
    'class_id' => $classId,
]);
$existingRegistration = $existingStatement->fetch();

if ($existingRegistration) {
    flash('error', 'This worker is already signed up for ' . $existingRegistration['class_title'] . '. Leave that class before selecting another.');
    redirect_to('departments/election/classes.php');
}

$countStatement = db()->prepare('SELECT COUNT(*) FROM election_training_registrations WHERE class_id = :class_id');
$countStatement->execute(['class_id' => $classId]);
if ((int) $countStatement->fetchColumn() >= (int) $class['seats_total']) {
    flash('error', 'That class is full.');
    redirect_to('departments/election/classes.php');
}

$statement = db()->prepare(
    'INSERT IGNORE INTO election_training_registrations (class_id, worker_id, assignment_id, registered_by_user_id, registered_by_assignment_id)
     VALUES (:class_id, :worker_id, :assignment_id, :registered_by_user_id, :registered_by_assignment_id)'
);
$statement->execute([
    'class_id' => $classId,
    'worker_id' => $workerId,
    'assignment_id' => $assignmentId,
    'registered_by_user_id' => $portalUser['id'] ?? null,
    'registered_by_assignment_id' => $currentAssignment['id'] ?? null,
]);

audit_event('registered', 'election_training_class', (string) $classId, ['worker_id' => $workerId]);
flash('success', 'Class signup saved.');
redirect_to('departments/election/class-detail.php?id=' . $classId);
