<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();

$classId = (int) ($_POST['class_id'] ?? 0);
$workerId = (int) ($_POST['worker_id'] ?? 0);
$currentWorker = current_election_worker();

if (!$currentWorker || (int) $currentWorker['id'] !== $workerId) {
    flash('error', 'You can only leave your own class signup.');
    redirect_to('departments/election/classes.php');
}

$statement = db()->prepare(
    'SELECT attended
     FROM election_training_registrations
     WHERE class_id = :class_id
       AND worker_id = :worker_id
     LIMIT 1'
);
$statement->execute([
    'class_id' => $classId,
    'worker_id' => $workerId,
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
       AND worker_id = :worker_id'
);
$statement->execute([
    'class_id' => $classId,
    'worker_id' => $workerId,
]);

audit_event('left_class', 'election_training_class', (string) $classId, ['worker_id' => $workerId]);
flash('success', 'You left the class. You can now choose another available class.');
redirect_to('departments/election/classes.php');
