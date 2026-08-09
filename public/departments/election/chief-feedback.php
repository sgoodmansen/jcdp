<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$portalUser = current_user();
$categories = election_feedback_categories();
$periods = db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll();
$precincts = election_precincts(false);

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

$selectedPrecinctId = (int) ($_GET['precinct_id'] ?? $_POST['precinct_id'] ?? 0);
$allowedPrecinctIds = array_map(fn($precinct) => (int) $precinct['id'], $precincts);
if ($selectedPrecinctId > 0 && !in_array($selectedPrecinctId, $allowedPrecinctIds, true)) {
    $selectedPrecinctId = 0;
}
$categoryFilter = (string) ($_GET['category'] ?? '');
if ($categoryFilter !== '' && !array_key_exists($categoryFilter, $categories)) {
    $categoryFilter = '';
}
$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'unacknowledged', 'acknowledged'], true)) {
    $statusFilter = 'all';
}
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$feedbackBaseQuery = [
    'election_period_id' => $selectedPeriodId,
    'precinct_id' => $selectedPrecinctId,
    'category' => $categoryFilter,
    'status' => $statusFilter,
    'q' => $searchQuery,
];
$feedbackBaseQuery = array_filter(
    $feedbackBaseQuery,
    fn($value) => !($value === '' || $value === 0 || $value === 'all')
);

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

function election_feedback_chief_assignment(int $periodId, int $precinctId): ?array
{
    $statement = db()->prepare(
        'SELECT election_worker_assignments.*,
                election_workers.first_name,
                election_workers.last_name,
                election_positions.name AS position_name,
                election_precincts.name AS precinct_name
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_worker_assignments.precinct_id = :precinct_id
           AND election_positions.is_chief_judge = 1
         ORDER BY election_worker_assignments.is_active DESC, election_worker_assignments.id DESC
         LIMIT 1'
    );
    $statement->execute([
        'election_period_id' => $periodId,
        'precinct_id' => $precinctId,
    ]);
    $assignment = $statement->fetch();

    return $assignment ?: null;
}

function election_feedback_preview(string $message, int $limit = 140): string
{
    $message = trim(preg_replace('/\s+/', ' ', $message));
    if (strlen($message) <= $limit) {
        return $message;
    }

    return rtrim(substr($message, 0, $limit - 3)) . '...';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_feedback') {
        $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
        $precinctId = (int) ($_POST['precinct_id'] ?? 0);
        $category = (string) ($_POST['category'] ?? 'other');
        $messageText = trim($_POST['message_text'] ?? '');

        if (!array_key_exists($category, $categories)) {
            $category = 'other';
        }

        if ($selectedPeriodId <= 0 || !in_array($precinctId, $allowedPrecinctIds, true) || $messageText === '') {
            flash('error', 'Select an election, select a precinct, and enter a feedback message before saving.');
            redirect_to('departments/election/chief-feedback.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
        }

        if ($feedbackId > 0) {
            $statement = db()->prepare(
                'UPDATE election_chief_feedback
                 SET category = :category,
                     message_text = :message_text,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id
                   AND election_period_id = :election_period_id'
            );
            $statement->execute([
                'category' => $category,
                'message_text' => $messageText,
                'updated_by_user_id' => $portalUser['id'] ?? null,
                'id' => $feedbackId,
                'election_period_id' => $selectedPeriodId,
            ]);
            audit_event('updated', 'election_chief_feedback', (string) $feedbackId, [
                'election_period_id' => $selectedPeriodId,
                'precinct_id' => $precinctId,
            ]);
            flash('success', 'Chief Judge feedback updated.');
        } else {
            $chiefAssignment = election_feedback_chief_assignment($selectedPeriodId, $precinctId);
            if (!$chiefAssignment) {
                flash('error', 'No Chief Judge assignment was found for that precinct.');
                redirect_to('departments/election/chief-feedback.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
            }

            $statement = db()->prepare(
                'INSERT INTO election_chief_feedback (
                    election_period_id, precinct_id, chief_assignment_id, category, message_text, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :election_period_id, :precinct_id, :chief_assignment_id, :category, :message_text, :created_by_user_id, :updated_by_user_id
                 )'
            );
            $statement->execute([
                'election_period_id' => $selectedPeriodId,
                'precinct_id' => $precinctId,
                'chief_assignment_id' => (int) $chiefAssignment['id'],
                'category' => $category,
                'message_text' => $messageText,
                'created_by_user_id' => $portalUser['id'] ?? null,
                'updated_by_user_id' => $portalUser['id'] ?? null,
            ]);
            $feedbackId = (int) db()->lastInsertId();
            audit_event('created', 'election_chief_feedback', (string) $feedbackId, [
                'election_period_id' => $selectedPeriodId,
                'precinct_id' => $precinctId,
            ]);
            flash('success', 'Chief Judge feedback saved.');
        }

        redirect_to('departments/election/chief-feedback.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
    }
}

$chiefByPrecinct = [];
if ($selectedPeriodId > 0) {
    $statement = db()->prepare(
        'SELECT election_worker_assignments.*,
                election_workers.first_name,
                election_workers.last_name,
                election_precincts.name AS precinct_name
         FROM election_worker_assignments
         INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
         INNER JOIN election_positions ON election_positions.id = election_worker_assignments.position_id
         INNER JOIN election_precincts ON election_precincts.id = election_worker_assignments.precinct_id
         WHERE election_worker_assignments.election_period_id = :election_period_id
           AND election_positions.is_chief_judge = 1
         ORDER BY election_worker_assignments.is_active DESC, election_precincts.name, election_worker_assignments.id DESC'
    );
    $statement->execute(['election_period_id' => $selectedPeriodId]);
    foreach ($statement->fetchAll() as $chiefAssignment) {
        $precinctId = (int) $chiefAssignment['precinct_id'];
        if (!isset($chiefByPrecinct[$precinctId])) {
            $chiefByPrecinct[$precinctId] = $chiefAssignment;
        }
    }
}

$feedbackSql = 'SELECT election_chief_feedback.*,
                       election_precincts.name AS precinct_name,
                       election_workers.first_name,
                       election_workers.last_name,
                       CONCAT(created_user.first_name, " ", created_user.last_name) AS created_by_name,
                       CONCAT(updated_user.first_name, " ", updated_user.last_name) AS updated_by_name
                FROM election_chief_feedback
                INNER JOIN election_precincts ON election_precincts.id = election_chief_feedback.precinct_id
                INNER JOIN election_worker_assignments ON election_worker_assignments.id = election_chief_feedback.chief_assignment_id
                INNER JOIN election_workers ON election_workers.id = election_worker_assignments.worker_id
                LEFT JOIN users AS created_user ON created_user.id = election_chief_feedback.created_by_user_id
                LEFT JOIN users AS updated_user ON updated_user.id = election_chief_feedback.updated_by_user_id
                WHERE election_chief_feedback.election_period_id = :election_period_id';
$feedbackParams = ['election_period_id' => $selectedPeriodId];
if ($selectedPrecinctId > 0) {
    $feedbackSql .= ' AND election_chief_feedback.precinct_id = :precinct_id';
    $feedbackParams['precinct_id'] = $selectedPrecinctId;
}
if ($categoryFilter !== '') {
    $feedbackSql .= ' AND election_chief_feedback.category = :category';
    $feedbackParams['category'] = $categoryFilter;
}
if ($statusFilter === 'unacknowledged') {
    $feedbackSql .= ' AND election_chief_feedback.acknowledged_at IS NULL';
} elseif ($statusFilter === 'acknowledged') {
    $feedbackSql .= ' AND election_chief_feedback.acknowledged_at IS NOT NULL';
}
if ($searchQuery !== '') {
    $feedbackSql .= ' AND (
        election_chief_feedback.message_text LIKE :search
        OR election_workers.first_name LIKE :search
        OR election_workers.last_name LIKE :search
        OR CONCAT(election_workers.first_name, " ", election_workers.last_name) LIKE :search
    )';
    $feedbackParams['search'] = '%' . $searchQuery . '%';
}
$feedbackSql .= ' ORDER BY election_chief_feedback.acknowledged_at IS NULL DESC,
                         election_precincts.name,
                         election_chief_feedback.created_at DESC';
$statement = db()->prepare($feedbackSql);
$statement->execute($feedbackParams);
$feedbackMessages = $selectedPeriodId > 0 ? $statement->fetchAll() : [];

$editFeedbackId = (int) ($_GET['edit_feedback'] ?? 0);
$editFeedback = null;
$unreadCount = 0;
foreach ($feedbackMessages as $feedback) {
    if (empty($feedback['acknowledged_at'])) {
        $unreadCount++;
    }
    if ($editFeedbackId > 0 && (int) $feedback['id'] === $editFeedbackId) {
        $editFeedback = $feedback;
    }
}

page_header('Chief Feedback');
?>
<main class="shell">
    <section class="panel">
        <h1>Chief Feedback</h1>
        <p>Send internal feedback to Chief Judges for the selected election period.</p>
        <?php election_navigation('chief-feedback'); ?>

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
                    <option value="">All precincts</option>
                    <?php foreach ($precincts as $precinct): ?>
                        <option value="<?= e((string) $precinct['id']) ?>" <?= $selectedPrecinctId === (int) $precinct['id'] ? 'selected' : '' ?>>
                            <?= e($precinct['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Category
                <select name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $categoryFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Status
                <select name="status">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="unacknowledged" <?= $statusFilter === 'unacknowledged' ? 'selected' : '' ?>>Not acknowledged</option>
                    <option value="acknowledged" <?= $statusFilter === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
                </select>
            </label>
            <label>
                Search
                <input name="q" value="<?= e($searchQuery) ?>" placeholder="Chief Judge or message">
            </label>
            <div class="actions">
                <button type="submit">View feedback</button>
                <a class="button secondary" href="<?= e(url('departments/election/chief-feedback.php')) ?>">Clear</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Add Feedback</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_feedback">
            <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
            <label>
                Precinct
                <select name="precinct_id" required>
                    <option value="">Select precinct</option>
                    <?php foreach ($precincts as $precinct): ?>
                        <?php $chiefAssignment = $chiefByPrecinct[(int) $precinct['id']] ?? null; ?>
                        <option value="<?= e((string) $precinct['id']) ?>" <?= $selectedPrecinctId === (int) $precinct['id'] ? 'selected' : '' ?> <?= !$chiefAssignment ? 'disabled' : '' ?>>
                            <?= e($precinct['name']) ?><?= $chiefAssignment ? ' - ' . e(election_person_name($chiefAssignment)) : ' - no Chief Judge' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Category
                <select name="category" required>
                    <?php foreach ($categories as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">
                Message
                <textarea name="message_text" required placeholder="Add suggestions or notes for the Chief Judge to review before the next election."></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save feedback</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Feedback Messages</h1>
                <p class="muted"><?= e($selectedPeriod['name'] ?? 'Selected election') ?><?= $selectedPrecinct ? ' - ' . e($selectedPrecinct['name']) : ' - All precincts' ?></p>
            </div>
            <span class="badge <?= $unreadCount > 0 ? 'badge-warning' : 'badge-success' ?>"><?= e((string) $unreadCount) ?> unacknowledged</span>
        </div>

        <?php if ($editFeedback): ?>
            <section class="card" style="margin-bottom: 18px;">
                <h2>Edit Feedback</h2>
                <p class="muted"><?= e($editFeedback['precinct_name']) ?> - <?= e(election_person_name($editFeedback)) ?></p>
                <form method="post" class="form compact-form">
                    <input type="hidden" name="action" value="save_feedback">
                    <input type="hidden" name="feedback_id" value="<?= e((string) $editFeedback['id']) ?>">
                    <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                    <input type="hidden" name="precinct_id" value="<?= e((string) $editFeedback['precinct_id']) ?>">
                    <label>
                        Category
                        <select name="category">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $editFeedback['category'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="span-2">
                        Message
                        <textarea name="message_text" required><?= e($editFeedback['message_text']) ?></textarea>
                    </label>
                    <p class="muted span-2">Chief Judges cannot reply here. Ask them to call the Election Supervisor if they would like to discuss this feedback.</p>
                    <div class="actions span-2">
                        <button type="submit" class="secondary compact-button">Save edits</button>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/chief-feedback.php?' . http_build_query($feedbackBaseQuery))) ?>">Cancel</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Precinct</th>
                    <th>Chief Judge</th>
                    <th>Category</th>
                    <th>Message</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($feedbackMessages as $feedback): ?>
                    <tr>
                        <td data-label="Precinct"><?= e($feedback['precinct_name']) ?></td>
                        <td data-label="Chief Judge"><?= e(election_person_name($feedback)) ?></td>
                        <td data-label="Category"><?= e($categories[$feedback['category']] ?? 'Other') ?></td>
                        <td data-label="Message">
                            <?= e(election_feedback_preview($feedback['message_text'])) ?>
                            <br>
                            <?php if (!empty($feedback['acknowledged_at'])): ?>
                                <span class="meta">Acknowledged <?= e(format_display_date($feedback['acknowledged_at'])) ?></span>
                            <?php else: ?>
                                <span class="meta">Not acknowledged</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Actions">
                            <?php $editQuery = $feedbackBaseQuery + ['edit_feedback' => (int) $feedback['id']]; ?>
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/chief-feedback.php?' . http_build_query($editQuery))) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$feedbackMessages): ?>
                    <tr><td colspan="5">No feedback messages were found for this selection.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
