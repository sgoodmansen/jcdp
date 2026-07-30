<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();

$classId = (int) ($_POST['class_id'] ?? 0);
$workerId = (int) ($_POST['worker_id'] ?? 0);
$currentWorker = current_election_worker();
$portalUser = current_user();

$workerStatement = db()->prepare('SELECT * FROM election_workers WHERE id = :id AND is_active = 1');
$workerStatement->execute(['id' => $workerId]);
$worker = $workerStatement->fetch();

$classStatement = db()->prepare(
    'SELECT election_training_classes.*
     FROM election_training_classes
     WHERE id = :id
       AND is_cancelled = 0'
);
$classStatement->execute(['id' => $classId]);
$class = $classStatement->fetch();

if (!$worker || !$class) {
    flash('error', 'Unable to sign up for that class.');
    redirect_to('departments/election/classes.php');
}

$canRegister = false;
if (can_manage_election_module()) {
    $canRegister = true;
} elseif ($currentWorker) {
    $isSelf = (int) $currentWorker['id'] === (int) $worker['id'];
    $isChief = (int) $currentWorker['is_chief_judge'] === 1 || (int) $currentWorker['is_assistant_chief_judge'] === 1;
    $sameScope = (int) $currentWorker['precinct_id'] === (int) $worker['precinct_id']
        && (int) $currentWorker['election_period_id'] === (int) $worker['election_period_id'];
    $canRegister = $isSelf || ($isChief && $sameScope);
}

$allowedPositionIds = election_class_allowed_position_ids($classId);
if (!$canRegister || !in_array((int) $worker['position_id'], $allowedPositionIds, true)) {
    flash('error', 'That worker is not eligible for this class.');
    redirect_to('departments/election/classes.php');
}

$existingStatement = db()->prepare(
    'SELECT election_training_classes.id, election_training_classes.class_title
     FROM election_training_registrations
     INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
     WHERE election_training_registrations.worker_id = :worker_id
       AND election_training_classes.election_period_id = :election_period_id
       AND election_training_classes.id <> :class_id
     LIMIT 1'
);
$existingStatement->execute([
    'worker_id' => $workerId,
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
    'INSERT IGNORE INTO election_training_registrations (class_id, worker_id, registered_by_user_id, registered_by_worker_id)
     VALUES (:class_id, :worker_id, :registered_by_user_id, :registered_by_worker_id)'
);
$statement->execute([
    'class_id' => $classId,
    'worker_id' => $workerId,
    'registered_by_user_id' => $portalUser['id'] ?? null,
    'registered_by_worker_id' => $currentWorker['id'] ?? null,
]);

audit_event('registered', 'election_training_class', (string) $classId, ['worker_id' => $workerId]);
flash('success', 'Class signup saved.');
redirect_to('departments/election/class-detail.php?id=' . $classId);
