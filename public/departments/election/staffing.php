<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();

$currentAssignment = current_election_assignment();
$isManager = can_manage_election_module();

$periods = $isManager
    ? db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll()
    : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['election_period_id']]);
    $periods = array_filter([$statement->fetch() ?: null]);
}

$selectedPeriodId = (int) ($_REQUEST['election_period_id'] ?? 0);
if ($selectedPeriodId === 0) {
    foreach ($periods as $period) {
        if ((int) $period['is_active'] === 1) {
            $selectedPeriodId = (int) $period['id'];
            break;
        }
    }
}
if ($selectedPeriodId === 0 && $periods) {
    $selectedPeriodId = (int) $periods[0]['id'];
}

$allowedPeriodIds = array_map(fn($period) => (int) $period['id'], $periods);
if ($selectedPeriodId > 0 && !in_array($selectedPeriodId, $allowedPeriodIds, true)) {
    $selectedPeriodId = (int) ($allowedPeriodIds[0] ?? 0);
}

$precincts = $isManager ? election_precincts() : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_precincts WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['precinct_id']]);
    $precincts = array_filter([$statement->fetch() ?: null]);
}

$selectedPrecinctId = (int) ($_REQUEST['precinct_id'] ?? 0);
$allowedPrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $precincts);
if ($selectedPrecinctId === 0 || !in_array($selectedPrecinctId, $allowedPrecinctIds, true)) {
    $selectedPrecinctId = (int) ($allowedPrecinctIds[0] ?? 0);
}

$allPositions = election_positions();
$assistantPositionIds = [];
$chiefPositionIds = [];
$positions = [];
foreach ($allPositions as $position) {
    if ((int) $position['is_assistant_chief_judge'] === 1) {
        $assistantPositionIds[] = (int) $position['id'];
        continue;
    }
    if ((int) $position['is_chief_judge'] === 1) {
        $chiefPositionIds[] = (int) $position['id'];
    }
    $positions[] = $position;
}
$positionIds = array_map(fn($position) => (int) $position['id'], $positions);
$chiefPositionLookup = array_fill_keys($chiefPositionIds, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_assistant_chief') {
    $periodId = (int) ($_POST['election_period_id'] ?? 0);
    $precinctId = (int) ($_POST['precinct_id'] ?? 0);
    $assistantAssignmentId = (int) ($_POST['assistant_assignment_id'] ?? 0);

    if (!in_array($periodId, $allowedPeriodIds, true) || !in_array($precinctId, $allowedPrecinctIds, true)) {
        flash('error', 'That Assistant Chief Judge change is not allowed.');
        redirect_to('departments/election/staffing.php');
    }

    if ($assistantAssignmentId > 0) {
        $statement = db()->prepare(
            'SELECT election_worker_assignments.id
             FROM election_worker_assignments
             INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
             WHERE election_worker_assignments.id = :assignment_id
               AND election_worker_assignments.election_period_id = :election_period_id
               AND election_worker_assignments.precinct_id = :precinct_id
               AND election_worker_assignments.is_active = 1
               AND election_positions.is_chief_judge = 0
               AND election_positions.is_assistant_chief_judge = 0
             LIMIT 1'
        );
        $statement->execute([
            'assignment_id' => $assistantAssignmentId,
            'election_period_id' => $periodId,
            'precinct_id' => $precinctId,
        ]);

        if (!$statement->fetch()) {
            flash('error', 'Select a worker already assigned to this precinct, excluding the Chief Judge.');
            redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
        }

        $statement = db()->prepare(
            'INSERT INTO election_precinct_roles (
                election_period_id, precinct_id, role_key, assignment_id, created_by_user_id, updated_by_user_id
             )
             VALUES (
                :election_period_id, :precinct_id, :role_key, :assignment_id, :created_by_user_id, :updated_by_user_id
             )
             ON DUPLICATE KEY UPDATE
                assignment_id = VALUES(assignment_id),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()'
        );
        $statement->execute([
            'election_period_id' => $periodId,
            'precinct_id' => $precinctId,
            'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
            'assignment_id' => $assistantAssignmentId,
            'created_by_user_id' => current_user()['id'] ?? null,
            'updated_by_user_id' => current_user()['id'] ?? null,
        ]);
    } else {
        $statement = db()->prepare(
            'DELETE FROM election_precinct_roles
             WHERE election_period_id = :election_period_id
               AND precinct_id = :precinct_id
               AND role_key = :role_key'
        );
        $statement->execute([
            'election_period_id' => $periodId,
            'precinct_id' => $precinctId,
            'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
        ]);
    }

    audit_event($assistantAssignmentId > 0 ? 'assigned_assistant_chief' : 'cleared_assistant_chief', 'election_precinct', (string) $precinctId, [
        'election_period_id' => $periodId,
        'assignment_id' => $assistantAssignmentId,
    ]);
    flash('success', $assistantAssignmentId > 0 ? 'Assistant Chief Judge responsibility saved.' : 'Assistant Chief Judge responsibility cleared.');
    redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_slot') {
    $periodId = (int) ($_POST['election_period_id'] ?? 0);
    $precinctId = (int) ($_POST['precinct_id'] ?? 0);
    $positionId = (int) ($_POST['position_id'] ?? 0);
    $workerId = (int) ($_POST['worker_id'] ?? 0);

    if (!in_array($periodId, $allowedPeriodIds, true)
        || !in_array($precinctId, $allowedPrecinctIds, true)
        || !in_array($positionId, $positionIds, true)) {
        flash('error', 'That staffing change is not allowed.');
        redirect_to('departments/election/staffing.php');
    }

    if ($workerId > 0) {
        $statement = db()->prepare(
            'SELECT 1
             FROM election_worker_assignments
             WHERE worker_id = :worker_id
               AND election_period_id = :election_period_id
               AND is_active = 1
               AND NOT (precinct_id = :precinct_id AND position_id = :position_id)
             LIMIT 1'
        );
        $statement->execute([
            'worker_id' => $workerId,
            'election_period_id' => $periodId,
            'precinct_id' => $precinctId,
            'position_id' => $positionId,
        ]);

        if ($statement->fetch()) {
            flash('error', 'That worker already has a position in this election.');
            redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
        }
    }

    db()->beginTransaction();

    $statement = db()->prepare(
        'UPDATE election_precinct_roles
         INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_precinct_roles.assignment_id
         SET election_precinct_roles.assignment_id = NULL,
             election_precinct_roles.updated_by_user_id = :updated_by_user_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.precinct_id = :precinct_id
           AND election_worker_assignments.position_id = :position_id
           AND election_worker_assignments.is_extra = 0
           AND election_worker_assignments.is_active = 1
           AND (:worker_id_zero = 0 OR election_worker_assignments.worker_id <> :worker_id_compare)
           AND election_precinct_roles.role_key = :role_key'
    );
    $statement->execute([
        'updated_by_user_id' => current_user()['id'] ?? null,
        'election_period_id' => $periodId,
        'precinct_id' => $precinctId,
        'position_id' => $positionId,
        'worker_id_zero' => $workerId,
        'worker_id_compare' => $workerId,
        'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
    ]);

    $statement = db()->prepare(
        'UPDATE election_worker_assignments
         SET is_active = 0
         WHERE election_period_id = :election_period_id
           AND precinct_id = :precinct_id
           AND position_id = :position_id
           AND is_extra = 0
           AND is_active = 1'
    );
    $statement->execute([
        'election_period_id' => $periodId,
        'precinct_id' => $precinctId,
        'position_id' => $positionId,
    ]);

    if ($workerId > 0) {
        $statement = db()->prepare('UPDATE election_workers SET availability_status = :availability_status, unavailable_reason = "", is_active = 1 WHERE id = :id');
        $statement->execute([
            'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
            'id' => $workerId,
        ]);

        $statement = db()->prepare(
            'INSERT INTO election_worker_assignments (
                worker_id, election_period_id, precinct_id, position_id, recruited_by_assignment_id,
                created_by_user_id, is_extra, is_active, notes
             )
             VALUES (
                :worker_id, :election_period_id, :precinct_id, :position_id, :recruited_by_assignment_id,
                :created_by_user_id, 0, 1, NULL
             )
             ON DUPLICATE KEY UPDATE
                is_extra = 0,
                is_active = 1,
                updated_at = NOW()'
        );
        $statement->execute([
            'worker_id' => $workerId,
            'election_period_id' => $periodId,
            'precinct_id' => $precinctId,
            'position_id' => $positionId,
            'recruited_by_assignment_id' => $currentAssignment['id'] ?? null,
            'created_by_user_id' => current_user()['id'] ?? null,
        ]);

        if (in_array($positionId, $chiefPositionIds, true)) {
            $statement = db()->prepare(
                'UPDATE election_precinct_roles
                 INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_precinct_roles.assignment_id
                 SET election_precinct_roles.assignment_id = NULL,
                     election_precinct_roles.updated_by_user_id = :updated_by_user_id
                 WHERE election_precinct_roles.election_period_id = :election_period_id
                   AND election_precinct_roles.precinct_id = :precinct_id
                   AND election_precinct_roles.role_key = :role_key
                   AND election_worker_assignments.worker_id = :worker_id'
            );
            $statement->execute([
                'updated_by_user_id' => current_user()['id'] ?? null,
                'election_period_id' => $periodId,
                'precinct_id' => $precinctId,
                'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
                'worker_id' => $workerId,
            ]);
        }
    }

    db()->commit();

    audit_event($workerId > 0 ? 'staffed_position' : 'cleared_position', 'election_precinct', (string) $precinctId, [
        'election_period_id' => $periodId,
        'position_id' => $positionId,
        'worker_id' => $workerId,
    ]);
    flash('success', $workerId > 0 ? 'Position assignment saved.' : 'Position cleared.');
    redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_extra_slot') {
    $periodId = (int) ($_POST['election_period_id'] ?? 0);
    $precinctId = (int) ($_POST['precinct_id'] ?? 0);
    $positionId = (int) ($_POST['position_id'] ?? 0);
    $workerId = (int) ($_POST['extra_worker_id'] ?? 0);

    if (!in_array($periodId, $allowedPeriodIds, true)
        || !in_array($precinctId, $allowedPrecinctIds, true)
        || !in_array($positionId, $positionIds, true)
        || isset($chiefPositionLookup[$positionId])) {
        flash('error', 'That extra worker change is not allowed.');
        redirect_to('departments/election/staffing.php');
    }

    if ($workerId <= 0) {
        flash('error', 'Select a worker before adding an extra worker.');
        redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
    }

    $statement = db()->prepare(
        'SELECT 1
         FROM election_worker_assignments
         WHERE worker_id = :worker_id
           AND election_period_id = :election_period_id
           AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([
        'worker_id' => $workerId,
        'election_period_id' => $periodId,
    ]);

    if ($statement->fetch()) {
        flash('error', 'That worker already has a position in this election.');
        redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
    }

    db()->beginTransaction();

    $statement = db()->prepare('UPDATE election_workers SET availability_status = :availability_status, unavailable_reason = "", is_active = 1 WHERE id = :id');
    $statement->execute([
        'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
        'id' => $workerId,
    ]);

    $statement = db()->prepare(
        'INSERT INTO election_worker_assignments (
            worker_id, election_period_id, precinct_id, position_id, recruited_by_assignment_id,
            created_by_user_id, is_extra, is_active, notes
         )
         VALUES (
            :worker_id, :election_period_id, :precinct_id, :position_id, :recruited_by_assignment_id,
            :created_by_user_id, 1, 1, NULL
         )
         ON DUPLICATE KEY UPDATE
            is_extra = 1,
            is_active = 1,
            updated_at = NOW()'
    );
    $statement->execute([
        'worker_id' => $workerId,
        'election_period_id' => $periodId,
        'precinct_id' => $precinctId,
        'position_id' => $positionId,
        'recruited_by_assignment_id' => $currentAssignment['id'] ?? null,
        'created_by_user_id' => current_user()['id'] ?? null,
    ]);

    db()->commit();

    audit_event('staffed_extra_position', 'election_precinct', (string) $precinctId, [
        'election_period_id' => $periodId,
        'position_id' => $positionId,
        'worker_id' => $workerId,
    ]);
    flash('success', 'Extra worker added.');
    redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_extra_slot') {
    $periodId = (int) ($_POST['election_period_id'] ?? 0);
    $precinctId = (int) ($_POST['precinct_id'] ?? 0);
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);

    if (!in_array($periodId, $allowedPeriodIds, true) || !in_array($precinctId, $allowedPrecinctIds, true) || $assignmentId <= 0) {
        flash('error', 'That extra worker change is not allowed.');
        redirect_to('departments/election/staffing.php');
    }

    $statement = db()->prepare(
        'SELECT election_worker_assignments.*
         FROM election_worker_assignments
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.id = :assignment_id
           AND election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.precinct_id = :precinct_id
           AND election_worker_assignments.is_extra = 1
           AND election_worker_assignments.is_active = 1
           AND election_positions.is_chief_judge = 0
         LIMIT 1'
    );
    $statement->execute([
        'assignment_id' => $assignmentId,
        'election_period_id' => $periodId,
        'precinct_id' => $precinctId,
    ]);
    $assignment = $statement->fetch();

    if (!$assignment) {
        flash('error', 'That extra worker could not be found.');
        redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
    }

    db()->beginTransaction();

    $statement = db()->prepare(
        'DELETE FROM election_precinct_roles
         WHERE assignment_id = :assignment_id
           AND role_key = :role_key'
    );
    $statement->execute([
        'assignment_id' => $assignmentId,
        'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
    ]);

    $statement = db()->prepare('UPDATE election_worker_assignments SET is_active = 0 WHERE id = :id');
    $statement->execute(['id' => $assignmentId]);

    db()->commit();

    audit_event('removed_extra_position', 'election_precinct', (string) $precinctId, [
        'election_period_id' => $periodId,
        'position_id' => (int) $assignment['position_id'],
        'worker_id' => (int) $assignment['worker_id'],
    ]);
    flash('success', 'Extra worker removed.');
    redirect_to('departments/election/staffing.php?election_period_id=' . $periodId . '&precinct_id=' . $precinctId);
}

$workerSearch = trim($_GET['worker_search'] ?? '');
$workerSql = 'SELECT id, first_name, last_name, email, phone, availability_status, is_active
              FROM election_workers
              WHERE availability_status = :availability_status
                AND is_active = 1
                AND NOT EXISTS (
                  SELECT 1
                  FROM election_worker_assignments
                  WHERE election_worker_assignments.worker_id = election_workers.id
                    AND election_worker_assignments.election_period_id = :worker_period_id
                    AND election_worker_assignments.is_active = 1
              )';
$workerParams = [
    'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
    'worker_period_id' => $selectedPeriodId,
];
if ($workerSearch !== '') {
    $workerSql .= ' AND (first_name LIKE :worker_search
                    OR last_name LIKE :worker_search
                    OR email LIKE :worker_search
                    OR phone LIKE :worker_search)';
    $workerParams['worker_search'] = '%' . $workerSearch . '%';
}
$workerSql .= ' ORDER BY is_active DESC, last_name, first_name LIMIT 400';
$statement = db()->prepare($workerSql);
$statement->execute($workerParams);
$availableWorkers = $statement->fetchAll();
$availableWorkerIds = array_map(fn($availableWorker) => (int) $availableWorker['id'], $availableWorkers);

$slotAssignments = [];
$extraSlotAssignments = [];
$allDisplayedAssignments = [];
$trainingStatusByAssignmentId = [];
$assistantChiefAssignment = null;
$assistantChiefOptions = [];
$filledCount = 0;
$extraWorkerCount = 0;
if ($selectedPeriodId > 0 && $selectedPrecinctId > 0) {
    $statement = db()->prepare(
        'SELECT election_worker_assignments.*,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.email,
                election_workers.phone,
                election_positions.name AS position_name,
                CASE WHEN election_precinct_roles.assignment_id IS NULL THEN 0 ELSE 1 END AS is_assistant_chief_judge_extra
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
            AND election_precinct_roles.role_key = :role_key
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.precinct_id = :precinct_id
           AND election_worker_assignments.is_active = 1
         ORDER BY election_worker_assignments.is_extra, election_positions.sort_order, election_workers.last_name, election_workers.first_name'
    );
    $statement->execute([
        'election_period_id' => $selectedPeriodId,
        'precinct_id' => $selectedPrecinctId,
        'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
    ]);

    foreach ($statement->fetchAll() as $assignment) {
        $allDisplayedAssignments[] = $assignment;
        $positionKey = (int) $assignment['position_id'];
        if ((int) ($assignment['is_extra'] ?? 0) === 1) {
            $extraSlotAssignments[$positionKey][] = $assignment;
            $extraWorkerCount++;
        } elseif (empty($slotAssignments[$positionKey])) {
            $slotAssignments[$positionKey] = $assignment;
        } else {
            $assignment['is_extra'] = 1;
            $extraSlotAssignments[$positionKey][] = $assignment;
            $extraWorkerCount++;
        }
    }

    $statement = db()->prepare(
        'SELECT election_precinct_roles.assignment_id,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.email,
                election_workers.phone,
                election_positions.name AS position_name
         FROM election_precinct_roles
         INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_precinct_roles.assignment_id
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_precinct_roles.election_period_id = :election_period_id
           AND election_precinct_roles.precinct_id = :precinct_id
           AND election_precinct_roles.role_key = :role_key
         LIMIT 1'
    );
    $statement->execute([
        'election_period_id' => $selectedPeriodId,
        'precinct_id' => $selectedPrecinctId,
        'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
    ]);
    $assistantChiefAssignment = $statement->fetch() ?: null;

    $statement = db()->prepare(
        'SELECT election_worker_assignments.id AS assignment_id,
                election_workers.first_name,
                election_workers.last_name,
                election_workers.email,
                election_workers.phone,
                election_positions.name AS position_name
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.precinct_id = :precinct_id
           AND election_worker_assignments.is_active = 1
           AND election_positions.is_chief_judge = 0
           AND election_positions.is_assistant_chief_judge = 0
         ORDER BY election_workers.last_name, election_workers.first_name'
    );
    $statement->execute([
        'election_period_id' => $selectedPeriodId,
        'precinct_id' => $selectedPrecinctId,
    ]);
    $assistantChiefOptions = $statement->fetchAll();
}

if ($selectedPeriodId > 0 && $allDisplayedAssignments) {
    $trainingClassesByPositionId = [];
    $statement = db()->prepare(
        'SELECT election_training_class_positions.position_id,
                election_training_classes.id,
                election_training_classes.class_title,
                election_training_classes.class_date,
                election_training_classes.start_time
         FROM election_training_classes
         INNER JOIN election_training_class_positions ON election_training_class_positions.class_id = election_training_classes.id
         WHERE election_training_classes.election_period_id = :election_period_id
           AND election_training_classes.is_cancelled = 0
         ORDER BY election_training_classes.class_date, election_training_classes.start_time'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    foreach ($statement->fetchAll() as $classRow) {
        $trainingClassesByPositionId[(int) $classRow['position_id']][] = $classRow;
    }

    $assignmentPlaceholders = [];
    $registrationParams = [];
    foreach ($allDisplayedAssignments as $index => $displayedAssignment) {
        $key = 'assignment_id_' . $index;
        $assignmentPlaceholders[] = ':' . $key;
        $registrationParams[$key] = (int) $displayedAssignment['id'];
    }

    $trainingRegistrationsByAssignmentId = [];
    $statement = db()->prepare(
        'SELECT election_training_registrations.assignment_id,
                election_training_registrations.attended,
                election_training_classes.class_title,
                election_training_classes.class_date,
                election_training_classes.start_time
         FROM election_training_registrations
         INNER JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
         WHERE election_training_registrations.assignment_id IN (' . implode(',', $assignmentPlaceholders) . ')
           AND election_training_classes.election_period_id = :election_period_id
           AND election_training_classes.is_cancelled = 0
         ORDER BY election_training_registrations.attended DESC,
                  election_training_classes.class_date,
                  election_training_classes.start_time'
    );
    $registrationParams['election_period_id'] = $selectedPeriodId;
    $statement->execute($registrationParams);
    foreach ($statement->fetchAll() as $registration) {
        $trainingRegistrationsByAssignmentId[(int) $registration['assignment_id']][] = $registration;
    }

    foreach ($allDisplayedAssignments as $displayedAssignment) {
        $trainingPositionIds = [(int) $displayedAssignment['position_id']];
        if ((int) ($displayedAssignment['is_assistant_chief_judge_extra'] ?? 0) === 1) {
            $trainingPositionIds = array_merge($trainingPositionIds, $assistantPositionIds);
        }
        $trainingPositionIds = array_values(array_unique(array_filter($trainingPositionIds)));

        $hasRequiredClass = false;
        foreach ($trainingPositionIds as $trainingPositionId) {
            if (!empty($trainingClassesByPositionId[$trainingPositionId])) {
                $hasRequiredClass = true;
                break;
            }
        }

        $registrations = $trainingRegistrationsByAssignmentId[(int) $displayedAssignment['id']] ?? [];
        $completedRegistration = null;
        $pendingRegistration = null;
        foreach ($registrations as $registration) {
            if ((int) $registration['attended'] === 1 && !$completedRegistration) {
                $completedRegistration = $registration;
            } elseif (!$pendingRegistration) {
                $pendingRegistration = $registration;
            }
        }

        if ($completedRegistration) {
            $trainingStatusByAssignmentId[(int) $displayedAssignment['id']] = [
                'label' => 'Training complete',
                'class' => 'badge-success',
                'detail' => $completedRegistration['class_title'] . ' - ' . format_display_date($completedRegistration['class_date']),
            ];
        } elseif ($pendingRegistration) {
            $trainingStatusByAssignmentId[(int) $displayedAssignment['id']] = [
                'label' => 'Signed up',
                'class' => 'badge-muted',
                'detail' => $pendingRegistration['class_title'] . ' - ' . format_display_date($pendingRegistration['class_date']) . ' ' . format_display_time($pendingRegistration['start_time']),
            ];
        } elseif ($hasRequiredClass) {
            $trainingStatusByAssignmentId[(int) $displayedAssignment['id']] = [
                'label' => 'Not signed up',
                'class' => 'badge-warning',
                'detail' => '',
            ];
        } else {
            $trainingStatusByAssignmentId[(int) $displayedAssignment['id']] = [
                'label' => 'No class set',
                'class' => 'badge-muted',
                'detail' => '',
            ];
        }
    }
}

foreach ($positions as $position) {
    if (!empty($slotAssignments[(int) $position['id']])) {
        $filledCount++;
    }
}

$selectedPrecinct = null;
foreach ($precincts as $precinct) {
    if ((int) $precinct['id'] === $selectedPrecinctId) {
        $selectedPrecinct = $precinct;
        break;
    }
}

$actions = [
    ['label' => 'Staffing Progress', 'href' => url('departments/election/staffing-progress.php?election_period_id=' . $selectedPeriodId), 'primary' => true],
    ['label' => 'Staffing Sheet', 'href' => url('departments/election/staffing-sheet.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $selectedPrecinctId)],
    ['label' => 'Bulk Email', 'href' => url('departments/election/bulk-email.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $selectedPrecinctId)],
    ['label' => 'Add contact', 'href' => url('departments/election/worker-edit.php')],
    ['label' => 'Address Book', 'href' => url('departments/election/workers.php')],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php')],
];

page_header('Precinct Staffing');
?>
<main class="shell">
    <section class="panel">
        <h1>Precinct Staffing</h1>
        <p>Fill each precinct position from the worker list.</p>
        <?php election_navigation('staffing'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Filters</h1>
        <form class="form compact-form" method="get">
            <label>
                Election
                <select name="election_period_id" required>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $selectedPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Precinct
                <select name="precinct_id" required>
                    <?php foreach ($precincts as $precinct): ?>
                        <option value="<?= e((string) $precinct['id']) ?>" <?= $selectedPrecinctId === (int) $precinct['id'] ? 'selected' : '' ?>>
                            <?= e($precinct['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Worker search
                <input name="worker_search" value="<?= e($workerSearch) ?>" placeholder="Narrow worker dropdowns">
            </label>
            <div class="actions">
                <button type="submit">View staffing</button>
                <a class="button secondary" href="<?= e(url('departments/election/staffing.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $selectedPrecinctId)) ?>">Clear search</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1><?= e(($selectedPrecinct['name'] ?? 'Selected precinct') . ' Positions') ?></h1>
                <p class="muted"><?= e((string) $filledCount) ?> of <?= e((string) count($positions)) ?> positions filled.</p>
            </div>
            <span class="badge <?= $filledCount === count($positions) ? 'badge-success' : 'badge-muted' ?>">
                <?= e((string) (count($positions) - $filledCount)) ?> open<?= $extraWorkerCount > 0 ? ' / ' . e((string) $extraWorkerCount) . ' extra' : '' ?>
            </span>
        </div>

        <section class="staffing-extra-role">
            <div>
                <h2>Assistant Chief Judge</h2>
                <p class="muted">Assign this responsibility to one worker already filling another position in this precinct.</p>
            </div>
            <form method="post" class="form compact-form">
                <input type="hidden" name="action" value="save_assistant_chief">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                <label>
                    Worker
                    <select name="assistant_assignment_id">
                        <option value="">No Assistant Chief Judge</option>
                        <?php foreach ($assistantChiefOptions as $assistantOption): ?>
                            <option value="<?= e((string) $assistantOption['assignment_id']) ?>" <?= (int) ($assistantChiefAssignment['assignment_id'] ?? 0) === (int) $assistantOption['assignment_id'] ? 'selected' : '' ?>>
                                <?= e(election_person_name($assistantOption)) ?> - <?= e($assistantOption['position_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="actions">
                    <button type="submit">Save responsibility</button>
                </div>
            </form>
            <?php if ($assistantChiefAssignment): ?>
                <p class="meta">
                    Current: <?= e(election_person_name($assistantChiefAssignment)) ?> - <?= e($assistantChiefAssignment['position_name']) ?>
                </p>
            <?php endif; ?>
        </section>

        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Assigned worker</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($positions as $position): ?>
                    <?php
                    $positionId = (int) $position['id'];
                    $currentSlotAssignment = $slotAssignments[$positionId] ?? null;
                    $currentWorkerId = (int) ($currentSlotAssignment['worker_id'] ?? 0);
                    $extraAssignments = $extraSlotAssignments[$positionId] ?? [];
                    $canAddExtraWorker = !isset($chiefPositionLookup[$positionId]);
                    $currentTrainingStatus = $currentSlotAssignment ? ($trainingStatusByAssignmentId[(int) $currentSlotAssignment['id']] ?? null) : null;
                    ?>
                    <tr>
                        <td data-label="Position"><?= e($position['name']) ?></td>
                        <td data-label="Assigned worker">
                            <form method="post" class="staffing-slot-form" id="staffing-slot-<?= e((string) $positionId) ?>">
                                <input type="hidden" name="action" value="save_slot">
                                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                                <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                                <input type="hidden" name="position_id" value="<?= e((string) $positionId) ?>">
                                <select name="worker_id">
                                    <option value="">Unassigned</option>
                                    <?php if ($currentSlotAssignment && !in_array($currentWorkerId, $availableWorkerIds, true)): ?>
                                        <option value="<?= e((string) $currentWorkerId) ?>" selected>
                                            <?= e(election_person_name($currentSlotAssignment)) ?>
                                        </option>
                                    <?php endif; ?>
                                    <?php foreach ($availableWorkers as $availableWorker): ?>
                                        <option value="<?= e((string) $availableWorker['id']) ?>" <?= $currentWorkerId === (int) $availableWorker['id'] ? 'selected' : '' ?>>
                                            <?= e(election_person_name($availableWorker)) ?><?= (int) $availableWorker['is_active'] === 1 ? '' : ' (inactive)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php if ($currentSlotAssignment): ?>
                                <div class="staffing-worker-contact">
                                    <?= e($currentSlotAssignment['email'] ?: 'No email') ?> - <?= e($currentSlotAssignment['phone'] ?: 'No phone') ?>
                                </div>
                            <?php else: ?>
                                <div class="staffing-worker-contact">No worker selected</div>
                            <?php endif; ?>
                            <?php if ($extraAssignments): ?>
                                <div class="staffing-extra-workers">
                                    <span class="meta">Additional workers</span>
                                    <?php foreach ($extraAssignments as $extraAssignment): ?>
                                        <?php $extraTrainingStatus = $trainingStatusByAssignmentId[(int) $extraAssignment['id']] ?? null; ?>
                                        <div class="staffing-extra-worker-row">
                                            <span>
                                                <?= e(election_person_name($extraAssignment)) ?><br>
                                                <span class="meta"><?= e($extraAssignment['email'] ?: 'No email') ?> - <?= e($extraAssignment['phone'] ?: 'No phone') ?></span>
                                            </span>
                                            <form method="post">
                                                <input type="hidden" name="action" value="remove_extra_slot">
                                                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                                                <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                                                <input type="hidden" name="assignment_id" value="<?= e((string) $extraAssignment['id']) ?>">
                                                <button type="submit" class="secondary compact-button">Remove</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($canAddExtraWorker): ?>
                                <details class="staffing-add-extra">
                                    <summary class="button secondary compact-button">Add extra worker</summary>
                                    <form method="post" class="staffing-add-extra-form">
                                        <input type="hidden" name="action" value="add_extra_slot">
                                        <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                                        <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                                        <input type="hidden" name="position_id" value="<?= e((string) $positionId) ?>">
                                        <select name="extra_worker_id">
                                            <option value="">Select extra worker</option>
                                            <?php foreach ($availableWorkers as $availableWorker): ?>
                                                <option value="<?= e((string) $availableWorker['id']) ?>">
                                                    <?= e(election_person_name($availableWorker)) ?><?= (int) $availableWorker['is_active'] === 1 ? '' : ' (inactive)' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="secondary compact-button">Add</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <div class="staffing-status-stack">
                                <?php if ($currentSlotAssignment): ?>
                                    <span class="badge badge-success">Filled</span>
                                <?php else: ?>
                                    <span class="badge badge-muted">Open</span>
                                <?php endif; ?>
                                <?php if ($currentTrainingStatus): ?>
                                    <span class="badge <?= e($currentTrainingStatus['class']) ?>"><?= e($currentTrainingStatus['label']) ?></span>
                                    <?php if ($currentTrainingStatus['detail'] !== ''): ?>
                                        <span class="meta"><?= e($currentTrainingStatus['detail']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($extraAssignments): ?>
                                    <span class="badge badge-muted"><?= e((string) count($extraAssignments)) ?> extra</span>
                                    <?php foreach ($extraAssignments as $extraAssignment): ?>
                                        <?php $extraTrainingStatus = $trainingStatusByAssignmentId[(int) $extraAssignment['id']] ?? null; ?>
                                        <?php if ($extraTrainingStatus): ?>
                                            <span class="meta"><?= e(election_person_name($extraAssignment)) ?></span>
                                            <span class="badge <?= e($extraTrainingStatus['class']) ?>"><?= e($extraTrainingStatus['label']) ?></span>
                                            <?php if ($extraTrainingStatus['detail'] !== ''): ?>
                                                <span class="meta"><?= e($extraTrainingStatus['detail']) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Action">
                            <div class="table-actions">
                                <button type="submit" form="staffing-slot-<?= e((string) $positionId) ?>" class="secondary compact-button">Save</button>
                                <?php if ($currentSlotAssignment): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="save_slot">
                                        <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                                        <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                                        <input type="hidden" name="position_id" value="<?= e((string) $positionId) ?>">
                                        <input type="hidden" name="worker_id" value="0">
                                        <button type="submit" class="secondary compact-button">Clear</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
