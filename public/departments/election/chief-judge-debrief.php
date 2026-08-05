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

$responseTypes = [
    'long_text' => 'Long text',
    'short_text' => 'Short text',
    'yes_no' => 'Yes / No',
    'rating' => 'Rating 1-5',
];

function election_debrief_response_field(array $question, ?string $value): void
{
    $questionId = (int) $question['id'];
    $name = 'answers[' . $questionId . ']';
    $value = (string) $value;
    $required = (int) $question['is_required'] === 1 ? 'required' : '';

    if ($question['response_type'] === 'yes_no') {
        ?>
        <select name="<?= e($name) ?>" <?= $required ?>>
            <option value="">Select answer</option>
            <option value="Yes" <?= $value === 'Yes' ? 'selected' : '' ?>>Yes</option>
            <option value="No" <?= $value === 'No' ? 'selected' : '' ?>>No</option>
        </select>
        <?php
        return;
    }

    if ($question['response_type'] === 'rating') {
        ?>
        <select name="<?= e($name) ?>" <?= $required ?>>
            <option value="">Select rating</option>
            <?php for ($rating = 1; $rating <= 5; $rating++): ?>
                <option value="<?= e((string) $rating) ?>" <?= $value === (string) $rating ? 'selected' : '' ?>><?= e((string) $rating) ?></option>
            <?php endfor; ?>
        </select>
        <?php
        return;
    }

    if ($question['response_type'] === 'short_text') {
        ?>
        <input name="<?= e($name) ?>" value="<?= e($value) ?>" <?= $required ?>>
        <?php
        return;
    }

    ?>
    <textarea name="<?= e($name) ?>" <?= $required ?>><?= e($value) ?></textarea>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_debrief') {
    $precinctId = (int) ($_POST['precinct_id'] ?? 0);
    $submitMode = ($_POST['submit_mode'] ?? 'draft') === 'submit';

    if ($selectedPeriodId <= 0 || !in_array($precinctId, $allowedPrecinctIds, true)) {
        flash('error', 'Unable to save that debrief.');
        redirect_to('departments/election/chief-judge-debrief.php');
    }

    $questionStatement = db()->prepare(
        'SELECT *
         FROM election_debrief_questions
         WHERE election_period_id = :election_period_id
           AND is_active = 1
         ORDER BY sort_order, question_text'
    );
    $questionStatement->execute(['election_period_id' => $selectedPeriodId]);
    $questionsForSave = $questionStatement->fetchAll();

    $answers = (array) ($_POST['answers'] ?? []);
    if ($submitMode) {
        foreach ($questionsForSave as $question) {
            if ((int) $question['is_required'] === 1 && trim((string) ($answers[(int) $question['id']] ?? '')) === '') {
                flash('error', 'Complete all required questions before submitting.');
                redirect_to('departments/election/chief-judge-debrief.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
            }
        }
    }

    $statement = db()->prepare(
        'INSERT INTO election_debrief_responses (
            election_period_id, precinct_id, submitted_at, submitted_by_user_id, submitted_by_assignment_id, other_comments
         ) VALUES (
            :election_period_id, :precinct_id, :submitted_at, :submitted_by_user_id, :submitted_by_assignment_id, :other_comments
         )
         ON DUPLICATE KEY UPDATE
            submitted_at = CASE
                WHEN VALUES(submitted_at) IS NULL THEN submitted_at
                ELSE VALUES(submitted_at)
            END,
            submitted_by_user_id = VALUES(submitted_by_user_id),
            submitted_by_assignment_id = VALUES(submitted_by_assignment_id),
            other_comments = VALUES(other_comments)'
    );
    $statement->execute([
        'election_period_id' => $selectedPeriodId,
        'precinct_id' => $precinctId,
        'submitted_at' => $submitMode ? date('Y-m-d H:i:s') : null,
        'submitted_by_user_id' => $portalUser['id'] ?? null,
        'submitted_by_assignment_id' => $currentAssignment['id'] ?? null,
        'other_comments' => trim($_POST['other_comments'] ?? ''),
    ]);

    $responseStatement = db()->prepare(
        'SELECT id
         FROM election_debrief_responses
         WHERE election_period_id = :election_period_id
           AND precinct_id = :precinct_id'
    );
    $responseStatement->execute([
        'election_period_id' => $selectedPeriodId,
        'precinct_id' => $precinctId,
    ]);
    $responseId = (int) $responseStatement->fetchColumn();

    $answerStatement = db()->prepare(
        'INSERT INTO election_debrief_answers (response_id, question_id, answer_text)
         VALUES (:response_id, :question_id, :answer_text)
         ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text)'
    );
    foreach ($questionsForSave as $question) {
        $answerStatement->execute([
            'response_id' => $responseId,
            'question_id' => (int) $question['id'],
            'answer_text' => trim((string) ($answers[(int) $question['id']] ?? '')),
        ]);
    }

    audit_event($submitMode ? 'submitted' : 'saved', 'election_debrief_response', (string) $responseId, [
        'election_period_id' => $selectedPeriodId,
        'precinct_id' => $precinctId,
    ]);
    flash('success', $submitMode ? 'Debrief submitted.' : 'Debrief draft saved.');
    redirect_to('departments/election/chief-judge-debrief.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
}

$questions = [];
if ($selectedPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT *
         FROM election_debrief_questions
         WHERE election_period_id = :election_period_id
           AND is_active = 1
         ORDER BY sort_order, question_text'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    $questions = $statement->fetchAll();
}

$responsesByPrecinct = [];
if ($selectedPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT *
         FROM election_debrief_responses
         WHERE election_period_id = :election_period_id'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    foreach ($statement->fetchAll() as $responseRow) {
        $responsesByPrecinct[(int) $responseRow['precinct_id']] = $responseRow;
    }
}

$selectedResponse = $selectedPrecinctId > 0 ? ($responsesByPrecinct[$selectedPrecinctId] ?? null) : null;
$answersByQuestion = [];
if ($selectedResponse) {
    $statement = db()->prepare('SELECT * FROM election_debrief_answers WHERE response_id = :response_id');
    $statement->execute(['response_id' => (int) $selectedResponse['id']]);
    foreach ($statement->fetchAll() as $answer) {
        $answersByQuestion[(int) $answer['question_id']] = $answer['answer_text'];
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

page_header('Chief Judge Debrief');
?>
<main class="shell">
    <section class="panel">
        <h1>Chief Judge Debrief</h1>
        <p>Collect post-election feedback from each precinct.</p>
        <?php election_navigation('chief-judge-debrief'); ?>

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
                <button type="submit">View debrief</button>
            </div>
        </form>
    </section>

    <?php if ($isManager && $selectedPrecinctId === 0): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Debrief Status</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Precinct</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($precincts as $precinct): ?>
                        <?php $response = $responsesByPrecinct[(int) $precinct['id']] ?? null; ?>
                        <tr>
                            <td data-label="Precinct"><?= e($precinct['name']) ?></td>
                            <td data-label="Status">
                                <?php if ($response && !empty($response['submitted_at'])): ?>
                                    <span class="badge badge-success">Submitted</span>
                                <?php elseif ($response): ?>
                                    <span class="badge badge-muted">Draft</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Not started</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Submitted"><?= $response && !empty($response['submitted_at']) ? e(format_display_date($response['submitted_at']) . ' ' . format_display_time($response['submitted_at'])) : '' ?></td>
                            <td data-label="Actions">
                                <a class="button secondary compact-button" href="<?= e(url('departments/election/chief-judge-debrief.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . (int) $precinct['id'])) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$precincts): ?>
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
                    <h1><?= e($selectedPrecinct['name']) ?> Debrief</h1>
                    <p class="muted"><?= e($selectedPeriod['name'] ?? 'Selected election') ?></p>
                </div>
                <?php if ($selectedResponse && !empty($selectedResponse['submitted_at'])): ?>
                    <span class="badge badge-success">Submitted <?= e(format_display_date($selectedResponse['submitted_at'])) ?></span>
                <?php elseif ($selectedResponse): ?>
                    <span class="badge badge-muted">Draft</span>
                <?php endif; ?>
            </div>

            <?php if (!$questions): ?>
                <p>No debrief questions have been created for this election.</p>
            <?php else: ?>
                <form method="post" class="form debrief-form">
                    <input type="hidden" name="action" value="save_debrief">
                    <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                    <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                    <?php foreach ($questions as $question): ?>
                        <label class="span-2 debrief-question">
                            <?= e($question['question_text']) ?><?= (int) $question['is_required'] === 1 ? ' *' : '' ?>
                            <?php if (!empty($question['help_text'])): ?>
                                <span class="meta"><?= e($question['help_text']) ?></span>
                            <?php endif; ?>
                            <?php election_debrief_response_field($question, $answersByQuestion[(int) $question['id']] ?? ''); ?>
                        </label>
                    <?php endforeach; ?>
                    <label class="span-2">
                        Other comments
                        <textarea name="other_comments"><?= e($selectedResponse['other_comments'] ?? '') ?></textarea>
                    </label>
                    <div class="actions span-2">
                        <button type="submit" name="submit_mode" value="draft" class="secondary">Save draft</button>
                        <button type="submit" name="submit_mode" value="submit">Submit debrief</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
