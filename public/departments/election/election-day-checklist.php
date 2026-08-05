<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$currentAssignment = current_election_assignment();
$portalUser = current_user();
$isManager = can_manage_election_module();

$periods = $isManager
    ? db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll()
    : [];
if (!$isManager && $currentAssignment) {
    $statement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
    $statement->execute(['id' => (int) $currentAssignment['election_period_id']]);
    $periods = array_filter([$statement->fetch() ?: null]);
}

$selectedPeriodId = (int) ($_GET['election_period_id'] ?? $_POST['election_period_id'] ?? 0);
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

$selectedPrecinctId = (int) ($_GET['precinct_id'] ?? $_POST['precinct_id'] ?? 0);
$allowedPrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $precincts);
if (!$isManager && $allowedPrecinctIds) {
    $selectedPrecinctId = (int) $allowedPrecinctIds[0];
} elseif ($selectedPrecinctId > 0 && !in_array($selectedPrecinctId, $allowedPrecinctIds, true)) {
    $selectedPrecinctId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_task') {
        require_election_manager();

        $taskId = (int) ($_POST['task_id'] ?? 0);
        $params = [
            'election_period_id' => $selectedPeriodId,
            'task_title' => trim($_POST['task_title'] ?? ''),
            'instructions' => trim($_POST['instructions'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'chief_can_complete' => isset($_POST['chief_can_complete']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by_user_id' => $portalUser['id'] ?? null,
        ];

        if ($params['task_title'] === '' || $selectedPeriodId <= 0) {
            flash('error', 'Enter a task name before saving.');
            redirect_to('departments/election/election-day-checklist.php?election_period_id=' . $selectedPeriodId);
        }

        if ($taskId > 0) {
            $params['id'] = $taskId;
            unset($params['created_by_user_id']);
            $statement = db()->prepare(
                'UPDATE election_day_checklist_tasks
                 SET task_title = :task_title,
                     instructions = :instructions,
                     sort_order = :sort_order,
                     chief_can_complete = :chief_can_complete,
                     is_active = :is_active
                 WHERE id = :id
                   AND election_period_id = :election_period_id'
            );
            $statement->execute($params);
            audit_event('updated', 'election_day_checklist_task', (string) $taskId, ['title' => $params['task_title']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO election_day_checklist_tasks (
                    election_period_id, task_title, instructions, sort_order, chief_can_complete, is_active, created_by_user_id
                 ) VALUES (
                    :election_period_id, :task_title, :instructions, :sort_order, :chief_can_complete, 1, :created_by_user_id
                 )'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $taskId = (int) db()->lastInsertId();
            audit_event('created', 'election_day_checklist_task', (string) $taskId, ['title' => $params['task_title']]);
        }

        flash('success', 'Checklist task saved.');
        redirect_to('departments/election/election-day-checklist.php?election_period_id=' . $selectedPeriodId);
    }

    if ($action === 'save_equipment') {
        require_election_manager();

        $precinctId = (int) ($_POST['equipment_precinct_id'] ?? 0);
        if ($selectedPeriodId <= 0 || !in_array($precinctId, $allowedPrecinctIds, true)) {
            flash('error', 'Select a precinct before saving equipment details.');
            redirect_to('departments/election/election-day-checklist.php?election_period_id=' . $selectedPeriodId);
        }

        $statement = db()->prepare(
            'INSERT INTO election_day_equipment_schedules (
                election_period_id, precinct_id, delivery_date, delivery_time, pickup_date, pickup_time, notes, updated_by_user_id
             ) VALUES (
                :election_period_id, :precinct_id, :delivery_date, :delivery_time, :pickup_date, :pickup_time, :notes, :updated_by_user_id
             )
             ON DUPLICATE KEY UPDATE
                delivery_date = VALUES(delivery_date),
                delivery_time = VALUES(delivery_time),
                pickup_date = VALUES(pickup_date),
                pickup_time = VALUES(pickup_time),
                notes = VALUES(notes),
                updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $statement->execute([
            'election_period_id' => $selectedPeriodId,
            'precinct_id' => $precinctId,
            'delivery_date' => ($_POST['delivery_date'] ?? '') ?: null,
            'delivery_time' => ($_POST['delivery_time'] ?? '') ?: null,
            'pickup_date' => ($_POST['pickup_date'] ?? '') ?: null,
            'pickup_time' => ($_POST['pickup_time'] ?? '') ?: null,
            'notes' => trim($_POST['equipment_notes'] ?? ''),
            'updated_by_user_id' => $portalUser['id'] ?? null,
        ]);

        audit_event('updated', 'election_day_equipment_schedule', $selectedPeriodId . ':' . $precinctId, []);
        flash('success', 'Equipment schedule saved.');
        redirect_to('departments/election/election-day-checklist.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
    }

    if ($action === 'save_checklist') {
        $precinctId = (int) ($_POST['checklist_precinct_id'] ?? 0);
        if ($selectedPeriodId <= 0 || !in_array($precinctId, $allowedPrecinctIds, true)) {
            flash('error', 'Unable to save that checklist.');
            redirect_to('departments/election/election-day-checklist.php');
        }

        $taskStatement = db()->prepare(
            'SELECT id, chief_can_complete
             FROM election_day_checklist_tasks
             WHERE election_period_id = :election_period_id
               AND is_active = 1'
        );
        $taskStatement->execute(['election_period_id' => $selectedPeriodId]);
        $tasksForSave = $taskStatement->fetchAll();
        $allowedTaskIds = [];
        foreach ($tasksForSave as $task) {
            if ($isManager || (int) $task['chief_can_complete'] === 1) {
                $allowedTaskIds[] = (int) $task['id'];
            }
        }

        $completedTaskIds = array_map('intval', (array) ($_POST['completed_task_ids'] ?? []));
        $completedTaskIds = array_values(array_intersect($completedTaskIds, $allowedTaskIds));
        $uncheckedTaskIds = array_values(array_diff($allowedTaskIds, $completedTaskIds));

        if ($uncheckedTaskIds) {
            $deleteParams = [
                'election_period_id' => $selectedPeriodId,
                'precinct_id' => $precinctId,
            ];
            $taskPlaceholders = [];
            foreach ($uncheckedTaskIds as $index => $taskId) {
                $key = 'task_id_' . $index;
                $taskPlaceholders[] = ':' . $key;
                $deleteParams[$key] = $taskId;
            }
            $deleteSql = 'DELETE FROM election_day_checklist_completions
                          WHERE election_period_id = :election_period_id
                            AND precinct_id = :precinct_id
                            AND task_id IN (' . implode(',', $taskPlaceholders) . ')';
            $deleteStatement = db()->prepare($deleteSql);
            $deleteStatement->execute($deleteParams);
        }

        $insertStatement = db()->prepare(
            'INSERT INTO election_day_checklist_completions (
                election_period_id, precinct_id, task_id, completed_at, completed_by_user_id, completed_by_assignment_id
             ) VALUES (
                :election_period_id, :precinct_id, :task_id, NOW(), :completed_by_user_id, :completed_by_assignment_id
             )
             ON DUPLICATE KEY UPDATE
                completed_at = COALESCE(completed_at, NOW()),
                completed_by_user_id = VALUES(completed_by_user_id),
                completed_by_assignment_id = VALUES(completed_by_assignment_id)'
        );
        foreach ($completedTaskIds as $taskId) {
            $insertStatement->execute([
                'election_period_id' => $selectedPeriodId,
                'precinct_id' => $precinctId,
                'task_id' => $taskId,
                'completed_by_user_id' => $portalUser['id'] ?? null,
                'completed_by_assignment_id' => $currentAssignment['id'] ?? null,
            ]);
        }

        audit_event('updated', 'election_day_checklist', $selectedPeriodId . ':' . $precinctId, ['completed' => count($completedTaskIds)]);
        flash('success', 'Checklist saved.');
        redirect_to('departments/election/election-day-checklist.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
    }
}

$selectedPeriod = null;
foreach ($periods as $period) {
    if ((int) $period['id'] === $selectedPeriodId) {
        $selectedPeriod = $period;
        break;
    }
}

$selectedPrecinct = null;
foreach ($precincts as $precinct) {
    if ((int) $precinct['id'] === $selectedPrecinctId) {
        $selectedPrecinct = $precinct;
        break;
    }
}

$tasks = [];
if ($selectedPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT *
         FROM election_day_checklist_tasks
         WHERE election_period_id = :election_period_id
         ORDER BY sort_order, task_title'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    $tasks = $statement->fetchAll();
}
$activeTasks = array_values(array_filter($tasks, fn($task) => (int) $task['is_active'] === 1));
$activeTaskIds = array_map(fn($task) => (int) $task['id'], $activeTasks);

$equipmentByPrecinct = [];
if ($selectedPeriodId > 0) {
    $statement = db()->prepare('SELECT * FROM election_day_equipment_schedules WHERE election_period_id = :election_period_id');
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    foreach ($statement->fetchAll() as $row) {
        $equipmentByPrecinct[(int) $row['precinct_id']] = $row;
    }
}

$completedByPrecinct = [];
if ($selectedPeriodId > 0 && $activeTaskIds) {
    $statement = db()->prepare(
        'SELECT election_day_checklist_completions.*
         FROM election_day_checklist_completions
         INNER JOIN election_day_checklist_tasks ON election_day_checklist_tasks.id = election_day_checklist_completions.task_id
         WHERE election_day_checklist_completions.election_period_id = :election_period_id
           AND election_day_checklist_tasks.is_active = 1'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    foreach ($statement->fetchAll() as $row) {
        $completedByPrecinct[(int) $row['precinct_id']][(int) $row['task_id']] = $row;
    }
}

$overviewRows = [];
foreach ($precincts as $precinct) {
    $precinctId = (int) $precinct['id'];
    $completedCount = count($completedByPrecinct[$precinctId] ?? []);
    $equipment = $equipmentByPrecinct[$precinctId] ?? [];
    $overviewRows[] = [
        'precinct' => $precinct,
        'completed_count' => $completedCount,
        'remaining_count' => max(0, count($activeTasks) - $completedCount),
        'equipment' => $equipment,
    ];
}

$selectedEquipment = $selectedPrecinctId > 0 ? ($equipmentByPrecinct[$selectedPrecinctId] ?? []) : [];
$selectedCompletions = $selectedPrecinctId > 0 ? ($completedByPrecinct[$selectedPrecinctId] ?? []) : [];

page_header('Election Day Checklist');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Day Checklist</h1>
        <p>Track precinct preparation tasks and voting equipment delivery details.</p>
        <?php election_navigation('election-day-checklist'); ?>

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
                <select name="precinct_id">
                    <?php if ($isManager): ?>
                        <option value="">All precincts</option>
                    <?php endif; ?>
                    <?php foreach ($precincts as $precinct): ?>
                        <option value="<?= e((string) $precinct['id']) ?>" <?= $selectedPrecinctId === (int) $precinct['id'] ? 'selected' : '' ?>>
                            <?= e($precinct['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">View checklist</button>
            </div>
        </form>
    </section>

    <?php if ($isManager && $selectedPrecinctId === 0): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <h1>Precinct Progress</h1>
                <span class="badge badge-muted"><?= e((string) count($activeTasks)) ?> active tasks</span>
            </div>
            <table class="table mobile-card-table election-day-overview-table">
                <thead>
                    <tr>
                        <th>Precinct</th>
                        <th>Equipment</th>
                        <th>Progress</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overviewRows as $row): ?>
                        <?php
                        $precinct = $row['precinct'];
                        $equipment = $row['equipment'];
                        $hasDelivery = !empty($equipment['delivery_date']);
                        $hasPickup = !empty($equipment['pickup_date']);
                        ?>
                        <tr>
                            <td data-label="Precinct"><?= e($precinct['name']) ?></td>
                            <td data-label="Equipment">
                                <span class="badge <?= $hasDelivery ? 'badge-success' : 'badge-muted' ?>"><?= $hasDelivery ? 'Delivery set' : 'No delivery' ?></span>
                                <span class="badge <?= $hasPickup ? 'badge-success' : 'badge-muted' ?>"><?= $hasPickup ? 'Pickup set' : 'No pickup' ?></span>
                            </td>
                            <td data-label="Progress">
                                <?= e((string) $row['completed_count']) ?> of <?= e((string) count($activeTasks)) ?> complete
                                <?php if ($row['remaining_count'] > 0): ?>
                                    <br><span class="meta"><?= e((string) $row['remaining_count']) ?> remaining</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Actions">
                                <div class="table-actions">
                                    <a class="button secondary compact-button" href="<?= e(url('departments/election/election-day-checklist.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . (int) $precinct['id'])) ?>">Open</a>
                                    <a class="button secondary compact-button" href="<?= e(url('departments/election/election-day-checklist-print.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . (int) $precinct['id'])) ?>">Print</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$overviewRows): ?>
                        <tr><td colspan="4">No precincts are available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($selectedPrecinct): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <div>
                    <h1><?= e($selectedPrecinct['name']) ?> Checklist</h1>
                    <p class="muted"><?= e($selectedPeriod['name'] ?? 'Selected election') ?></p>
                </div>
                <a class="button secondary" href="<?= e(url('departments/election/election-day-checklist-print.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $selectedPrecinctId)) ?>">Print checklist</a>
            </div>

            <div class="election-day-equipment-summary">
                <div>
                    <strong>Equipment delivery</strong><br>
                    <span class="meta">
                        <?= !empty($selectedEquipment['delivery_date']) ? e(format_display_date($selectedEquipment['delivery_date']) . ' ' . format_display_time($selectedEquipment['delivery_time'] ?? '')) : 'Not scheduled' ?>
                    </span>
                </div>
                <div>
                    <strong>Equipment pickup</strong><br>
                    <span class="meta">
                        <?= !empty($selectedEquipment['pickup_date']) ? e(format_display_date($selectedEquipment['pickup_date']) . ' ' . format_display_time($selectedEquipment['pickup_time'] ?? '')) : 'Not scheduled' ?>
                    </span>
                </div>
                <?php if (!empty($selectedEquipment['notes'])): ?>
                    <div class="span-2">
                        <strong>Equipment notes</strong><br>
                        <span class="meta"><?= nl2br(e($selectedEquipment['notes'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post" class="election-day-checklist-form">
                <input type="hidden" name="action" value="save_checklist">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <input type="hidden" name="checklist_precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                <table class="table mobile-card-table election-day-checklist-table">
                    <thead>
                        <tr>
                            <th>Done</th>
                            <th>Task</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeTasks as $task): ?>
                            <?php
                            $taskId = (int) $task['id'];
                            $completion = $selectedCompletions[$taskId] ?? null;
                            $canCompleteTask = $isManager || (int) $task['chief_can_complete'] === 1;
                            ?>
                            <tr>
                                <td data-label="Done">
                                    <?php if ($canCompleteTask): ?>
                                        <input type="checkbox" name="completed_task_ids[]" value="<?= e((string) $taskId) ?>" <?= $completion ? 'checked' : '' ?>>
                                    <?php else: ?>
                                        <span class="meta">Supervisor</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Task">
                                    <strong><?= e($task['task_title']) ?></strong>
                                    <?php if (!empty($task['instructions'])): ?>
                                        <br><span class="meta"><?= nl2br(e($task['instructions'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Completed">
                                    <?php if ($completion): ?>
                                        <span class="badge badge-success">Complete</span>
                                        <br><span class="meta"><?= e(format_display_date($completion['completed_at'])) ?> <?= e(format_display_time($completion['completed_at'])) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-muted">Open</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$activeTasks): ?>
                            <tr><td colspan="3">No checklist tasks have been created for this election.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php if ($activeTasks): ?>
                    <div class="actions" style="margin-top: 18px;">
                        <button type="submit">Save checklist</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>

        <?php if ($isManager): ?>
            <section class="panel" style="margin-top: 18px;">
                <h1>Equipment Schedule</h1>
                <form class="form compact-form" method="post">
                    <input type="hidden" name="action" value="save_equipment">
                    <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                    <input type="hidden" name="equipment_precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                    <label>
                        Delivery date
                        <input type="date" name="delivery_date" value="<?= e($selectedEquipment['delivery_date'] ?? '') ?>">
                    </label>
                    <label>
                        Delivery time
                        <input type="time" name="delivery_time" value="<?= e($selectedEquipment['delivery_time'] ?? '') ?>">
                    </label>
                    <label>
                        Pickup date
                        <input type="date" name="pickup_date" value="<?= e($selectedEquipment['pickup_date'] ?? '') ?>">
                    </label>
                    <label>
                        Pickup time
                        <input type="time" name="pickup_time" value="<?= e($selectedEquipment['pickup_time'] ?? '') ?>">
                    </label>
                    <label class="span-2">
                        Equipment notes
                        <textarea name="equipment_notes"><?= e($selectedEquipment['notes'] ?? '') ?></textarea>
                    </label>
                    <div class="actions span-2">
                        <button type="submit">Save equipment schedule</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($isManager): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Add Checklist Task</h1>
            <form class="form compact-form" method="post">
                <input type="hidden" name="action" value="save_task">
                <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                <label>
                    Task name
                    <input name="task_title" required>
                </label>
                <label>
                    Sort order
                    <input type="number" name="sort_order" value="<?= e((string) ((count($tasks) + 1) * 10)) ?>">
                </label>
                <label class="check-label">
                    <input type="checkbox" name="chief_can_complete" checked>
                    Chief Judge can check off
                </label>
                <label class="span-2">
                    Instructions
                    <textarea name="instructions"></textarea>
                </label>
                <div class="actions span-2">
                    <button type="submit">Add task</button>
                </div>
            </form>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Checklist Template</h1>
            <table class="table mobile-card-table election-day-task-table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Options</th>
                        <th>Save</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td data-label="Task">
                                <form id="task-form-<?= e((string) $task['id']) ?>" method="post" class="form inline-edit-form">
                                    <input type="hidden" name="action" value="save_task">
                                    <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                                    <input type="hidden" name="task_id" value="<?= e((string) $task['id']) ?>">
                                    <input name="task_title" value="<?= e($task['task_title']) ?>" required>
                                    <textarea name="instructions"><?= e($task['instructions']) ?></textarea>
                                </form>
                            </td>
                            <td data-label="Options">
                                <label>
                                    Sort
                                    <input form="task-form-<?= e((string) $task['id']) ?>" type="number" name="sort_order" value="<?= e((string) $task['sort_order']) ?>">
                                </label>
                                <label class="check-label">
                                    <input form="task-form-<?= e((string) $task['id']) ?>" type="checkbox" name="chief_can_complete" <?= (int) $task['chief_can_complete'] === 1 ? 'checked' : '' ?>>
                                    Chief Judge can check off
                                </label>
                                <label class="check-label">
                                    <input form="task-form-<?= e((string) $task['id']) ?>" type="checkbox" name="is_active" <?= (int) $task['is_active'] === 1 ? 'checked' : '' ?>>
                                    Active
                                </label>
                            </td>
                            <td data-label="Save">
                                <button form="task-form-<?= e((string) $task['id']) ?>" type="submit" class="secondary compact-button">Save</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$tasks): ?>
                        <tr><td colspan="3">No tasks have been created for this election.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
