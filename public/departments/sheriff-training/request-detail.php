<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$request = sheriff_training_request_by_id($id);

if (!$request) {
    http_response_code(404);
    page_header('Request not found');
    echo '<main class="shell"><section class="panel"><h1>Request not found</h1><p>The selected training request could not be found.</p></section></main>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldStatus = (string) $request['status'];
    $action = $_POST['action'] ?? 'save_decision';
    $allowedActions = [
        'approve_request' => 'approved',
        'deny_request' => 'denied',
        'cancel_request' => 'cancelled',
        'complete_training' => 'completed',
    ];
    $newStatus = $allowedActions[$action] ?? $request['status'];

    if ($action === 'complete_training' && $oldStatus !== 'approved') {
        flash('error', 'Only approved requests can be marked complete.');
        redirect_to('departments/sheriff-training/request-detail.php?id=' . $id);
    }

    if (in_array($action, ['approve_request', 'deny_request', 'cancel_request'], true) && !in_array($oldStatus, ['pending', 'approved'], true)) {
        flash('error', 'This request is already in a final status.');
        redirect_to('departments/sheriff-training/request-detail.php?id=' . $id);
    }

    $comment = trim($_POST['decision_comment'] ?? '');
    $sendEmail = isset($_POST['send_email']);
    $emailSent = false;

    if ($action === 'complete_training') {
        $actualTrainingCost = sheriff_training_decimal($_POST['actual_training_cost'] ?? '0');
        $actualLodgingCost = sheriff_training_decimal($_POST['actual_lodging_cost'] ?? '0');

        $statement = db()->prepare(
            'UPDATE sheriff_training_requests
             SET status = "completed",
                 actual_training_cost = :actual_training_cost,
                 actual_lodging_cost = :actual_lodging_cost,
                 decision_comment = :decision_comment,
                 decision_by_user_id = :decision_by_user_id,
                 decision_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'actual_training_cost' => $actualTrainingCost,
            'actual_lodging_cost' => $actualLodgingCost,
            'decision_comment' => $comment ?: null,
            'decision_by_user_id' => current_user()['id'] ?? null,
        ]);
    } elseif (array_key_exists($action, $allowedActions)) {
        $statement = db()->prepare(
            'UPDATE sheriff_training_requests
             SET status = :status,
                 decision_comment = :decision_comment,
                 decision_by_user_id = :decision_by_user_id,
                 decision_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'status' => $newStatus,
            'decision_comment' => $comment ?: null,
            'decision_by_user_id' => current_user()['id'] ?? null,
        ]);
    } else {
        flash('error', 'Select a valid request action.');
        redirect_to('departments/sheriff-training/request-detail.php?id=' . $id);
    }

    $request = sheriff_training_request_by_id($id);
    if ($sendEmail) {
        $emailSent = sheriff_training_send_status_email($request, current_user(), $comment);
        if ($emailSent) {
            $statement = db()->prepare('UPDATE sheriff_training_requests SET status_email_sent_at = NOW() WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
    }

    $historyStatement = db()->prepare(
        'INSERT INTO sheriff_training_request_history
            (request_id, changed_by_user_id, old_status, new_status, comment, email_sent)
         VALUES
            (:request_id, :changed_by_user_id, :old_status, :new_status, :comment, :email_sent)'
    );
    $historyStatement->execute([
        'request_id' => $id,
        'changed_by_user_id' => current_user()['id'] ?? null,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'comment' => $comment ?: null,
        'email_sent' => $emailSent ? 1 : 0,
    ]);

    audit_event('status_changed', 'sheriff_training_request', (string) $id, [
        'status' => $newStatus,
        'email_sent' => $emailSent,
    ]);

    $message = match ($action) {
        'approve_request' => 'Request approved.',
        'deny_request' => 'Request denied.',
        'cancel_request' => 'Request cancelled.',
        'complete_training' => 'Training marked complete.',
        default => 'Request updated.',
    };
    if ($sendEmail && !$emailSent) {
        flash('error', $message . ' The email could not be sent. Check that the officer has an email address and the server mail setup is working.');
    } else {
        flash('success', $emailSent ? $message . ' Email sent.' : $message);
    }
    redirect_to('departments/sheriff-training/request-detail.php?id=' . $id);
}

$request = sheriff_training_request_by_id($id);
$budget = sheriff_training_budget_summary((int) $request['fiscal_year_id'], $id);
$requestTrainingCost = sheriff_training_effective_training_cost($request);
$requestLodgingCost = sheriff_training_effective_lodging_cost($request);
$trainingAfterApproval = ($budget['training_remaining'] ?? 0) - $requestTrainingCost;
$lodgingAfterApproval = ($budget['lodging_remaining'] ?? 0) - $requestLodgingCost;

$historyStatement = db()->prepare(
    'SELECT sheriff_training_requests.*,
            sheriff_training_fiscal_years.label AS fiscal_year_label
     FROM sheriff_training_requests
     INNER JOIN sheriff_training_fiscal_years ON sheriff_training_fiscal_years.id = sheriff_training_requests.fiscal_year_id
     WHERE sheriff_training_requests.officer_id = :officer_id
       AND sheriff_training_requests.id <> :id
     ORDER BY sheriff_training_requests.start_date DESC
     LIMIT 10'
);
$historyStatement->execute([
    'officer_id' => $request['officer_id'],
    'id' => $id,
]);
$officerHistory = $historyStatement->fetchAll();

$auditStatement = db()->prepare(
    'SELECT sheriff_training_request_history.*,
            users.first_name,
            users.last_name
     FROM sheriff_training_request_history
     LEFT JOIN users ON users.id = sheriff_training_request_history.changed_by_user_id
     WHERE sheriff_training_request_history.request_id = :request_id
     ORDER BY sheriff_training_request_history.created_at DESC'
);
$auditStatement->execute(['request_id' => $id]);
$decisionHistory = $auditStatement->fetchAll();

page_header('Review Training Request');
?>
<main class="shell">
    <section class="panel">
        <h1>Review Training Request</h1>
        <p><?= e($request['class_name']) ?> for <?= e($request['first_name'] . ' ' . $request['last_name']) ?></p>
        <?php sheriff_training_navigation('requests'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="dashboard-stat-row sheriff-stat-row" style="margin-top: 18px;">
        <div class="dashboard-stat-group status-summary-group">
            <h2>Budget Impact</h2>
            <div class="grid dashboard-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(sheriff_training_money($trainingAfterApproval)) ?></h3>
                    <p>Training after approval</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(sheriff_training_money($lodgingAfterApproval)) ?></h3>
                    <p>Lodging after approval</p>
                </article>
            </div>
            <?php if ($trainingAfterApproval < 0 || $lodgingAfterApproval < 0): ?>
                <div class="notice error">Approving this request would exceed the selected fiscal year budget.</div>
            <?php endif; ?>
        </div>
        <div class="dashboard-stat-group summary-stat-group">
            <h2>Request Cost</h2>
            <div class="grid dashboard-stat-grid">
                <article class="card dashboard-stat-card">
                    <h3><?= e(sheriff_training_money($requestTrainingCost)) ?></h3>
                    <p>Training class</p>
                </article>
                <article class="card dashboard-stat-card">
                    <h3><?= e(sheriff_training_money($requestLodgingCost)) ?></h3>
                    <p>Lodging</p>
                </article>
            </div>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="page-toolbar">
            <div>
                <h1>Request Details</h1>
            </div>
            <div class="actions">
                <?php if ($request['status'] === 'approved'): ?>
                    <a class="button compact-button" href="<?= e(url('departments/sheriff-training/request-detail.php?id=' . $request['id'] . '#complete-training')) ?>">Complete training</a>
                <?php endif; ?>
                <a class="button secondary compact-button" href="<?= e(url('departments/sheriff-training/request-edit.php?id=' . $request['id'])) ?>">Edit request</a>
            </div>
        </div>
        <table class="table mobile-card-table">
            <tbody>
                <tr><th>Officer</th><td><?= e($request['first_name'] . ' ' . $request['last_name']) ?><?= $request['rank_title'] ? ', ' . e($request['rank_title']) : '' ?></td></tr>
                <tr><th>Email</th><td><?= e($request['email'] ?: 'Not set') ?></td></tr>
                <tr><th>Training</th><td><?= e($request['class_name']) ?></td></tr>
                <tr><th>Provider</th><td><?= e($request['provider'] ?: 'Not set') ?></td></tr>
                <tr><th>Location</th><td><?= e($request['location'] ?: 'Not set') ?></td></tr>
                <tr><th>Training dates</th><td><?= e(format_display_date($request['start_date'])) ?><?= $request['end_date'] && $request['end_date'] !== $request['start_date'] ? ' to ' . e(format_display_date($request['end_date'])) : '' ?></td></tr>
                <tr><th>Payment fiscal year</th><td><?= e($request['fiscal_year_label']) ?></td></tr>
                <tr><th>Status</th><td><span class="badge <?= e(sheriff_training_status_badge_class($request['status'])) ?>"><?= e(sheriff_training_status_label($request['status'])) ?></span></td></tr>
                <tr><th>Notes</th><td><?= nl2br(e($request['notes'] ?: '')) ?: 'None' ?></td></tr>
            </tbody>
        </table>
    </section>

    <?php if ($request['status'] === 'pending'): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Decision</h1>
            <p>Approve, deny, or cancel this paper request after reviewing the budget and officer history.</p>
            <form class="form compact-form" method="post">
                <input type="hidden" name="id" value="<?= e((string) $request['id']) ?>">
                <label class="span-2">
                    Supervisor comment / denial reason
                    <textarea name="decision_comment"><?= e($request['decision_comment']) ?></textarea>
                </label>
                <label class="toggle-option span-2">
                    <input type="checkbox" name="send_email" value="1" checked>
                    <span class="toggle-track" aria-hidden="true"></span>
                    <span>
                        Email officer about this decision
                        <small>Send the update from the supervisor email.</small>
                    </span>
                </label>
                <div class="actions span-2">
                    <button type="submit" name="action" value="approve_request">Approve</button>
                    <button type="submit" name="action" value="deny_request" class="secondary">Deny</button>
                    <button type="submit" name="action" value="cancel_request" class="secondary">Cancel request</button>
                </div>
            </form>
        </section>
    <?php elseif ($request['status'] === 'approved'): ?>
        <section class="panel" id="complete-training" style="margin-top: 18px;">
            <h1>Complete Training</h1>
            <p>Enter final costs and mark this class complete in one step.</p>
            <form class="form compact-form" method="post">
                <input type="hidden" name="id" value="<?= e((string) $request['id']) ?>">
                <input type="hidden" name="action" value="complete_training">
                <label>
                    Actual class cost
                    <input name="actual_training_cost" inputmode="decimal" value="<?= e((string) ($request['actual_training_cost'] ?? $request['estimated_training_cost'])) ?>">
                </label>
                <label>
                    Actual lodging cost
                    <input name="actual_lodging_cost" inputmode="decimal" value="<?= e((string) ($request['actual_lodging_cost'] ?? $request['estimated_lodging_cost'])) ?>">
                </label>
                <label class="span-2">
                    Completion comment
                    <textarea name="decision_comment"><?= e($request['decision_comment']) ?></textarea>
                </label>
                <label class="toggle-option">
                    <input type="checkbox" name="send_email" value="1">
                    <span class="toggle-track" aria-hidden="true"></span>
                    <span>
                        Email officer about completion
                        <small>Usually off unless the officer needs a status update.</small>
                    </span>
                </label>
                <div class="actions span-2">
                    <button type="submit">Mark complete</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Final Status</h1>
            <p>This request is marked <?= e(strtolower(sheriff_training_status_label($request['status']))) ?>.</p>
            <?php if ($request['decision_comment']): ?>
                <p><strong>Comment:</strong> <?= e($request['decision_comment']) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel" style="margin-top: 18px;">
        <h1>Officer Training History</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Training</th>
                    <th>Payment FY</th>
                    <th>Status</th>
                    <th>Cost Used</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officerHistory as $history): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($history['start_date'])) ?></td>
                        <td data-label="Training"><?= e($history['class_name']) ?></td>
                        <td data-label="Payment FY"><?= e($history['fiscal_year_label']) ?></td>
                        <td data-label="Status"><?= e(sheriff_training_status_label($history['status'])) ?></td>
                        <td data-label="Cost Used"><?= e(sheriff_training_money(sheriff_training_effective_training_cost($history) + sheriff_training_effective_lodging_cost($history))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$officerHistory): ?>
                    <tr><td colspan="5">No other training history is recorded for this officer.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Decision History</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Supervisor</th>
                    <th>Email</th>
                    <th>Comment</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($decisionHistory as $history): ?>
                    <tr>
                        <td data-label="Date"><?= e(format_display_date($history['created_at'])) ?></td>
                        <td data-label="Status"><?= e(sheriff_training_status_label($history['new_status'])) ?></td>
                        <td data-label="Supervisor"><?= e(trim(($history['first_name'] ?? '') . ' ' . ($history['last_name'] ?? '')) ?: 'Unknown') ?></td>
                        <td data-label="Email"><?= (int) $history['email_sent'] === 1 ? 'Sent' : 'Not sent' ?></td>
                        <td data-label="Comment"><?= e($history['comment'] ?: '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$decisionHistory): ?>
                    <tr><td colspan="5">No decision history has been recorded.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
