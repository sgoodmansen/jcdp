<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$portalUser = current_user();
$periods = db()->query('SELECT * FROM election_periods ORDER BY starts_on DESC, name')->fetchAll();

$checklistPeriodId = (int) ($_GET['checklist_period_id'] ?? $_POST['checklist_period_id'] ?? 0);
if ($checklistPeriodId === 0) {
    foreach ($periods as $period) {
        if ((int) $period['is_active'] === 1) {
            $checklistPeriodId = (int) $period['id'];
            break;
        }
    }
}
if ($checklistPeriodId === 0 && $periods) {
    $checklistPeriodId = (int) $periods[0]['id'];
}

$debriefPeriodId = (int) ($_GET['debrief_period_id'] ?? $_POST['debrief_period_id'] ?? 0);
if ($debriefPeriodId === 0) {
    $debriefPeriodId = $checklistPeriodId;
}

$responseTypes = [
    'long_text' => 'Long text',
    'short_text' => 'Short text',
    'yes_no' => 'Yes / No',
    'rating' => 'Rating 1-5',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'copy_checklist_tasks') {
        $sourcePeriodId = (int) ($_POST['source_period_id'] ?? 0);
        $targetPeriodId = (int) ($_POST['target_period_id'] ?? 0);

        if ($sourcePeriodId <= 0 || $targetPeriodId <= 0 || $sourcePeriodId === $targetPeriodId) {
            flash('error', 'Choose two different election periods before copying.');
            redirect_to('departments/election/election-day-setup.php?checklist_period_id=' . $targetPeriodId);
        }

        $sourceStatement = db()->prepare(
            'SELECT *
             FROM election_day_checklist_tasks
             WHERE election_period_id = :election_period_id
             ORDER BY sort_order, task_title'
        );
        $sourceStatement->execute(['election_period_id' => $sourcePeriodId]);
        $sourceTasks = $sourceStatement->fetchAll();

        $existingStatement = db()->prepare(
            'SELECT LOWER(TRIM(task_title)) AS task_key
             FROM election_day_checklist_tasks
             WHERE election_period_id = :election_period_id'
        );
        $existingStatement->execute(['election_period_id' => $targetPeriodId]);
        $existingKeys = array_flip(array_column($existingStatement->fetchAll(), 'task_key'));

        $insertStatement = db()->prepare(
            'INSERT INTO election_day_checklist_tasks (
                election_period_id, task_title, instructions, sort_order, chief_can_complete, is_active, created_by_user_id
             ) VALUES (
                :election_period_id, :task_title, :instructions, :sort_order, :chief_can_complete, :is_active, :created_by_user_id
             )'
        );

        $copied = 0;
        $skipped = 0;
        foreach ($sourceTasks as $task) {
            $taskKey = strtolower(trim((string) $task['task_title']));
            if (isset($existingKeys[$taskKey])) {
                $skipped++;
                continue;
            }

            $insertStatement->execute([
                'election_period_id' => $targetPeriodId,
                'task_title' => $task['task_title'],
                'instructions' => $task['instructions'],
                'sort_order' => (int) $task['sort_order'],
                'chief_can_complete' => (int) $task['chief_can_complete'],
                'is_active' => (int) $task['is_active'],
                'created_by_user_id' => $portalUser['id'] ?? null,
            ]);
            $existingKeys[$taskKey] = true;
            $copied++;
        }

        audit_event('copied', 'election_day_checklist_template', $sourcePeriodId . ':' . $targetPeriodId, [
            'copied' => $copied,
            'skipped' => $skipped,
        ]);
        flash('success', $copied . ' checklist tasks copied. ' . $skipped . ' skipped because they already existed.');
        redirect_to('departments/election/election-day-setup.php?checklist_period_id=' . $targetPeriodId);
    }

    if ($action === 'save_checklist_task') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $templatePeriodId = (int) ($_POST['checklist_period_id'] ?? 0);
        $params = [
            'election_period_id' => $templatePeriodId,
            'task_title' => trim($_POST['task_title'] ?? ''),
            'instructions' => trim($_POST['instructions'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'chief_can_complete' => isset($_POST['chief_can_complete']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by_user_id' => $portalUser['id'] ?? null,
        ];

        if ($params['task_title'] === '' || $templatePeriodId <= 0) {
            flash('error', 'Enter a task name and select an election before saving.');
            redirect_to('departments/election/election-day-setup.php?checklist_period_id=' . $templatePeriodId);
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
        redirect_to('departments/election/election-day-setup.php?checklist_period_id=' . $templatePeriodId);
    }

    if ($action === 'save_debrief_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $templatePeriodId = (int) ($_POST['debrief_period_id'] ?? 0);
        $responseType = (string) ($_POST['response_type'] ?? 'long_text');
        if (!array_key_exists($responseType, $responseTypes)) {
            $responseType = 'long_text';
        }

        $params = [
            'election_period_id' => $templatePeriodId,
            'question_text' => trim($_POST['question_text'] ?? ''),
            'help_text' => trim($_POST['help_text'] ?? ''),
            'response_type' => $responseType,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'is_required' => isset($_POST['is_required']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by_user_id' => $portalUser['id'] ?? null,
        ];

        if ($params['question_text'] === '' || $templatePeriodId <= 0) {
            flash('error', 'Enter a question and select an election before saving.');
            redirect_to('departments/election/election-day-setup.php?debrief_period_id=' . $templatePeriodId);
        }

        if ($questionId > 0) {
            $params['id'] = $questionId;
            unset($params['created_by_user_id']);
            $statement = db()->prepare(
                'UPDATE election_debrief_questions
                 SET question_text = :question_text,
                     help_text = :help_text,
                     response_type = :response_type,
                     sort_order = :sort_order,
                     is_required = :is_required,
                     is_active = :is_active
                 WHERE id = :id
                   AND election_period_id = :election_period_id'
            );
            $statement->execute($params);
            audit_event('updated', 'election_debrief_question', (string) $questionId, ['question' => $params['question_text']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO election_debrief_questions (
                    election_period_id, question_text, help_text, response_type, sort_order, is_required, is_active, created_by_user_id
                 ) VALUES (
                    :election_period_id, :question_text, :help_text, :response_type, :sort_order, :is_required, 1, :created_by_user_id
                 )'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $questionId = (int) db()->lastInsertId();
            audit_event('created', 'election_debrief_question', (string) $questionId, ['question' => $params['question_text']]);
        }

        flash('success', 'Debrief question saved.');
        redirect_to('departments/election/election-day-setup.php?debrief_period_id=' . $templatePeriodId);
    }
}

$checklistTasks = [];
if ($checklistPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT *
         FROM election_day_checklist_tasks
         WHERE election_period_id = :election_period_id
         ORDER BY sort_order, task_title'
    );
    $statement->execute(['election_period_id' => $checklistPeriodId]);
    $checklistTasks = $statement->fetchAll();
}

$debriefQuestions = [];
if ($debriefPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT *
         FROM election_debrief_questions
         WHERE election_period_id = :election_period_id
         ORDER BY sort_order, question_text'
    );
    $statement->execute(['election_period_id' => $debriefPeriodId]);
    $debriefQuestions = $statement->fetchAll();
}

page_header('Election Day Setup');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Day Setup</h1>
        <p>Build election day checklist tasks and Chief Judge debrief questions for each election period.</p>
        <?php election_navigation('election-day-setup'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Copy Checklist</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="copy_checklist_tasks">
            <label>
                Copy from election
                <select name="source_period_id" required>
                    <option value="">Select election</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>"><?= e($period['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Copy to election
                <select name="target_period_id" required>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $checklistPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Copy checklist</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Checklist Template</h1>
        <form class="form compact-form" method="get" style="margin-bottom: 18px;">
            <label>
                Election
                <select name="checklist_period_id" required>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $checklistPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">View template</button>
            </div>
        </form>

        <h2>Add checklist task</h2>
        <form class="form compact-form" method="post" style="margin-bottom: 22px;">
            <input type="hidden" name="action" value="save_checklist_task">
            <input type="hidden" name="checklist_period_id" value="<?= e((string) $checklistPeriodId) ?>">
            <label>
                Task name
                <input name="task_title" required>
            </label>
            <label>
                Sort order
                <input type="number" name="sort_order" value="<?= e((string) ((count($checklistTasks) + 1) * 10)) ?>">
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

        <h2>Template tasks</h2>
        <table class="table mobile-card-table election-day-task-table">
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Options</th>
                    <th>Save</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checklistTasks as $task): ?>
                    <tr>
                        <td data-label="Task">
                            <form id="task-form-<?= e((string) $task['id']) ?>" method="post" class="form inline-edit-form">
                                <input type="hidden" name="action" value="save_checklist_task">
                                <input type="hidden" name="checklist_period_id" value="<?= e((string) $checklistPeriodId) ?>">
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
                <?php if (!$checklistTasks): ?>
                    <tr><td colspan="3">No tasks have been created for this election.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Chief Judge Debrief Questions</h1>
        <form class="form compact-form" method="get" style="margin-bottom: 18px;">
            <label>
                Election
                <select name="debrief_period_id" required>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= $debriefPeriodId === (int) $period['id'] ? 'selected' : '' ?>>
                            <?= e($period['name']) ?><?= (int) $period['is_active'] === 1 ? ' (open)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">View questions</button>
            </div>
        </form>

        <h2>Add debrief question</h2>
        <form class="form compact-form" method="post" style="margin-bottom: 22px;">
            <input type="hidden" name="action" value="save_debrief_question">
            <input type="hidden" name="debrief_period_id" value="<?= e((string) $debriefPeriodId) ?>">
            <label class="span-2">
                Question
                <input name="question_text" required>
            </label>
            <label>
                Response type
                <select name="response_type">
                    <?php foreach ($responseTypes as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Sort order
                <input type="number" name="sort_order" value="<?= e((string) ((count($debriefQuestions) + 1) * 10)) ?>">
            </label>
            <label class="check-label">
                <input type="checkbox" name="is_required">
                Required
            </label>
            <label class="span-2">
                Help text
                <textarea name="help_text"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Add question</button>
            </div>
        </form>

        <h2>Debrief questions</h2>
        <table class="table mobile-card-table election-day-task-table">
            <thead>
                <tr>
                    <th>Question</th>
                    <th>Options</th>
                    <th>Save</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($debriefQuestions as $question): ?>
                    <tr>
                        <td data-label="Question">
                            <form id="debrief-question-form-<?= e((string) $question['id']) ?>" method="post" class="form inline-edit-form">
                                <input type="hidden" name="action" value="save_debrief_question">
                                <input type="hidden" name="debrief_period_id" value="<?= e((string) $debriefPeriodId) ?>">
                                <input type="hidden" name="question_id" value="<?= e((string) $question['id']) ?>">
                                <input name="question_text" value="<?= e($question['question_text']) ?>" required>
                                <textarea name="help_text"><?= e($question['help_text']) ?></textarea>
                            </form>
                        </td>
                        <td data-label="Options">
                            <label>
                                Type
                                <select form="debrief-question-form-<?= e((string) $question['id']) ?>" name="response_type">
                                    <?php foreach ($responseTypes as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $question['response_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Sort
                                <input form="debrief-question-form-<?= e((string) $question['id']) ?>" type="number" name="sort_order" value="<?= e((string) $question['sort_order']) ?>">
                            </label>
                            <label class="check-label">
                                <input form="debrief-question-form-<?= e((string) $question['id']) ?>" type="checkbox" name="is_required" <?= (int) $question['is_required'] === 1 ? 'checked' : '' ?>>
                                Required
                            </label>
                            <label class="check-label">
                                <input form="debrief-question-form-<?= e((string) $question['id']) ?>" type="checkbox" name="is_active" <?= (int) $question['is_active'] === 1 ? 'checked' : '' ?>>
                                Active
                            </label>
                        </td>
                        <td data-label="Save">
                            <button form="debrief-question-form-<?= e((string) $question['id']) ?>" type="submit" class="secondary compact-button">Save</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$debriefQuestions): ?>
                    <tr><td colspan="3">No debrief questions have been created for this election.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
