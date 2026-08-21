<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$portalUser = current_user();
$categories = election_feedback_categories();
$feedbackId = (int) ($_GET['id'] ?? $_POST['feedback_id'] ?? 0);

$returnQuery = [
    'election_period_id' => (int) ($_GET['election_period_id'] ?? $_POST['election_period_id'] ?? 0),
    'precinct_id' => (int) ($_GET['precinct_id'] ?? $_POST['precinct_id'] ?? 0),
    'category' => (string) ($_GET['category'] ?? $_POST['category_filter'] ?? ''),
    'status' => (string) ($_GET['status'] ?? $_POST['status'] ?? ''),
    'q' => trim((string) ($_GET['q'] ?? $_POST['q'] ?? '')),
];
$returnQuery = array_filter(
    $returnQuery,
    fn($value) => !($value === '' || $value === 0 || $value === 'all')
);

function election_chief_feedback_return_url(array $returnQuery): string
{
    $queryString = http_build_query($returnQuery);
    return url('departments/election/chief-feedback.php' . ($queryString !== '' ? '?' . $queryString : ''));
}

$statement = db()->prepare(
    'SELECT election_chief_feedback.*,
            election_periods.name AS election_name,
            election_precincts.name AS precinct_name,
            election_workers.first_name,
            election_workers.last_name
     FROM election_chief_feedback
     INNER JOIN election_periods ON election_periods.id = election_chief_feedback.election_period_id
     INNER JOIN election_precincts ON election_precincts.id = election_chief_feedback.precinct_id
     INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_chief_feedback.chief_assignment_id
     INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
     WHERE election_chief_feedback.id = :id'
);
$statement->execute(['id' => $feedbackId]);
$feedback = $statement->fetch();

if (!$feedback) {
    flash('error', 'Feedback message not found.');
    redirect_to('departments/election/chief-feedback.php');
}

if (empty($returnQuery['election_period_id'])) {
    $returnQuery['election_period_id'] = (int) $feedback['election_period_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = (string) ($_POST['category'] ?? 'other');
    $messageText = trim($_POST['message_text'] ?? '');
    $markAsNew = isset($_POST['mark_as_new']);

    if (!array_key_exists($category, $categories)) {
        $category = 'other';
    }

    if ($messageText === '') {
        flash('error', 'Enter a feedback message before saving.');
        redirect_to('departments/election/chief-feedback-edit.php?' . http_build_query(['id' => $feedbackId] + $returnQuery));
    }

    $statement = db()->prepare(
        'UPDATE election_chief_feedback
         SET category = :category,
             message_text = :message_text,
             updated_by_user_id = :updated_by_user_id,
             acknowledged_at = CASE
                 WHEN :mark_as_new = 1 THEN NULL
                 ELSE acknowledged_at
             END
         WHERE id = :id'
    );
    $statement->execute([
        'category' => $category,
        'message_text' => $messageText,
        'updated_by_user_id' => $portalUser['id'] ?? null,
        'mark_as_new' => $markAsNew ? 1 : 0,
        'id' => $feedbackId,
    ]);

    audit_event('updated', 'election_chief_feedback', (string) $feedbackId, [
        'election_period_id' => (int) $feedback['election_period_id'],
        'precinct_id' => (int) $feedback['precinct_id'],
        'marked_as_new' => $markAsNew ? 1 : 0,
    ]);
    flash('success', $markAsNew ? 'Chief Judge feedback updated and marked new.' : 'Chief Judge feedback updated.');
    redirect_to('departments/election/chief-feedback.php?' . http_build_query($returnQuery));
}

page_header('Edit Chief Feedback');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit Feedback</h1>
        <p><?= e($feedback['precinct_name']) ?> - <?= e(election_person_name($feedback)) ?></p>
        <?php election_navigation('chief-feedback'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Feedback Message</h1>
        <p class="muted"><?= e($feedback['election_name']) ?> - <?= e($feedback['precinct_name']) ?></p>
        <form method="post" class="form compact-form">
            <input type="hidden" name="feedback_id" value="<?= e((string) $feedback['id']) ?>">
            <input type="hidden" name="election_period_id" value="<?= e((string) ($returnQuery['election_period_id'] ?? 0)) ?>">
            <input type="hidden" name="precinct_id" value="<?= e((string) ($returnQuery['precinct_id'] ?? 0)) ?>">
            <input type="hidden" name="category_filter" value="<?= e((string) ($returnQuery['category'] ?? '')) ?>">
            <input type="hidden" name="status" value="<?= e((string) ($returnQuery['status'] ?? '')) ?>">
            <input type="hidden" name="q" value="<?= e((string) ($returnQuery['q'] ?? '')) ?>">
            <label>
                Category
                <select name="category">
                    <?php foreach ($categories as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $feedback['category'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">
                Message
                <textarea name="message_text" required><?= e($feedback['message_text']) ?></textarea>
            </label>
            <label class="check-label span-2">
                <input type="checkbox" name="mark_as_new">
                Mark as new for Chief Judge
            </label>
            <p class="muted span-2">Chief Judges cannot reply here. Ask them to call the Election Supervisor if they would like to discuss this feedback.</p>
            <div class="actions span-2">
                <button type="submit">Save feedback</button>
                <a class="button secondary" href="<?= e(election_chief_feedback_return_url($returnQuery)) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
