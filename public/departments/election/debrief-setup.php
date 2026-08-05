<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$portalUser = current_user();
$periods = db()->query('SELECT * FROM election_periods ORDER BY starts_on DESC, name')->fetchAll();

$debriefPeriodId = (int) ($_GET['debrief_period_id'] ?? $_POST['debrief_period_id'] ?? 0);
if ($debriefPeriodId === 0) {
    foreach ($periods as $period) {
        if ((int) $period['is_active'] === 1) {
            $debriefPeriodId = (int) $period['id'];
            break;
        }
    }
}
if ($debriefPeriodId === 0 && $periods) {
    $debriefPeriodId = (int) $periods[0]['id'];
}

$responseTypes = [
    'long_text' => 'Long text',
    'short_text' => 'Short text',
    'yes_no' => 'Yes / No',
    'rating' => 'Rating 1-5',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_debrief_question') {
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
        redirect_to('departments/election/debrief-setup.php?debrief_period_id=' . $templatePeriodId);
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
    redirect_to('departments/election/debrief-setup.php?debrief_period_id=' . $templatePeriodId);
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

page_header('Debrief Questions Setup');
?>
<main class="shell">
    <section class="panel">
        <h1>Debrief Questions Setup</h1>
        <p>Create the questions Chief Judges will answer after election day.</p>
        <?php election_navigation('debrief-setup'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Debrief Questions</h1>
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

        <h2>Question List</h2>
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
