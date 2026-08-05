<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$portalUser = current_user();
$periods = db()->query('SELECT * FROM election_periods ORDER BY is_active DESC, starts_on DESC, name')->fetchAll();
$precincts = election_precincts();

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

$noteTypes = [
    'incident' => 'Incident',
    'reminder' => 'Reminder',
    'follow_up' => 'Follow-up',
    'facility' => 'Facility',
    'equipment' => 'Equipment',
    'staffing' => 'Staffing',
    'other' => 'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_note') {
        $precinctId = (int) ($_POST['precinct_id'] ?? 0);
        $noteType = (string) ($_POST['note_type'] ?? 'other');
        $noteText = trim($_POST['note_text'] ?? '');

        if ($selectedPeriodId <= 0 || !in_array($precinctId, $allowedPrecinctIds, true) || $noteText === '') {
            flash('error', 'Select an election, select a precinct, and enter a note before saving.');
            redirect_to('departments/election/precinct-notes.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
        }

        if (!array_key_exists($noteType, $noteTypes)) {
            $noteType = 'other';
        }

        $statement = db()->prepare(
            'INSERT INTO election_precinct_notes (
                election_period_id, precinct_id, note_type, note_text, created_by_user_id
             ) VALUES (
                :election_period_id, :precinct_id, :note_type, :note_text, :created_by_user_id
             )'
        );
        $statement->execute([
            'election_period_id' => $selectedPeriodId,
            'precinct_id' => $precinctId,
            'note_type' => $noteType,
            'note_text' => $noteText,
            'created_by_user_id' => $portalUser['id'] ?? null,
        ]);
        $noteId = (int) db()->lastInsertId();

        audit_event('created', 'election_precinct_note', (string) $noteId, [
            'election_period_id' => $selectedPeriodId,
            'precinct_id' => $precinctId,
            'note_type' => $noteType,
        ]);
        flash('success', 'Precinct note saved.');
        redirect_to('departments/election/precinct-notes.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $precinctId);
    }

    if ($action === 'toggle_resolved') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $isResolved = isset($_POST['is_resolved']) ? 1 : 0;

        $statement = db()->prepare(
            'UPDATE election_precinct_notes
             SET is_resolved = :is_resolved
             WHERE id = :id
               AND election_period_id = :election_period_id'
        );
        $statement->execute([
            'is_resolved' => $isResolved,
            'id' => $noteId,
            'election_period_id' => $selectedPeriodId,
        ]);

        audit_event($isResolved ? 'resolved' : 'reopened', 'election_precinct_note', (string) $noteId, []);
        flash('success', $isResolved ? 'Note marked resolved.' : 'Note reopened.');
        redirect_to('departments/election/precinct-notes.php?election_period_id=' . $selectedPeriodId . '&precinct_id=' . $selectedPrecinctId);
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

$noteSql = 'SELECT election_precinct_notes.*,
                   election_periods.name AS election_name,
                   election_precincts.name AS precinct_name,
                   CONCAT(users.first_name, " ", users.last_name) AS created_by_name
            FROM election_precinct_notes
            INNER JOIN election_periods ON election_periods.id = election_precinct_notes.election_period_id
            INNER JOIN election_precincts ON election_precincts.id = election_precinct_notes.precinct_id
            LEFT JOIN users ON users.id = election_precinct_notes.created_by_user_id
            WHERE election_precinct_notes.election_period_id = :election_period_id';
$noteParams = ['election_period_id' => $selectedPeriodId];
if ($selectedPrecinctId > 0) {
    $noteSql .= ' AND election_precinct_notes.precinct_id = :precinct_id';
    $noteParams['precinct_id'] = $selectedPrecinctId;
}
$noteSql .= ' ORDER BY election_precinct_notes.is_resolved ASC,
                     election_precincts.name,
                     election_precinct_notes.created_at DESC';
$statement = db()->prepare($noteSql);
$statement->execute($noteParams);
$notes = $selectedPeriodId > 0 ? $statement->fetchAll() : [];

$openCount = 0;
$resolvedCount = 0;
foreach ($notes as $note) {
    if ((int) $note['is_resolved'] === 1) {
        $resolvedCount++;
    } else {
        $openCount++;
    }
}

page_header('Precinct Notes');
?>
<main class="shell">
    <section class="panel">
        <h1>Precinct Notes</h1>
        <p>Keep supervisor notes, incidents, follow-ups, and reminders by precinct.</p>
        <?php election_navigation('precinct-notes'); ?>

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
            <div class="actions">
                <button type="submit">View notes</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Add Note</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
            <label>
                Precinct
                <select name="precinct_id" required>
                    <option value="">Select precinct</option>
                    <?php foreach ($precincts as $precinct): ?>
                        <option value="<?= e((string) $precinct['id']) ?>" <?= $selectedPrecinctId === (int) $precinct['id'] ? 'selected' : '' ?>>
                            <?= e($precinct['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Type
                <select name="note_type" required>
                    <?php foreach ($noteTypes as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">
                Note
                <textarea name="note_text" required placeholder="Add incident details, reminders, or things to remember for next time."></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Add note</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div class="section-heading-row">
            <div>
                <h1>Notes</h1>
                <p class="muted"><?= e($selectedPeriod['name'] ?? 'Selected election') ?><?= $selectedPrecinct ? ' - ' . e($selectedPrecinct['name']) : ' - All precincts' ?></p>
            </div>
            <div class="actions">
                <span class="badge badge-warning"><?= e((string) $openCount) ?> open</span>
                <span class="badge badge-muted"><?= e((string) $resolvedCount) ?> resolved</span>
            </div>
        </div>

        <div class="precinct-note-list">
            <?php foreach ($notes as $note): ?>
                <article class="card precinct-note-card <?= (int) $note['is_resolved'] === 1 ? 'is-resolved' : '' ?>">
                    <div class="section-heading-row">
                        <div>
                            <h2><?= e($noteTypes[$note['note_type']] ?? 'Other') ?></h2>
                            <p class="muted">
                                <?= e($note['precinct_name']) ?> - <?= e(format_display_date($note['created_at'])) ?> <?= e(format_display_time($note['created_at'])) ?>
                                <?php if (!empty($note['created_by_name'])): ?>
                                    - <?= e($note['created_by_name']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="badge <?= (int) $note['is_resolved'] === 1 ? 'badge-success' : 'badge-warning' ?>">
                            <?= (int) $note['is_resolved'] === 1 ? 'Resolved' : 'Open' ?>
                        </span>
                    </div>
                    <p><?= nl2br(e($note['note_text'])) ?></p>
                    <form method="post" class="actions">
                        <input type="hidden" name="action" value="toggle_resolved">
                        <input type="hidden" name="election_period_id" value="<?= e((string) $selectedPeriodId) ?>">
                        <input type="hidden" name="precinct_id" value="<?= e((string) $selectedPrecinctId) ?>">
                        <input type="hidden" name="note_id" value="<?= e((string) $note['id']) ?>">
                        <?php if ((int) $note['is_resolved'] === 1): ?>
                            <button type="submit" class="secondary compact-button">Reopen</button>
                        <?php else: ?>
                            <input type="hidden" name="is_resolved" value="1">
                            <button type="submit" class="secondary compact-button">Mark resolved</button>
                        <?php endif; ?>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (!$notes): ?>
                <p>No precinct notes were found for this selection.</p>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php page_footer(); ?>
