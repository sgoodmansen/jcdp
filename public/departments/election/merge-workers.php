<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();

function election_merge_worker(int $keepWorkerId, int $mergeWorkerId): array
{
    if ($keepWorkerId <= 0 || $mergeWorkerId <= 0 || $keepWorkerId === $mergeWorkerId) {
        throw new RuntimeException('Choose two different worker contacts.');
    }

    $workerStatement = db()->prepare('SELECT * FROM election_workers WHERE id IN (:keep_worker_id, :merge_worker_id)');
    $workerStatement->execute([
        'keep_worker_id' => $keepWorkerId,
        'merge_worker_id' => $mergeWorkerId,
    ]);
    $workersById = [];
    foreach ($workerStatement->fetchAll() as $worker) {
        $workersById[(int) $worker['id']] = $worker;
    }

    if (empty($workersById[$keepWorkerId]) || empty($workersById[$mergeWorkerId])) {
        throw new RuntimeException('One of the selected worker contacts could not be found.');
    }

    $assignmentIdMap = [];
    $movedAssignments = 0;
    $combinedAssignments = 0;
    $movedRegistrations = 0;
    $combinedRegistrations = 0;

    db()->beginTransaction();

    try {
        $copyStatement = db()->prepare(
            'UPDATE election_workers AS keep_worker
             INNER JOIN election_workers AS merge_worker ON merge_worker.id = :merge_id_copy
             SET keep_worker.email = CASE WHEN COALESCE(keep_worker.email, "") = "" THEN merge_worker.email ELSE keep_worker.email END,
                 keep_worker.email_normalized = CASE WHEN COALESCE(keep_worker.email_normalized, "") = "" THEN merge_worker.email_normalized ELSE keep_worker.email_normalized END,
                 keep_worker.phone = CASE WHEN COALESCE(keep_worker.phone, "") = "" THEN merge_worker.phone ELSE keep_worker.phone END,
                 keep_worker.phone_digits = CASE WHEN COALESCE(keep_worker.phone_digits, "") = "" THEN merge_worker.phone_digits ELSE keep_worker.phone_digits END,
                 keep_worker.mailing_address = CASE WHEN COALESCE(keep_worker.mailing_address, "") = "" THEN merge_worker.mailing_address ELSE keep_worker.mailing_address END,
                 keep_worker.city = CASE WHEN COALESCE(keep_worker.city, "") = "" THEN merge_worker.city ELSE keep_worker.city END,
                 keep_worker.state = CASE WHEN COALESCE(keep_worker.state, "") = "" THEN merge_worker.state ELSE keep_worker.state END,
                 keep_worker.zip_code = CASE WHEN COALESCE(keep_worker.zip_code, "") = "" THEN merge_worker.zip_code ELSE keep_worker.zip_code END,
                 keep_worker.wants_email_reminders = GREATEST(keep_worker.wants_email_reminders, merge_worker.wants_email_reminders),
                 keep_worker.wants_text_reminders = GREATEST(keep_worker.wants_text_reminders, merge_worker.wants_text_reminders),
                 keep_worker.reminder_preferences_asked_at = COALESCE(keep_worker.reminder_preferences_asked_at, merge_worker.reminder_preferences_asked_at),
                 keep_worker.availability_status = :active_status,
                 keep_worker.is_active = 1
             WHERE keep_worker.id = :keep_id_copy'
        );
        $copyStatement->execute([
            'merge_id_copy' => $mergeWorkerId,
            'keep_id_copy' => $keepWorkerId,
            'active_status' => ELECTION_WORKER_STATUS_ACTIVE,
        ]);

        $assignmentStatement = db()->prepare(
            'SELECT *
             FROM election_worker_assignments
             WHERE worker_id = :worker_id
             ORDER BY id'
        );
        $assignmentStatement->execute(['worker_id' => $mergeWorkerId]);
        $mergeAssignments = $assignmentStatement->fetchAll();

        $findAssignmentStatement = db()->prepare(
            'SELECT id
             FROM election_worker_assignments
             WHERE worker_id = :worker_id
               AND election_period_id = :election_period_id
               AND precinct_id = :precinct_id
               AND position_id = :position_id
             LIMIT 1'
        );
        $moveAssignmentStatement = db()->prepare(
            'UPDATE election_worker_assignments
             SET worker_id = :keep_worker_id
             WHERE id = :assignment_id'
        );
        $combineAssignmentStatement = db()->prepare(
            'UPDATE election_worker_assignments
             SET is_active = 0,
                 notes = TRIM(CONCAT(COALESCE(notes, ""), "\nMerged into assignment #", :target_assignment_id))
             WHERE id = :assignment_id'
        );
        $roleStatement = db()->prepare(
            'UPDATE election_precinct_roles
             SET assignment_id = :target_assignment_id,
                 updated_by_user_id = :updated_by_user_id
             WHERE assignment_id = :source_assignment_id'
        );

        foreach ($mergeAssignments as $assignment) {
            $assignmentId = (int) $assignment['id'];
            $findAssignmentStatement->execute([
                'worker_id' => $keepWorkerId,
                'election_period_id' => (int) $assignment['election_period_id'],
                'precinct_id' => (int) $assignment['precinct_id'],
                'position_id' => (int) $assignment['position_id'],
            ]);
            $existingAssignmentId = (int) ($findAssignmentStatement->fetchColumn() ?: 0);

            if ($existingAssignmentId > 0) {
                $assignmentIdMap[$assignmentId] = $existingAssignmentId;
                $roleStatement->execute([
                    'target_assignment_id' => $existingAssignmentId,
                    'updated_by_user_id' => current_user()['id'] ?? null,
                    'source_assignment_id' => $assignmentId,
                ]);
                $combineAssignmentStatement->execute([
                    'target_assignment_id' => $existingAssignmentId,
                    'assignment_id' => $assignmentId,
                ]);
                $combinedAssignments++;
                continue;
            }

            $assignmentIdMap[$assignmentId] = $assignmentId;
            $moveAssignmentStatement->execute([
                'keep_worker_id' => $keepWorkerId,
                'assignment_id' => $assignmentId,
            ]);
            $movedAssignments++;
        }

        foreach ($assignmentIdMap as $sourceAssignmentId => $targetAssignmentId) {
            if ($sourceAssignmentId === $targetAssignmentId) {
                continue;
            }

            $recruitedStatement = db()->prepare(
                'UPDATE election_worker_assignments
                 SET recruited_by_assignment_id = :target_assignment_id
                 WHERE recruited_by_assignment_id = :source_assignment_id'
            );
            $recruitedStatement->execute([
                'target_assignment_id' => $targetAssignmentId,
                'source_assignment_id' => $sourceAssignmentId,
            ]);

            $registeredByStatement = db()->prepare(
                'UPDATE election_training_registrations
                 SET registered_by_assignment_id = :target_assignment_id
                 WHERE registered_by_assignment_id = :source_assignment_id'
            );
            $registeredByStatement->execute([
                'target_assignment_id' => $targetAssignmentId,
                'source_assignment_id' => $sourceAssignmentId,
            ]);
        }

        $registrationStatement = db()->prepare(
            'SELECT *
             FROM election_training_registrations
             WHERE worker_id = :worker_id'
        );
        $registrationStatement->execute(['worker_id' => $mergeWorkerId]);
        $registrations = $registrationStatement->fetchAll();

        $findRegistrationStatement = db()->prepare(
            'SELECT *
             FROM election_training_registrations
             WHERE class_id = :class_id
               AND worker_id = :worker_id
             LIMIT 1'
        );
        $combineRegistrationStatement = db()->prepare(
            'UPDATE election_training_registrations
             SET assignment_id = COALESCE(assignment_id, :assignment_id),
                 registered_by_assignment_id = COALESCE(registered_by_assignment_id, :registered_by_assignment_id),
                 attended = GREATEST(attended, :attended),
                 attended_at = COALESCE(attended_at, :attended_at)
             WHERE class_id = :class_id
               AND worker_id = :worker_id'
        );
        $deleteRegistrationStatement = db()->prepare(
            'DELETE FROM election_training_registrations
             WHERE class_id = :class_id
               AND worker_id = :worker_id'
        );
        $moveRegistrationStatement = db()->prepare(
            'UPDATE election_training_registrations
             SET worker_id = :keep_worker_id,
                 assignment_id = :assignment_id,
                 registered_by_assignment_id = :registered_by_assignment_id
             WHERE class_id = :class_id
               AND worker_id = :merge_worker_id'
        );

        foreach ($registrations as $registration) {
            $sourceAssignmentId = (int) ($registration['assignment_id'] ?? 0);
            $targetAssignmentId = $sourceAssignmentId > 0 ? ($assignmentIdMap[$sourceAssignmentId] ?? $sourceAssignmentId) : null;
            $registeredByAssignmentId = (int) ($registration['registered_by_assignment_id'] ?? 0);
            $targetRegisteredByAssignmentId = $registeredByAssignmentId > 0 ? ($assignmentIdMap[$registeredByAssignmentId] ?? $registeredByAssignmentId) : null;

            $findRegistrationStatement->execute([
                'class_id' => (int) $registration['class_id'],
                'worker_id' => $keepWorkerId,
            ]);
            $existingRegistration = $findRegistrationStatement->fetch();

            if ($existingRegistration) {
                $combineRegistrationStatement->execute([
                    'assignment_id' => $targetAssignmentId,
                    'registered_by_assignment_id' => $targetRegisteredByAssignmentId,
                    'attended' => (int) $registration['attended'],
                    'attended_at' => $registration['attended_at'],
                    'class_id' => (int) $registration['class_id'],
                    'worker_id' => $keepWorkerId,
                ]);
                $deleteRegistrationStatement->execute([
                    'class_id' => (int) $registration['class_id'],
                    'worker_id' => $mergeWorkerId,
                ]);
                $combinedRegistrations++;
                continue;
            }

            $moveRegistrationStatement->execute([
                'keep_worker_id' => $keepWorkerId,
                'assignment_id' => $targetAssignmentId,
                'registered_by_assignment_id' => $targetRegisteredByAssignmentId,
                'class_id' => (int) $registration['class_id'],
                'merge_worker_id' => $mergeWorkerId,
            ]);
            $movedRegistrations++;
        }

        $keepName = election_person_name($workersById[$keepWorkerId]) ?: ('worker #' . $keepWorkerId);
        $mergedAt = date('Y-m-d H:i:s');
        $deactivateStatement = db()->prepare(
            'UPDATE election_workers
             SET is_active = 0,
                 availability_status = :inactive_status,
                 unavailable_reason = "",
                 access_token_hash = NULL,
                 access_token_created_at = NULL,
                 notes = TRIM(CONCAT(COALESCE(notes, ""), "\nMerged into ", :keep_name, " (#", :keep_worker_id, ") on ", :merged_at))
             WHERE id = :merge_worker_id'
        );
        $deactivateStatement->execute([
            'keep_name' => $keepName,
            'keep_worker_id' => $keepWorkerId,
            'merged_at' => $mergedAt,
            'inactive_status' => ELECTION_WORKER_STATUS_INACTIVE,
            'merge_worker_id' => $mergeWorkerId,
        ]);

        audit_event('merged_worker_contacts', 'election_worker', (string) $keepWorkerId, [
            'merged_worker_id' => $mergeWorkerId,
            'moved_assignments' => $movedAssignments,
            'combined_assignments' => $combinedAssignments,
            'moved_registrations' => $movedRegistrations,
            'combined_registrations' => $combinedRegistrations,
        ]);

        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return [
        'moved_assignments' => $movedAssignments,
        'combined_assignments' => $combinedAssignments,
        'moved_registrations' => $movedRegistrations,
        'combined_registrations' => $combinedRegistrations,
    ];
}

function election_merge_worker_lookup(int $workerId): ?array
{
    if ($workerId <= 0) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT election_workers.*,
                COUNT(DISTINCT election_worker_assignments.id) AS assignment_count,
                COUNT(DISTINCT election_training_registrations.class_id) AS training_count,
                MAX(election_periods.starts_on) AS latest_election_starts_on
         FROM election_workers
         LEFT JOIN election_worker_assignments ON election_worker_assignments.worker_id = election_workers.id
         LEFT JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
         LEFT JOIN election_training_registrations ON election_training_registrations.worker_id = election_workers.id
         WHERE election_workers.id = :worker_id
         GROUP BY election_workers.id'
    );
    $statement->execute(['worker_id' => $workerId]);
    $worker = $statement->fetch();

    return $worker ?: null;
}

function election_merge_worker_search(string $query): array
{
    if ($query === '') {
        return [];
    }

    $statement = db()->prepare(
        'SELECT election_workers.*,
                COUNT(DISTINCT election_worker_assignments.id) AS assignment_count,
                COUNT(DISTINCT election_training_registrations.class_id) AS training_count,
                MAX(election_periods.starts_on) AS latest_election_starts_on
         FROM election_workers
         LEFT JOIN election_worker_assignments ON election_worker_assignments.worker_id = election_workers.id
         LEFT JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
         LEFT JOIN election_training_registrations ON election_training_registrations.worker_id = election_workers.id
         WHERE election_workers.first_name LIKE :query
            OR election_workers.last_name LIKE :query
            OR CONCAT(election_workers.first_name, " ", election_workers.last_name) LIKE :query
            OR election_workers.email LIKE :query
            OR election_workers.phone LIKE :query
         GROUP BY election_workers.id
         ORDER BY election_workers.is_active DESC,
                  election_workers.last_name,
                  election_workers.first_name
         LIMIT 50'
    );
    $statement->execute(['query' => '%' . $query . '%']);

    return $statement->fetchAll();
}

function election_merge_worker_assignments(int $workerId): array
{
    $statement = db()->prepare(
        'SELECT election_worker_assignments.*,
                election_periods.name AS election_name,
                election_precincts.name AS precinct_name,
                election_positions.name AS position_name,
                CASE WHEN election_precinct_roles.assignment_id IS NULL THEN 0 ELSE 1 END AS is_assistant_chief_judge_extra
         FROM election_worker_assignments
         INNER JOIN election_periods ON election_periods.id = election_worker_assignments.election_period_id
         INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         LEFT JOIN election_precinct_roles ON election_precinct_roles.assignment_id = election_worker_assignments.id
            AND election_precinct_roles.role_key = :role_key
         WHERE election_worker_assignments.worker_id = :worker_id
         ORDER BY election_periods.starts_on DESC,
                  election_precincts.name,
                  election_positions.sort_order,
                  election_worker_assignments.id'
    );
    $statement->execute([
        'role_key' => ELECTION_ROLE_ASSISTANT_CHIEF_JUDGE,
        'worker_id' => $workerId,
    ]);

    return $statement->fetchAll();
}

$query = trim($_GET['q'] ?? '');
$keepWorkerId = (int) ($_GET['keep_worker_id'] ?? $_POST['keep_worker_id'] ?? 0);
$mergeWorkerId = (int) ($_GET['merge_worker_id'] ?? $_POST['merge_worker_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $summary = election_merge_worker($keepWorkerId, $mergeWorkerId);
        flash('success', 'Contacts merged. Assignments moved: ' . $summary['moved_assignments'] . '. Matching assignments combined: ' . $summary['combined_assignments'] . '. Training records moved: ' . $summary['moved_registrations'] . '. Matching training records combined: ' . $summary['combined_registrations'] . '.');
        redirect_to('departments/election/worker-edit.php?id=' . $keepWorkerId);
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('departments/election/merge-workers.php?' . http_build_query([
            'q' => $query,
            'keep_worker_id' => $keepWorkerId,
            'merge_worker_id' => $mergeWorkerId,
        ]));
    }
}

$searchResults = election_merge_worker_search($query);
$keepWorker = election_merge_worker_lookup($keepWorkerId);
$mergeWorker = election_merge_worker_lookup($mergeWorkerId);
$keepAssignments = $keepWorker ? election_merge_worker_assignments((int) $keepWorker['id']) : [];
$mergeAssignments = $mergeWorker ? election_merge_worker_assignments((int) $mergeWorker['id']) : [];

$actions = [
    ['label' => 'Address Book', 'href' => url('departments/election/workers.php'), 'primary' => true],
    ['label' => 'Add contact', 'href' => url('departments/election/worker-edit.php')],
    ['label' => 'Import Contacts', 'href' => url('departments/election/import-workers.php')],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

page_header('Merge Worker Contacts');
?>
<main class="shell">
    <section class="panel">
        <h1>Merge Worker Contacts</h1>
        <p>Use this when the same election worker appears more than once in the address book.</p>
        <?php election_navigation('merge-workers'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Find Contacts</h1>
        <form class="form compact-form" method="get">
            <label>
                Search by name, email, or phone
                <input name="q" value="<?= e($query) ?>" placeholder="Example: Penny Nelson">
            </label>
            <?php if ($keepWorkerId > 0): ?>
                <input type="hidden" name="keep_worker_id" value="<?= e((string) $keepWorkerId) ?>">
            <?php endif; ?>
            <?php if ($mergeWorkerId > 0): ?>
                <input type="hidden" name="merge_worker_id" value="<?= e((string) $mergeWorkerId) ?>">
            <?php endif; ?>
            <div class="actions">
                <button type="submit">Search</button>
                <a class="button secondary" href="<?= e(url('departments/election/merge-workers.php')) ?>">Start over</a>
            </div>
        </form>
    </section>

    <?php if ($query !== ''): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1>Search Results</h1>
                    <p class="muted">Choose the contact to keep and the duplicate contact to merge into it.</p>
                </div>
                <span class="badge badge-muted"><?= e((string) count($searchResults)) ?> found</span>
            </div>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>History</th>
                        <th>Status</th>
                        <th>Choose</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchResults as $worker): ?>
                        <?php
                        $workerId = (int) $worker['id'];
                        $baseQuery = [
                            'q' => $query,
                            'keep_worker_id' => $keepWorkerId > 0 ? $keepWorkerId : '',
                            'merge_worker_id' => $mergeWorkerId > 0 ? $mergeWorkerId : '',
                        ];
                        $keepUrl = url('departments/election/merge-workers.php?' . http_build_query(array_merge($baseQuery, ['keep_worker_id' => $workerId])));
                        $mergeUrl = url('departments/election/merge-workers.php?' . http_build_query(array_merge($baseQuery, ['merge_worker_id' => $workerId])));
                        ?>
                        <tr>
                            <td data-label="Name">
                                <?= e(election_person_name($worker)) ?><br>
                                <span class="meta">Contact #<?= e((string) $workerId) ?></span>
                            </td>
                            <td data-label="Contact">
                                <?= e($worker['email'] ?: 'No email') ?><br>
                                <span class="meta"><?= e($worker['phone'] ?: 'No phone') ?></span>
                            </td>
                            <td data-label="History">
                                <?= e((string) (int) $worker['assignment_count']) ?> assignments<br>
                                <span class="meta"><?= e((string) (int) $worker['training_count']) ?> training records</span>
                            </td>
                            <td data-label="Status">
                                <span class="badge <?= e(election_worker_status_badge_class($worker)) ?>">
                                    <?= e(election_worker_status_label($worker)) ?>
                                </span>
                            </td>
                            <td data-label="Choose">
                                <div class="table-actions">
                                    <a class="button secondary compact-button <?= $keepWorkerId === $workerId ? 'active' : '' ?>" href="<?= e($keepUrl) ?>">Keep</a>
                                    <a class="button secondary compact-button <?= $mergeWorkerId === $workerId ? 'active' : '' ?>" href="<?= e($mergeUrl) ?>">Merge</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$searchResults): ?>
                        <tr><td colspan="5">No contacts matched that search.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($keepWorker || $mergeWorker): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Review Merge</h1>
            <div class="grid two-column-grid">
                <article class="card">
                    <h2>Keep this contact</h2>
                    <?php if ($keepWorker): ?>
                        <h3><?= e(election_person_name($keepWorker)) ?></h3>
                        <p>
                            <?= e($keepWorker['email'] ?: 'No email') ?><br>
                            <?= e($keepWorker['phone'] ?: 'No phone') ?>
                        </p>
                        <p class="meta">
                            <?= e(trim(($keepWorker['mailing_address'] ?? '') . ' ' . ($keepWorker['city'] ?? '') . ' ' . ($keepWorker['state'] ?? '') . ' ' . ($keepWorker['zip_code'] ?? '')) ?: 'No address') ?>
                        </p>
                        <p>
                            <span class="badge badge-muted"><?= e((string) (int) $keepWorker['assignment_count']) ?> assignments</span>
                            <span class="badge badge-muted"><?= e((string) (int) $keepWorker['training_count']) ?> training records</span>
                        </p>
                    <?php else: ?>
                        <p>No contact selected yet.</p>
                    <?php endif; ?>
                </article>
                <article class="card">
                    <h2>Merge this duplicate</h2>
                    <?php if ($mergeWorker): ?>
                        <h3><?= e(election_person_name($mergeWorker)) ?></h3>
                        <p>
                            <?= e($mergeWorker['email'] ?: 'No email') ?><br>
                            <?= e($mergeWorker['phone'] ?: 'No phone') ?>
                        </p>
                        <p class="meta">
                            <?= e(trim(($mergeWorker['mailing_address'] ?? '') . ' ' . ($mergeWorker['city'] ?? '') . ' ' . ($mergeWorker['state'] ?? '') . ' ' . ($mergeWorker['zip_code'] ?? '')) ?: 'No address') ?>
                        </p>
                        <p>
                            <span class="badge badge-muted"><?= e((string) (int) $mergeWorker['assignment_count']) ?> assignments</span>
                            <span class="badge badge-muted"><?= e((string) (int) $mergeWorker['training_count']) ?> training records</span>
                        </p>
                    <?php else: ?>
                        <p>No duplicate selected yet.</p>
                    <?php endif; ?>
                </article>
            </div>

            <?php if ($keepWorker && $mergeWorker && (int) $keepWorker['id'] !== (int) $mergeWorker['id']): ?>
                <form method="post" class="form" style="margin-top: 18px;">
                    <input type="hidden" name="q" value="<?= e($query) ?>">
                    <input type="hidden" name="keep_worker_id" value="<?= e((string) $keepWorker['id']) ?>">
                    <input type="hidden" name="merge_worker_id" value="<?= e((string) $mergeWorker['id']) ?>">
                    <div class="notice warning">
                        The duplicate contact will be marked inactive. Its assignments and training signups will move to the contact being kept.
                    </div>
                    <div class="actions">
                        <button type="submit">Merge contacts</button>
                        <a class="button secondary" href="<?= e(url('departments/election/merge-workers.php?q=' . urlencode($query))) ?>">Cancel</a>
                    </div>
                </form>
            <?php elseif ($keepWorker && $mergeWorker): ?>
                <div class="notice error" style="margin-top: 18px;">Choose two different contacts.</div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($keepWorker || $mergeWorker): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Assignment Preview</h1>
            <div class="grid two-column-grid">
                <article class="card">
                    <h2>Kept contact assignments</h2>
                    <?php if ($keepAssignments): ?>
                        <ul class="plain-list">
                            <?php foreach ($keepAssignments as $assignment): ?>
                                <li>
                                    <?= e($assignment['election_name']) ?> - <?= e($assignment['precinct_name']) ?>, <?= e($assignment['position_name']) ?>
                                    <?= (int) $assignment['is_extra'] === 1 ? ' (extra)' : '' ?>
                                    <?= (int) $assignment['is_assistant_chief_judge_extra'] === 1 ? ' (Assistant Chief)' : '' ?>
                                    <?= (int) $assignment['is_active'] === 1 ? '' : ' (inactive)' ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No assignments.</p>
                    <?php endif; ?>
                </article>
                <article class="card">
                    <h2>Duplicate contact assignments</h2>
                    <?php if ($mergeAssignments): ?>
                        <ul class="plain-list">
                            <?php foreach ($mergeAssignments as $assignment): ?>
                                <li>
                                    <?= e($assignment['election_name']) ?> - <?= e($assignment['precinct_name']) ?>, <?= e($assignment['position_name']) ?>
                                    <?= (int) $assignment['is_extra'] === 1 ? ' (extra)' : '' ?>
                                    <?= (int) $assignment['is_assistant_chief_judge_extra'] === 1 ? ' (Assistant Chief)' : '' ?>
                                    <?= (int) $assignment['is_active'] === 1 ? '' : ' (inactive)' ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No assignments.</p>
                    <?php endif; ?>
                </article>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
