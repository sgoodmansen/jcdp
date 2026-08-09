<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();
election_require_day_checklist_setup();

$worker = current_election_worker();
$assignment = current_election_assignment();
if ($worker && !$assignment) {
    redirect_to('departments/election/select-assignment.php');
}

if (!$assignment || !election_assignment_is_chief_judge($assignment)) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>Chief Judge feedback is only available to the assigned Chief Judge.</p></section></main>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge_feedback') {
    $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
    $statement = db()->prepare(
        'UPDATE election_chief_feedback
         SET acknowledged_at = COALESCE(acknowledged_at, NOW())
         WHERE id = :id
           AND chief_assignment_id = :chief_assignment_id'
    );
    $statement->execute([
        'id' => $feedbackId,
        'chief_assignment_id' => (int) $assignment['id'],
    ]);

    audit_event('acknowledged', 'election_chief_feedback', (string) $feedbackId, [
        'assignment_id' => (int) $assignment['id'],
    ]);
    flash('success', 'Feedback acknowledged.');
    redirect_to('departments/election/my-feedback.php');
}

$categories = election_feedback_categories();
$statement = db()->prepare(
    'SELECT election_chief_feedback.*,
            election_periods.name AS election_name,
            election_precincts.name AS precinct_name,
            CONCAT(users.first_name, " ", users.last_name) AS created_by_name
     FROM election_chief_feedback
     INNER JOIN election_periods ON election_periods.id = election_chief_feedback.election_period_id
     INNER JOIN election_precincts ON election_precincts.id = election_chief_feedback.precinct_id
     LEFT JOIN users ON users.id = election_chief_feedback.created_by_user_id
     WHERE election_chief_feedback.chief_assignment_id = :chief_assignment_id
     ORDER BY election_chief_feedback.acknowledged_at IS NULL DESC,
              election_chief_feedback.created_at DESC'
);
$statement->execute(['chief_assignment_id' => (int) $assignment['id']]);
$feedbackMessages = $statement->fetchAll();

$unreadCount = 0;
foreach ($feedbackMessages as $feedback) {
    if (empty($feedback['acknowledged_at'])) {
        $unreadCount++;
    }
}

page_header('My Feedback');
?>
<main class="shell">
    <section class="panel">
        <h1>My Feedback</h1>
        <p><?= e(election_person_name($worker)) ?> - <?= e($assignment['precinct_name']) ?></p>
        <?php election_navigation('my-feedback'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Chief Judge Feedback</h1>
                <p class="muted">Review feedback from the Election Supervisor. Call the Election Supervisor if you would like to discuss anything listed here.</p>
            </div>
            <span class="badge <?= $unreadCount > 0 ? 'badge-warning' : 'badge-success' ?>"><?= e((string) $unreadCount) ?> new</span>
        </div>

        <div class="precinct-note-list">
            <?php foreach ($feedbackMessages as $feedback): ?>
                <article class="card precinct-note-card">
                    <div class="section-heading-row">
                        <div>
                            <h2><?= e($categories[$feedback['category']] ?? 'Other') ?></h2>
                            <p class="muted">
                                <?= e($feedback['election_name']) ?> - <?= e($feedback['precinct_name']) ?>
                                - <?= e(format_display_date($feedback['created_at'])) ?>
                            </p>
                        </div>
                        <?php if (!empty($feedback['acknowledged_at'])): ?>
                            <span class="badge badge-success">Acknowledged <?= e(format_display_date($feedback['acknowledged_at'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-warning">New</span>
                        <?php endif; ?>
                    </div>
                    <p><?= nl2br(e($feedback['message_text'])) ?></p>
                    <?php if (empty($feedback['acknowledged_at'])): ?>
                        <form method="post" class="actions">
                            <input type="hidden" name="action" value="acknowledge_feedback">
                            <input type="hidden" name="feedback_id" value="<?= e((string) $feedback['id']) ?>">
                            <button type="submit" class="secondary compact-button">Acknowledge</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$feedbackMessages): ?>
                <p>No Chief Judge feedback has been added for this assignment.</p>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php page_footer(); ?>
