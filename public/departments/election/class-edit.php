<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$copyId = (int) ($_GET['copy_id'] ?? 0);
$class = null;
$isCopy = false;

if ($id > 0) {
    $statement = db()->prepare('SELECT * FROM election_training_classes WHERE id = :id');
    $statement->execute(['id' => $id]);
    $class = $statement->fetch();

    if (!$class) {
        http_response_code(404);
        page_header('Class not found');
        echo '<main class="shell"><section class="panel"><h1>Class not found</h1><p>The selected training class could not be found.</p></section></main>';
        page_footer();
        exit;
    }
}

if ($id === 0 && $copyId > 0) {
    $statement = db()->prepare('SELECT * FROM election_training_classes WHERE id = :id');
    $statement->execute(['id' => $copyId]);
    $class = $statement->fetch();

    if (!$class) {
        http_response_code(404);
        page_header('Class not found');
        echo '<main class="shell"><section class="panel"><h1>Class not found</h1><p>The selected training class could not be copied.</p></section></main>';
        page_footer();
        exit;
    }

    $isCopy = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $params = [
        'election_period_id' => (int) ($_POST['election_period_id'] ?? 0),
        'class_title' => trim($_POST['class_title'] ?? ''),
        'class_date' => $_POST['class_date'] ?? date('Y-m-d'),
        'start_time' => $_POST['start_time'] ?? '09:00',
        'duration_minutes' => (int) ($_POST['duration_minutes'] ?? 60),
        'building_address' => title_case_address($_POST['building_address'] ?? ''),
        'room_location' => trim($_POST['room_location'] ?? ''),
        'instructor_name' => preserve_name_case($_POST['instructor_name'] ?? ''),
        'seats_total' => (int) ($_POST['seats_total'] ?? 0),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_cancelled' => isset($_POST['is_cancelled']) ? 1 : 0,
    ];

    if ($id > 0) {
        $params['id'] = $id;
        $statement = db()->prepare(
            'UPDATE election_training_classes
             SET election_period_id = :election_period_id,
                 class_title = :class_title,
                 class_date = :class_date,
                 start_time = :start_time,
                 duration_minutes = :duration_minutes,
                 building_address = :building_address,
                 room_location = :room_location,
                 instructor_name = :instructor_name,
                 seats_total = :seats_total,
                 notes = :notes,
                 is_cancelled = :is_cancelled
             WHERE id = :id'
        );
        $statement->execute($params);
        election_sync_class_positions($id, (array) ($_POST['position_ids'] ?? []));
        audit_event('updated', 'election_training_class', (string) $id, ['title' => $params['class_title']]);
        flash('success', 'Training class saved.');
        redirect_to('departments/election/class-detail.php?id=' . $id);
    }

    $params['created_by_user_id'] = current_user()['id'];
    $statement = db()->prepare(
        'INSERT INTO election_training_classes (
            election_period_id, created_by_user_id, class_title, class_date, start_time, duration_minutes,
            building_address, room_location, instructor_name, seats_total, notes, is_cancelled
         )
         VALUES (
            :election_period_id, :created_by_user_id, :class_title, :class_date, :start_time, :duration_minutes,
            :building_address, :room_location, :instructor_name, :seats_total, :notes, 0
         )'
    );
    unset($params['is_cancelled']);
    $statement->execute($params);
    $id = (int) db()->lastInsertId();
    election_sync_class_positions($id, (array) ($_POST['position_ids'] ?? []));
    audit_event('created', 'election_training_class', (string) $id, ['title' => $params['class_title']]);
    flash('success', 'Training class created.');
    redirect_to('departments/election/class-detail.php?id=' . $id);
}

$periods = election_active_periods();
$positions = election_positions();
$allowedPositionIds = $class ? election_class_allowed_position_ids($isCopy ? $copyId : (int) $class['id']) : [];

$actions = [
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php'), 'primary' => true],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

$pageTitle = $id > 0 ? 'Edit Training Class' : ($isCopy ? 'Copy Training Class' : 'New Training Class');
page_header($pageTitle);
?>
<main class="shell">
    <section class="panel">
        <h1><?= e($pageTitle) ?></h1>
        <p><?= $isCopy ? 'Review the copied details, choose the new date and time, then save the new class.' : 'Set the schedule, location, instructor, seats, and positions allowed to attend.' ?></p>
        <?php election_navigation('class-edit'); ?>

        <?php if ($isCopy): ?>
            <div class="notice success">Copied from <?= e($class['class_title']) ?>. Saving will create a new class.</div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e($isCopy ? '0' : (string) $id) ?>">
            <label>
                Election
                <select name="election_period_id" required>
                    <option value="">Select election</option>
                    <?php foreach ($periods as $period): ?>
                        <option value="<?= e((string) $period['id']) ?>" <?= (int) ($class['election_period_id'] ?? 0) === (int) $period['id'] ? 'selected' : '' ?>><?= e($period['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Class title
                <input name="class_title" value="<?= e($class['class_title'] ?? '') ?>" required>
            </label>
            <label>
                Date
                <input type="date" name="class_date" value="<?= e($class['class_date'] ?? date('Y-m-d')) ?>" required>
            </label>
            <label>
                Time
                <input type="time" name="start_time" value="<?= e(substr($class['start_time'] ?? '09:00', 0, 5)) ?>" required>
            </label>
            <label>
                Duration minutes
                <input type="number" name="duration_minutes" min="1" value="<?= e((string) ($class['duration_minutes'] ?? 60)) ?>" required>
            </label>
            <label>
                Seats
                <input type="number" name="seats_total" min="1" value="<?= e((string) ($class['seats_total'] ?? 20)) ?>" required>
            </label>
            <label>
                Building address
                <input name="building_address" value="<?= e($class['building_address'] ?? '') ?>" required>
            </label>
            <label>
                Room location
                <input name="room_location" value="<?= e($class['room_location'] ?? '') ?>">
            </label>
            <label>
                Instructor name
                <input name="instructor_name" value="<?= e($class['instructor_name'] ?? '') ?>" required>
            </label>
            <fieldset class="form-fieldset span-2">
                <legend>Positions allowed to attend</legend>
                <p class="meta">Chief Judge and Assistant Chief Judge may join any class as optional training.</p>
                <div class="checkbox-grid">
                    <?php foreach ($positions as $position): ?>
                        <?php $isOptionalTrainingPosition = (int) $position['is_chief_judge'] === 1 || (int) $position['is_assistant_chief_judge'] === 1; ?>
                        <label class="check-option">
                            <input type="checkbox" name="position_ids[]" value="<?= e((string) $position['id']) ?>" <?= in_array((int) $position['id'], $allowedPositionIds, true) || $isOptionalTrainingPosition ? 'checked' : '' ?> <?= $isOptionalTrainingPosition ? 'disabled' : '' ?>>
                            <?= e($position['name']) ?><?= $isOptionalTrainingPosition ? ' (optional)' : '' ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <?php if ($id > 0): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_cancelled" <?= (int) ($class['is_cancelled'] ?? 0) === 1 ? 'checked' : '' ?>>
                    Cancelled
                </label>
            <?php endif; ?>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($class['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $isCopy ? 'Create copied class' : 'Save class' ?></button>
                <a class="button secondary" href="<?= e(url('departments/election/classes.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
