<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$classId = (int) ($_POST['class_id'] ?? 0);
$workerId = (int) ($_POST['worker_id'] ?? 0);
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$currentWorker = current_election_worker();
$currentAssignment = current_election_assignment();

if (!$currentWorker || !$currentAssignment || (int) $currentWorker['id'] !== $workerId || (int) $currentAssignment['id'] !== $assignmentId) {
    flash('error', 'You can only leave your own class signup.');
    redirect_to('departments/election/classes.php');
}

$statement = db()->prepare(
    'SELECT attended
     FROM election_training_registrations
     WHERE class_id = :class_id
       AND worker_id = :worker_id
       AND assignment_id = :assignment_id
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

audit_event('left_class', 'election_training_class', (string) $classId, ['worker_id' => $workerId, 'assignment_id' => $assignmentId]);
flash('success', 'You left the class. You can now choose another available class.');
redirect_to('departments/election/classes.php');
