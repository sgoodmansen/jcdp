<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$assignmentId = (int) ($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
$isNewAssignment = $id > 0 && (($_GET['new_assignment'] ?? $_POST['new_assignment'] ?? '') === '1');
$currentWorker = current_election_worker();
$currentAssignment = current_election_assignment();
$isManager = can_manage_election_module();
$canManageWorkers = current_election_actor_can_manage_workers();

$worker = null;
$assignment = null;
if ($id > 0) {
    $statement = db()->prepare('SELECT * FROM election_workers WHERE id = :id');
    $statement->execute(['id' => $id]);
    $worker = $statement->fetch();

    if (!$worker) {
        http_response_code(404);
        page_header('Worker not found');
        echo '<main class="shell"><section class="panel"><h1>Worker not found</h1><p>The selected election worker could not be found.</p></section></main>';
        page_footer();
        exit;
    }

    if (!$isNewAssignment) {
        $assignmentSql = 'SELECT * FROM election_worker_assignments WHERE worker_id = :worker_id';
        $assignmentParams = ['worker_id' => $id];
        if ($assignmentId > 0) {
            $assignmentSql .= ' AND id = :assignment_id';
            $assignmentParams['assignment_id'] = $assignmentId;
        }
        $assignmentSql .= ' ORDER BY is_active DESC, election_period_id DESC, id DESC LIMIT 1';
        $statement = db()->prepare($assignmentSql);
        $statement->execute($assignmentParams);
        $assignment = $statement->fetch() ?: null;
        $assignmentId = (int) ($assignment['id'] ?? 0);
    }
}

$isSelfEdit = $currentWorker && $worker && (int) $currentWorker['id'] === (int) $worker['id'];

if (!$isSelfEdit && !$canManageWorkers) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to edit this worker.</p></section></main>';
    page_footer();
    exit;
}

if ($currentAssignment && $assignment && !$isManager) {
    if ((int) $assignment['precinct_id'] !== (int) $currentAssignment['precinct_id']
        || (int) $assignment['election_period_id'] !== (int) $currentAssignment['election_period_id']) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You can only manage workers in your assigned precinct.</p></section></main>';
        page_footer();
        exit;
    }
}

if ($id === 0 && !$canManageWorkers) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to add workers.</p></section></main>';
    page_footer();
    exit;
}

$sentAccessUrl = null;
$possibleMatches = [];
$workerStatus = $worker ? election_worker_status($worker) : ELECTION_WORKER_STATUS_ACTIVE;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_worker';

    if ($action === 'send_welcome_email' && $worker && !$isSelfEdit && $canManageWorkers) {
        $emailWorker = election_worker_for_email((int) $worker['id'], $assignmentId > 0 ? $assignmentId : null);

        if (!$emailWorker || trim((string) ($emailWorker['email'] ?? '')) === '') {
            flash('error', 'Add an email address before sending the welcome email.');
            redirect_to('departments/election/worker-edit.php?id=' . (int) $worker['id']);
        }

        $token = election_generate_worker_token((int) $worker['id']);
        $sentAccessUrl = election_worker_access_url((int) $worker['id'], $token);
        $emailSent = election_send_worker_welcome_email($emailWorker, $sentAccessUrl);

        audit_event('sent_welcome_email', 'election_worker', (string) $worker['id'], [
            'email_sent' => $emailSent,
        ]);

        if ($emailSent) {
            flash('success', 'Welcome email sent to ' . $emailWorker['email'] . '.');
            redirect_to('departments/election/worker-edit.php?id=' . (int) $worker['id']);
        }

        flash('error', 'The welcome email could not be sent. The access link was generated but mail delivery failed.');
    } elseif ($action === 'add_worker_note' && $worker && !$isSelfEdit && $canManageWorkers) {
        $noteText = trim($_POST['note_text'] ?? '');
        if ($noteText === '') {
            flash('error', 'Enter a follow-up note before saving.');
            redirect_to('departments/election/worker-edit.php?id=' . (int) $worker['id']);
        }

        $statement = db()->prepare(
            'INSERT INTO election_worker_notes (worker_id, created_by_user_id, note_text)
             VALUES (:worker_id, :created_by_user_id, :note_text)'
        );
        $statement->execute([
            'worker_id' => (int) $worker['id'],
            'created_by_user_id' => current_user()['id'] ?? null,
            'note_text' => $noteText,
        ]);

        audit_event('created_note', 'election_worker', (string) $worker['id'], []);
        flash('success', 'Follow-up note added.');
        redirect_to('departments/election/worker-edit.php?id=' . (int) $worker['id']);
    } else {
        $periodId = (int) ($_POST['election_period_id'] ?? ($assignment['election_period_id'] ?? 0));
        $precinctId = (int) ($_POST['precinct_id'] ?? ($assignment['precinct_id'] ?? 0));
        $positionId = (int) ($_POST['position_id'] ?? ($assignment['position_id'] ?? 0));

        if ($currentAssignment && !$isManager) {
            $periodId = (int) $currentAssignment['election_period_id'];
            $precinctId = (int) $currentAssignment['precinct_id'];
        }

        if ($isSelfEdit && $assignment) {
            $positionId = (int) $assignment['position_id'];
            $periodId = (int) $assignment['election_period_id'];
            $precinctId = (int) $assignment['precinct_id'];
        }

        $regularPositionIds = array_map(
            fn($position) => (int) $position['id'],
            array_filter(election_positions(), fn($position) => (int) $position['is_assistant_chief_judge'] !== 1)
        );
        $hasAssignmentSelection = $periodId > 0 || $precinctId > 0 || $positionId > 0;
        $shouldManageAssignment = !$isSelfEdit && ($assignmentId > 0 || $isNewAssignment || ($id === 0 && $hasAssignmentSelection));
        if ($shouldManageAssignment && ($periodId === 0 || $precinctId === 0 || $positionId === 0)) {
            flash('error', 'Choose an election, precinct, and position to add an assignment, or leave all three blank to save only the worker contact.');
            redirect_to('departments/election/worker-edit.php' . ($id > 0 ? '?id=' . $id : ''));
        }
        if ($shouldManageAssignment && !in_array($positionId, $regularPositionIds, true)) {
            flash('error', 'Assistant Chief Judge is assigned as an extra responsibility on Precinct Staffing.');
            redirect_to('departments/election/worker-edit.php' . ($id > 0 ? '?id=' . $id : ''));
        }

        $params = [
            'first_name' => preserve_name_case($_POST['first_name'] ?? ''),
            'last_name' => preserve_name_case($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'email_normalized' => election_normalized_email($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'phone_digits' => election_phone_digits($_POST['phone'] ?? ''),
            'name_key' => election_worker_name_key($_POST['first_name'] ?? '', $_POST['last_name'] ?? ''),
            'mailing_address' => title_case_address($_POST['mailing_address'] ?? ''),
            'city' => title_case_name($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'zip_code' => trim($_POST['zip_code'] ?? ''),
            'wants_email_reminders' => isset($_POST['wants_email_reminders']) ? 1 : 0,
            'wants_text_reminders' => isset($_POST['wants_text_reminders']) ? 1 : 0,
        ];
        $postedWorkerStatus = $_POST['availability_status'] ?? $workerStatus;
        if (!array_key_exists($postedWorkerStatus, election_worker_status_options())) {
            $postedWorkerStatus = ELECTION_WORKER_STATUS_ACTIVE;
        }
        if ($isSelfEdit) {
            $postedWorkerStatus = $workerStatus;
        }
        $params['availability_status'] = $postedWorkerStatus;
        $params['unavailable_reason'] = $postedWorkerStatus === ELECTION_WORKER_STATUS_UNAVAILABLE
            ? trim($_POST['unavailable_reason'] ?? '')
            : '';
        $params['contact_is_active'] = $postedWorkerStatus === ELECTION_WORKER_STATUS_ACTIVE ? 1 : 0;

        $assignmentParams = [
            'election_period_id' => $periodId,
            'precinct_id' => $precinctId,
            'position_id' => $positionId,
            'is_active' => $isSelfEdit ? (int) ($assignment['is_active'] ?? 1) : (isset($_POST['is_active']) ? 1 : 0),
            'notes' => $isSelfEdit ? (string) ($assignment['notes'] ?? '') : trim($_POST['notes'] ?? ''),
        ];

        if ($id === 0 && $action === 'use_existing_worker') {
            $existingWorkerId = (int) ($_POST['existing_worker_id'] ?? 0);
            $statement = db()->prepare('SELECT id, availability_status, is_active FROM election_workers WHERE id = :id');
            $statement->execute(['id' => $existingWorkerId]);
            $existingWorker = $statement->fetch();

            if (!$existingWorker) {
                flash('error', 'Select an existing worker before adding the assignment.');
                redirect_to('departments/election/worker-edit.php');
            }

            if (!$shouldManageAssignment) {
                flash('success', 'Existing worker opened. Review the contact record before making changes.');
                redirect_to('departments/election/worker-edit.php?id=' . $existingWorkerId);
            }

            if (election_worker_status($existingWorker) !== ELECTION_WORKER_STATUS_ACTIVE) {
                flash('error', 'That worker is marked unavailable or archived. Change the worker status before adding an assignment.');
                redirect_to('departments/election/worker-edit.php');
            }

            $assignmentParams['worker_id'] = $existingWorkerId;
            $assignmentParams['created_by_user_id'] = current_user()['id'] ?? null;
            $assignmentParams['recruited_by_assignment_id'] = $currentAssignment['id'] ?? null;
            $statement = db()->prepare(
                'INSERT IGNORE INTO election_worker_assignments (
                    worker_id, election_period_id, precinct_id, position_id, recruited_by_assignment_id,
                    created_by_user_id, is_active, notes
                 )
                 VALUES (
                    :worker_id, :election_period_id, :precinct_id, :position_id, :recruited_by_assignment_id,
                    :created_by_user_id, :is_active, :notes
                 )'
            );
            $statement->execute($assignmentParams);

            if ($statement->rowCount() === 0) {
                flash('error', 'That worker already has this assignment.');
            } else {
                audit_event('created_assignment', 'election_worker', (string) $existingWorkerId, [
                    'election_period_id' => $periodId,
                    'precinct_id' => $precinctId,
                    'position_id' => $positionId,
                ]);
                flash('success', 'Existing worker found. New assignment added.');
            }

            redirect_to('departments/election/workers.php');
        }

        if ($id > 0) {
            $params['id'] = $id;
            $statement = db()->prepare(
                'UPDATE election_workers
                 SET first_name = :first_name,
                     last_name = :last_name,
                     email = :email,
                     email_normalized = :email_normalized,
                     phone = :phone,
                     phone_digits = :phone_digits,
                     name_key = :name_key,
                     mailing_address = :mailing_address,
                     city = :city,
                     state = :state,
                     zip_code = :zip_code,
                     wants_email_reminders = :wants_email_reminders,
                     wants_text_reminders = :wants_text_reminders,
                     reminder_preferences_asked_at = COALESCE(reminder_preferences_asked_at, NOW()),
                     availability_status = :availability_status,
                     unavailable_reason = :unavailable_reason,
                     is_active = :contact_is_active,
                     access_token_hash = CASE WHEN :contact_is_active_token = 1 THEN access_token_hash ELSE NULL END,
                     access_token_created_at = CASE WHEN :contact_is_active_token_created = 1 THEN access_token_created_at ELSE NULL END
                 WHERE id = :id'
            );
            $params['contact_is_active_token'] = $params['contact_is_active'];
            $params['contact_is_active_token_created'] = $params['contact_is_active'];
            $statement->execute($params);

            if (!$isSelfEdit && $assignmentId > 0) {
                $assignmentParams['id'] = $assignmentId;
                $statement = db()->prepare(
                    'UPDATE election_worker_assignments
                     SET election_period_id = :election_period_id,
                         precinct_id = :precinct_id,
                         position_id = :position_id,
                         is_active = :is_active,
                         notes = :notes
                     WHERE id = :id'
                );
                $statement->execute($assignmentParams);
            } elseif (!$isSelfEdit && $isNewAssignment) {
                $assignmentParams['worker_id'] = $id;
                $assignmentParams['created_by_user_id'] = current_user()['id'] ?? null;
                $assignmentParams['recruited_by_assignment_id'] = $currentAssignment['id'] ?? null;
                $statement = db()->prepare(
                    'INSERT INTO election_worker_assignments (
                        worker_id, election_period_id, precinct_id, position_id, recruited_by_assignment_id,
                        created_by_user_id, is_active, notes
                     )
                     VALUES (
                        :worker_id, :election_period_id, :precinct_id, :position_id, :recruited_by_assignment_id,
                        :created_by_user_id, :is_active, :notes
                     )'
                );
                $statement->execute($assignmentParams);
            }

            audit_event('updated', 'election_worker', (string) $id, ['name' => $params['first_name'] . ' ' . $params['last_name']]);
            flash('success', 'Worker saved.');
            redirect_to($isSelfEdit ? 'departments/election/index.php' : 'departments/election/workers.php');
        }

        if ($action === 'save_worker' && empty($_POST['create_anyway'])) {
            $possibleMatches = election_find_possible_worker_matches($params);
            if ($possibleMatches) {
                $worker = $params;
                $workerStatus = $params['availability_status'];
                $assignment = $assignmentParams;
            }
        }

        if (!$possibleMatches) {
        $params['created_by_user_id'] = current_user()['id'] ?? null;
        $statement = db()->prepare(
            'INSERT INTO election_workers (
                election_period_id, precinct_id, position_id, recruited_by_worker_id, created_by_user_id,
                first_name, last_name, email, email_normalized, phone, phone_digits, name_key, mailing_address, city, state, zip_code,
                wants_email_reminders, wants_text_reminders, availability_status, unavailable_reason, is_active, notes
             )
             VALUES (
                :election_period_id, :precinct_id, :position_id, NULL, :created_by_user_id,
                :first_name, :last_name, :email, :email_normalized, :phone, :phone_digits, :name_key, :mailing_address, :city, :state, :zip_code,
                :wants_email_reminders, :wants_text_reminders, :availability_status, :unavailable_reason, :contact_is_active, NULL
             )'
        );
        $legacyParams = [
            'election_period_id' => $shouldManageAssignment ? $assignmentParams['election_period_id'] : null,
            'precinct_id' => $shouldManageAssignment ? $assignmentParams['precinct_id'] : null,
            'position_id' => $shouldManageAssignment ? $assignmentParams['position_id'] : null,
            'created_by_user_id' => $params['created_by_user_id'],
            'first_name' => $params['first_name'],
            'last_name' => $params['last_name'],
            'email' => $params['email'],
            'email_normalized' => $params['email_normalized'],
            'phone' => $params['phone'],
            'phone_digits' => $params['phone_digits'],
            'name_key' => $params['name_key'],
            'mailing_address' => $params['mailing_address'],
            'city' => $params['city'],
            'state' => $params['state'],
            'zip_code' => $params['zip_code'],
            'wants_email_reminders' => $params['wants_email_reminders'],
            'wants_text_reminders' => $params['wants_text_reminders'],
            'availability_status' => $params['availability_status'],
            'unavailable_reason' => $params['unavailable_reason'],
            'contact_is_active' => $params['contact_is_active'],
        ];
        $statement->execute($legacyParams);
        $id = (int) db()->lastInsertId();

        if ($shouldManageAssignment) {
            $assignmentParams['worker_id'] = $id;
            $assignmentParams['created_by_user_id'] = current_user()['id'] ?? null;
            $assignmentParams['recruited_by_assignment_id'] = $currentAssignment['id'] ?? null;
            $statement = db()->prepare(
                'INSERT INTO election_worker_assignments (
                    worker_id, election_period_id, precinct_id, position_id, recruited_by_assignment_id,
                    created_by_user_id, is_active, notes
                 )
                 VALUES (
                    :worker_id, :election_period_id, :precinct_id, :position_id, :recruited_by_assignment_id,
                    :created_by_user_id, 1, :notes
                 )'
            );
            unset($assignmentParams['is_active']);
            $statement->execute($assignmentParams);
        }

        audit_event('created', 'election_worker', (string) $id, ['name' => $params['first_name'] . ' ' . $params['last_name']]);

        $statement = db()->prepare('SELECT * FROM election_workers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $worker = $statement->fetch();
        flash('success', $shouldManageAssignment
            ? 'Worker added with assignment. Send the welcome email when you are ready to share the access link.'
            : 'Worker contact added to the address book.');
        redirect_to('departments/election/workers.php');
        }
    }
}

$periods = election_active_periods();
$precincts = election_precincts();
$positions = array_values(array_filter(election_positions(), fn($position) => (int) $position['is_assistant_chief_judge'] !== 1));

if ($currentAssignment && !$isManager) {
    $periods = array_values(array_filter($periods, fn($period) => (int) $period['id'] === (int) $currentAssignment['election_period_id']));
    $precincts = array_values(array_filter($precincts, fn($precinct) => (int) $precinct['id'] === (int) $currentAssignment['precinct_id']));
}

$selectedPeriodId = (int) ($assignment['election_period_id'] ?? 0);
if ($selectedPeriodId === 0 && count($periods) === 1 && ($isNewAssignment || $assignmentId > 0)) {
    $selectedPeriodId = (int) $periods[0]['id'];
}

$assignmentHistory = [];
$savedWorkerId = (int) ($worker['id'] ?? 0);
if ($savedWorkerId > 0) {
    $historySql = 'SELECT election_worker_assignments.*,
                          election_periods.name AS election_name,
                          election_periods.starts_on,
                          election_periods.ends_on,
                          election_periods.is_active AS election_is_active,
                          election_precincts.name AS precinct_name,
                          election_positions.name AS position_name,
                          CASE WHEN election_precinct_roles.assignment_id IS NULL THEN 0 ELSE 1 END AS is_assistant_chief_judge_extra,
                          COUNT(DISTINCT election_training_registrations.class_id) AS training_registration_count,
                          SUM(CASE WHEN election_training_registrations.attended = 1 THEN 1 ELSE 0 END) AS training_attended_count,
                          GROUP_CONCAT(
                              DISTINCT CONCAT(
                                  election_training_classes.class_title,
                                  " - ",
                                  DATE_FORMAT(election_training_classes.class_date, "%m-%d-%Y"),
                                  " ",
                                  CASE WHEN election_training_registrations.attended = 1 THEN "Complete" ELSE "Registered" END
                              )
                              ORDER BY election_training_classes.class_date, election_training_classes.start_time
                              SEPARATOR "\n"
                          ) AS training_summary
                   FROM election_worker_assignments
                   INNER JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
                   INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
                   INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
                   LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
                       AND election_precinct_roles.role_key = :assistant_role_key
                   LEFT JOIN election_training_registrations ON election_training_registrations.assignment_id = election_worker_assignments.id
                   LEFT JOIN election_training_classes ON election_training_classes.id = election_training_registrations.class_id
                   WHERE election_worker_assignments.worker_id = :worker_id';
    $historyParams = [
        'assistant_role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
        'worker_id' => $savedWorkerId,
    ];

    if (!$isManager && $currentAssignment && !$isSelfEdit) {
        $historySql .= ' AND election_worker_assignments.election_period_id = :history_election_period_id
                         AND election_worker_assignments.precinct_id = :history_precinct_id';
        $historyParams['history_election_period_id'] = (int) $currentAssignment['election_period_id'];
        $historyParams['history_precinct_id'] = (int) $currentAssignment['precinct_id'];
    }

    $historySql .= ' GROUP BY election_worker_assignments.id
                     ORDER BY election_periods.starts_on DESC,
                              election_precincts.name,
                              election_positions.sort_order,
                              election_worker_assignments.is_extra,
                              election_worker_assignments.id DESC';
    $statement = db()->prepare($historySql);
    $statement->execute($historyParams);
    $assignmentHistory = $statement->fetchAll();
}

$workerNotes = [];
if ($savedWorkerId > 0 && !$isSelfEdit && $canManageWorkers) {
    $statement = db()->prepare(
        'SELECT election_worker_notes.*,
                users.first_name AS author_first_name,
                users.last_name AS author_last_name
         FROM election_worker_notes
         LEFT JOIN users ON users.id = election_worker_notes.created_by_user_id
         WHERE election_worker_notes.worker_id = :worker_id
         ORDER BY election_worker_notes.created_at DESC, election_worker_notes.id DESC
         LIMIT 50'
    );
    $statement->execute(['worker_id' => $savedWorkerId]);
    $workerNotes = $statement->fetchAll();
}

$actions = [
    ['label' => 'Address Book', 'href' => url('departments/election/workers.php'), 'primary' => true],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

$pageTitle = $isNewAssignment ? 'Add Worker Assignment' : ($id > 0 ? 'Edit Election Contact' : 'Add Election Contact');
page_header($pageTitle);
?>
<main class="shell">
    <section class="panel">
        <h1><?= e($pageTitle) ?></h1>
        <p><?= $isSelfEdit ? 'Update your contact information and reminder preferences.' : 'Assign the worker to an election, precinct, and position.' ?></p>
        <?php election_navigation($isSelfEdit ? 'my-information' : 'workers'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($sentAccessUrl): ?>
            <div class="notice error">
                Access link generated but email was not sent: <a href="<?= e($sentAccessUrl) ?>"><?= e($sentAccessUrl) ?></a>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($possibleMatches): ?>
        <div class="modal-backdrop duplicate-worker-modal" role="presentation">
            <section class="panel modal-panel" role="dialog" aria-modal="true" aria-labelledby="duplicate-worker-title">
                <div class="section-heading-row">
                    <div>
                        <h1 id="duplicate-worker-title">Possible Duplicate Worker Found</h1>
                        <p>Review the existing contacts before creating a new person.</p>
                    </div>
                    <button type="button" class="secondary compact-button" data-close-modal>Cancel</button>
                </div>

                <div class="notice warning">
                    You entered <?= e(trim(($worker['first_name'] ?? '') . ' ' . ($worker['last_name'] ?? ''))) ?>.
                    If this is the same person, use the existing worker record.
                </div>

                <table class="table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Assignments</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($possibleMatches as $match): ?>
                            <tr>
                                <td data-label="Name"><?= e(election_person_name($match)) ?></td>
                                <td data-label="Contact">
                                    <?= e($match['email'] ?: 'No email') ?><br>
                                    <span class="meta"><?= e($match['phone'] ?: 'No phone') ?></span>
                                </td>
                                <td data-label="Assignments">
                                    <?php foreach (array_filter(explode("\n", (string) ($match['assignment_summary'] ?? ''))) as $summary): ?>
                                        <?= e($summary) ?><br>
                                    <?php endforeach; ?>
                                    <?php if (empty($match['assignment_summary'])): ?>
                                        <span class="meta">No assignments yet</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <form method="post">
                                        <input type="hidden" name="action" value="use_existing_worker">
                                        <input type="hidden" name="existing_worker_id" value="<?= e((string) $match['id']) ?>">
                                        <input type="hidden" name="election_period_id" value="<?= e((string) ($assignment['election_period_id'] ?? 0)) ?>">
                                        <input type="hidden" name="precinct_id" value="<?= e((string) ($assignment['precinct_id'] ?? 0)) ?>">
                                        <input type="hidden" name="position_id" value="<?= e((string) ($assignment['position_id'] ?? 0)) ?>">
                                        <?php if ((int) ($assignment['is_active'] ?? 1) === 1): ?>
                                            <input type="hidden" name="is_active" value="1">
                                        <?php endif; ?>
                                        <input type="hidden" name="notes" value="<?= e($assignment['notes'] ?? '') ?>">
                                        <button type="submit" class="compact-button"><?= ((int) ($assignment['election_period_id'] ?? 0) > 0 && (int) ($assignment['precinct_id'] ?? 0) > 0 && (int) ($assignment['position_id'] ?? 0) > 0) ? 'Use existing' : 'Open existing' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" class="actions" style="margin-top: 18px;">
                    <input type="hidden" name="create_anyway" value="1">
                    <input type="hidden" name="election_period_id" value="<?= e((string) ($assignment['election_period_id'] ?? 0)) ?>">
                    <input type="hidden" name="precinct_id" value="<?= e((string) ($assignment['precinct_id'] ?? 0)) ?>">
                    <input type="hidden" name="position_id" value="<?= e((string) ($assignment['position_id'] ?? 0)) ?>">
                    <input type="hidden" name="first_name" value="<?= e($worker['first_name'] ?? '') ?>">
                    <input type="hidden" name="last_name" value="<?= e($worker['last_name'] ?? '') ?>">
                    <input type="hidden" name="email" value="<?= e($worker['email'] ?? '') ?>">
                    <input type="hidden" name="phone" value="<?= e($worker['phone'] ?? '') ?>">
                    <input type="hidden" name="mailing_address" value="<?= e($worker['mailing_address'] ?? '') ?>">
                    <input type="hidden" name="city" value="<?= e($worker['city'] ?? '') ?>">
                    <input type="hidden" name="state" value="<?= e($worker['state'] ?? '') ?>">
                    <input type="hidden" name="zip_code" value="<?= e($worker['zip_code'] ?? '') ?>">
                    <?php if ((int) ($worker['wants_email_reminders'] ?? 0) === 1): ?>
                        <input type="hidden" name="wants_email_reminders" value="1">
                    <?php endif; ?>
                    <?php if ((int) ($worker['wants_text_reminders'] ?? 0) === 1): ?>
                        <input type="hidden" name="wants_text_reminders" value="1">
                    <?php endif; ?>
                    <input type="hidden" name="availability_status" value="<?= e($workerStatus) ?>">
                    <input type="hidden" name="unavailable_reason" value="<?= e($worker['unavailable_reason'] ?? '') ?>">
                    <?php if ((int) ($assignment['is_active'] ?? 1) === 1): ?>
                        <input type="hidden" name="is_active" value="1">
                    <?php endif; ?>
                    <input type="hidden" name="notes" value="<?= e($assignment['notes'] ?? '') ?>">
                    <button type="submit" class="secondary">Create new person anyway</button>
                    <button type="button" class="secondary" data-close-modal>Cancel</button>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <section class="panel" style="margin-top: 18px;">
        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
            <input type="hidden" name="new_assignment" value="<?= $isNewAssignment ? '1' : '0' ?>">
            <?php if (!$isSelfEdit): ?>
                <?php $assignmentFieldsRequired = $isNewAssignment || $assignmentId > 0; ?>
                <?php if (!$assignmentFieldsRequired): ?>
                    <p class="span-2">Leave the assignment fields blank to save this person as an address book contact only.</p>
                <?php endif; ?>
                <label class="span-2">
                    Election<?= $assignmentFieldsRequired ? '' : ' assignment' ?>
                    <select name="election_period_id" <?= $assignmentFieldsRequired ? 'required' : '' ?>>
                        <option value=""><?= $assignmentFieldsRequired ? 'Select election' : 'No assignment yet' ?></option>
                        <?php foreach ($periods as $period): ?>
                            <option value="<?= e((string) $period['id']) ?>" <?= $selectedPeriodId === (int) $period['id'] ? 'selected' : '' ?>><?= e($period['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Precinct
                    <select name="precinct_id" <?= $assignmentFieldsRequired ? 'required' : '' ?>>
                        <option value=""><?= $assignmentFieldsRequired ? 'Select precinct' : 'No assignment yet' ?></option>
                        <?php foreach ($precincts as $precinct): ?>
                            <option value="<?= e((string) $precinct['id']) ?>" <?= (int) ($assignment['precinct_id'] ?? 0) === (int) $precinct['id'] ? 'selected' : '' ?>><?= e($precinct['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Position
                    <select name="position_id" <?= $assignmentFieldsRequired ? 'required' : '' ?>>
                        <option value=""><?= $assignmentFieldsRequired ? 'Select position' : 'No assignment yet' ?></option>
                        <?php foreach ($positions as $position): ?>
                            <option value="<?= e((string) $position['id']) ?>" <?= (int) ($assignment['position_id'] ?? 0) === (int) $position['id'] ? 'selected' : '' ?>><?= e($position['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label>
                First name
                <input name="first_name" value="<?= e($worker['first_name'] ?? '') ?>" required>
            </label>
            <label>
                Last name
                <input name="last_name" value="<?= e($worker['last_name'] ?? '') ?>" required>
            </label>
            <label>
                Email
                <input type="email" name="email" value="<?= e($worker['email'] ?? '') ?>">
            </label>
            <label>
                Phone
                <input name="phone" class="phone-input" inputmode="tel" placeholder="(208) 555-1234" value="<?= e($worker['phone'] ?? '') ?>">
            </label>
            <label>
                Mailing address
                <input name="mailing_address" value="<?= e($worker['mailing_address'] ?? '') ?>">
            </label>
            <label>
                City
                <input name="city" value="<?= e($worker['city'] ?? '') ?>">
            </label>
            <label>
                State
                <select name="state">
                    <?php state_options($worker['state'] ?? 'ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code" value="<?= e($worker['zip_code'] ?? '') ?>">
            </label>
            <label class="check-label">
                <input type="checkbox" name="wants_email_reminders" <?= (int) ($worker['wants_email_reminders'] ?? 0) === 1 ? 'checked' : '' ?>>
                Email reminders
            </label>
            <label class="check-label">
                <input type="checkbox" name="wants_text_reminders" <?= (int) ($worker['wants_text_reminders'] ?? 0) === 1 ? 'checked' : '' ?>>
                Text reminders
            </label>
            <?php if (!$isSelfEdit): ?>
                <div class="worker-status-panel span-2">
                    <label>
                        Worker status
                        <select name="availability_status" id="worker-availability-status">
                            <?php foreach (election_worker_status_options() as $statusValue => $statusLabel): ?>
                                <option value="<?= e($statusValue) ?>" <?= $workerStatus === $statusValue ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="worker-unavailable-panel" id="worker-unavailable-panel">
                        Unavailable reason
                        <input name="unavailable_reason" id="worker-unavailable-reason" value="<?= e($worker['unavailable_reason'] ?? '') ?>" placeholder="Moved, deceased, health, do not contact">
                        <span class="quick-reason-buttons" aria-label="Unavailable quick reasons">
                            <button type="button" class="secondary compact-button" data-unavailable-reason="Moved">Moved</button>
                            <button type="button" class="secondary compact-button" data-unavailable-reason="Deceased">Deceased</button>
                            <button type="button" class="secondary compact-button" data-unavailable-reason="Health">Health</button>
                            <button type="button" class="secondary compact-button" data-unavailable-reason="Do not contact">Do not contact</button>
                            <button type="button" class="secondary compact-button" data-unavailable-reason="">Other</button>
                        </span>
                    </label>
                </div>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) ($assignment['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    Active assignment
                </label>
                <label class="span-2">
                    Notes
                    <textarea name="notes"><?= e($assignment['notes'] ?? '') ?></textarea>
                </label>
            <?php endif; ?>
            <div class="actions span-2">
                <button type="submit">Save worker</button>
                <?php if ($isSelfEdit): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/index.php')) ?>">Cancel</a>
                <?php else: ?>
                    <a class="button secondary" href="<?= e(url('departments/election/workers.php')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if ($savedWorkerId > 0 && !$isSelfEdit && $canManageWorkers): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>Follow-Up Log</h1>
                    <p class="muted">Track calls, messages, availability details, and other supervisor notes.</p>
                </div>
                <span class="badge badge-muted"><?= e((string) count($workerNotes)) ?> note<?= count($workerNotes) === 1 ? '' : 's' ?></span>
            </div>

            <form class="form wide-form worker-note-form" method="post">
                <input type="hidden" name="id" value="<?= e((string) $savedWorkerId) ?>">
                <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
                <input type="hidden" name="action" value="add_worker_note">
                <label>
                    New note
                    <textarea name="note_text" placeholder="Called and left voicemail, prefers morning shifts, needs follow-up, etc." required></textarea>
                </label>
                <div class="actions">
                    <button type="submit" class="secondary">Add note</button>
                </div>
            </form>

            <div class="worker-note-list">
                <?php foreach ($workerNotes as $note): ?>
                    <?php
                    $authorName = trim((string) ($note['author_first_name'] ?? '') . ' ' . (string) ($note['author_last_name'] ?? ''));
                    $createdAt = (string) ($note['created_at'] ?? '');
                    ?>
                    <article class="worker-note-entry">
                        <p><?= nl2br(e($note['note_text'])) ?></p>
                        <span class="meta">
                            <?= e(format_display_date($createdAt)) ?> <?= e(format_display_time($createdAt)) ?>
                            <?= $authorName !== '' ? ' by ' . e($authorName) : '' ?>
                        </span>
                    </article>
                <?php endforeach; ?>
                <?php if (!$workerNotes): ?>
                    <p>No follow-up notes have been added yet.</p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($savedWorkerId > 0): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <h1>Assignment History</h1>
                <?php if (!$isSelfEdit && $canManageWorkers): ?>
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/worker-edit.php?id=' . $savedWorkerId . '&new_assignment=1')) ?>">Add assignment</a>
                <?php endif; ?>
            </div>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Election</th>
                        <th>Precinct</th>
                        <th>Position</th>
                        <th>Assignment</th>
                        <th>Training</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignmentHistory as $history): ?>
                        <tr>
                            <td data-label="Election">
                                <?= e($history['election_name']) ?><br>
                                <span class="meta"><?= e(format_display_date($history['starts_on'])) ?> - <?= e(format_display_date($history['ends_on'])) ?></span>
                            </td>
                            <td data-label="Precinct"><?= e($history['precinct_name']) ?></td>
                            <td data-label="Position">
                                <?= e($history['position_name']) ?>
                                <?php if ((int) ($history['is_assistant_chief_judge_extra'] ?? 0) === 1): ?>
                                    <br><span class="badge badge-muted">Assistant Chief</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Assignment">
                                <span class="badge <?= (int) $history['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                    <?= (int) $history['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                </span>
                                <?php if ((int) ($history['is_extra'] ?? 0) === 1): ?>
                                    <br><span class="badge badge-muted">Extra worker</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Training">
                                <?php if ((int) $history['training_registration_count'] > 0): ?>
                                    <span class="badge <?= (int) $history['training_attended_count'] > 0 ? 'badge-success' : 'badge-muted' ?>">
                                        <?= e((string) (int) $history['training_attended_count']) ?> complete / <?= e((string) (int) $history['training_registration_count']) ?> registered
                                    </span>
                                    <?php foreach (array_filter(explode("\n", (string) ($history['training_summary'] ?? ''))) as $trainingSummary): ?>
                                        <br><span class="meta"><?= e($trainingSummary) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="meta">No training registration</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <?php if (!$isSelfEdit && $canManageWorkers): ?>
                                    <a class="button secondary compact-button" href="<?= e(url('departments/election/worker-edit.php?id=' . $savedWorkerId . '&assignment_id=' . (int) $history['id'])) ?>">Edit assignment</a>
                                <?php else: ?>
                                    <span class="meta">View only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$assignmentHistory): ?>
                        <tr><td colspan="6">No assignment history has been recorded for this worker yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($savedWorkerId > 0 && !$isSelfEdit && $canManageWorkers): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Welcome Email</h1>
            <p>Send the worker a welcome letter with instructions and a fresh access link.</p>
            <form method="post">
                <input type="hidden" name="id" value="<?= e((string) $savedWorkerId) ?>">
                <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
                <input type="hidden" name="action" value="send_welcome_email">
                <button type="submit" class="secondary">Send welcome email</button>
            </form>
        </section>
    <?php endif; ?>
</main>
<script src="<?= e(url('assets/forms.js?v=20260730c')) ?>"></script>
<script>
    const duplicateWorkerModal = document.querySelector('.duplicate-worker-modal');
    const closeDuplicateWorkerModal = () => {
        if (duplicateWorkerModal) {
            duplicateWorkerModal.hidden = true;
        }
    };

    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', closeDuplicateWorkerModal);
    });

    if (duplicateWorkerModal) {
        duplicateWorkerModal.addEventListener('click', (event) => {
            if (event.target === duplicateWorkerModal) {
                closeDuplicateWorkerModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeDuplicateWorkerModal();
            }
        });
    }

    const workerUnavailableStatus = '<?= e(ELECTION_WORKER_STATUS_UNAVAILABLE) ?>';
    const workerStatus = document.getElementById('worker-availability-status');
    const workerUnavailablePanel = document.getElementById('worker-unavailable-panel');
    const updateUnavailableReasonVisibility = () => {
        if (!workerStatus || !workerUnavailablePanel) {
            return;
        }

        workerUnavailablePanel.hidden = workerStatus.value !== workerUnavailableStatus;
    };

    if (workerStatus) {
        workerStatus.addEventListener('change', updateUnavailableReasonVisibility);
        updateUnavailableReasonVisibility();
    }

    document.querySelectorAll('[data-unavailable-reason]').forEach((button) => {
        button.addEventListener('click', () => {
            const reason = document.getElementById('worker-unavailable-reason');
            if (!workerStatus || !reason) {
                return;
            }

            workerStatus.value = workerUnavailableStatus;
            reason.value = button.dataset.unavailableReason || '';
            updateUnavailableReasonVisibility();
            reason.focus();
        });
    });
</script>
<?php page_footer(); ?>
