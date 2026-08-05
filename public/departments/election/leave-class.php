<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$classId = (int) ($_POST['class_id'] ?? 0);
$workerId = (int) ($_POST['worker_id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$currentWorker = current_election_worker();
$currentAssignment = current_election_assignment();
$isManager = can_manage_election_module();
$canRemoveAsSupervisor = $isManager && !$currentWorker;

$statement = db()->prepare(
    'SELECT election_training_registrations.attended,
            election_training_classes.election_period_id,
            election_worker_assignments.precinct_id,
            election_worker_assignments.worker_id
     FROM election_training_registrations
     INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
     INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_training_registrations.assignment_id
     WHERE election_training_registrations.class_id = :class_id
       AND election_training_registrations.worker_id = :worker_id
       AND election_training_registrations.assignment_id = :assignment_id
     LIMIT 1'
);
$statement->execute([
    'class_id' => $classId,
    'worker_id' => $workerId,
    'assignment_id' => $assignmentId,
]);
$registration = $statement->fetch();

if (!$registration) {
    flash('error', 'Class signup was not found.');
    redirect_to('departments/election/classes.php');
}

$isSelfRemoval = $currentWorker
    && $currentAssignment
    && (int) $currentWorker['id'] === $workerId
    && (int) $currentAssignment['id'] === $assignmentId;
$isChiefRemoval = $currentAssignment
    && election_assignment_has_chief_permissions($currentAssignment)
    && (int) $currentAssignment['election_period_id'] === (int) $registration['election_period_id']
    && (int) $currentAssignment['precinct_id'] === (int) $registration['precinct_id'];

if (!$isSelfRemoval && !$canRemoveAsSupervisor && !$isChiefRemoval) {
    flash('error', 'You do not have permission to remove that class signup.');
    redirect_to('departments/election/classes.php');
}

if ((int) $registration['attended'] === 1) {
    flash('error', 'Completed training cannot be removed.');
    redirect_to('departments/election/class-detail.php?id=' . $classId);
}

$statement = db()->prepare(
    'DELETE FROM election_training_registrations
     WHERE class_id = :class_id
       AND worker_id = :worker_id
       AND assignment_id = :assignment_id'
);
$statement->execute([
    'class_id' => $classId,
    'worker_id' => $workerId,
    'assignment_id' => $assignmentId,
]);

audit_event($isSelfRemoval ? 'left_class' : 'removed_class_signup', 'election_training_class', (string) $classId, [
    'worker_id' => $workerId,
    'assignment_id' => $assignmentId,
]);
flash('success', $isSelfRemoval ? 'You left the class. You can now choose another available class.' : 'Class signup removed.');
redirect_to('departments/election/class-detail.php?id=' . $classId);
