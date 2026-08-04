<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_manager('dare');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_lesson') {
        $statement = db()->prepare(
            'INSERT INTO dare_lessons (title, sort_order, notes, is_active)
             VALUES (:title, :sort_order, :notes, 1)'
        );
        $statement->execute([
            'title' => trim($_POST['title'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'notes' => trim($_POST['notes'] ?? ''),
        ]);

        $lessonId = (int) db()->lastInsertId();
        audit_event('created', 'dare_lesson', (string) $lessonId, ['title' => trim($_POST['title'] ?? '')]);
        flash('success', 'Lesson added.');
        redirect_to('departments/dare/lessons.php');
    }

    if ($action === 'sync_empty_classes') {
        $statement = db()->prepare(
            'INSERT IGNORE INTO dare_class_lessons (class_id, lesson_id, lesson_title, sort_order)
             SELECT dare_classes.id, dare_lessons.id, dare_lessons.title, dare_lessons.sort_order
             FROM dare_classes
             CROSS JOIN dare_lessons
             WHERE dare_lessons.is_active = 1
               AND NOT EXISTS (
                    SELECT 1
                    FROM dare_class_lessons
                    WHERE dare_class_lessons.class_id = dare_classes.id
               )'
        );
        $statement->execute();

        audit_event('synced', 'dare_lesson', null, ['scope' => 'classes_without_lessons']);
        flash('success', 'Active lessons were attached to classes that did not have a lesson plan yet.');
        redirect_to('departments/dare/lessons.php');
    }
}

$lessons = db()->query('SELECT * FROM dare_lessons ORDER BY sort_order, title')->fetchAll();

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Schools & officers', 'href' => url('departments/dare/lookups.php')],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
];

page_header('DARE Lessons');
?>
<main class="shell">
    <section class="panel">
        <h1>DARE Lessons</h1>
        <p>Manage the master lesson list used for future DARE classes.</p>
        <?php dare_navigation('lessons'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Add Lesson</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="add_lesson">
            <label>
                Lesson name
                <input name="title" required>
            </label>
            <label>
                Sort order
                <input type="number" name="sort_order" value="0" min="0">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Add lesson</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Lesson List</h1>
        <form method="post" class="actions" style="margin-bottom: 14px;">
            <input type="hidden" name="action" value="sync_empty_classes">
            <button type="submit" class="secondary">Attach active lessons to classes without lessons</button>
        </form>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Lesson</th>
                    <th>Notes</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lessons as $lesson): ?>
                    <tr>
                        <td data-label="Order"><?= e((string) $lesson['sort_order']) ?></td>
                        <td data-label="Lesson"><?= e($lesson['title']) ?></td>
                        <td data-label="Notes"><?= e($lesson['notes'] ?: 'None') ?></td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $lesson['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $lesson['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dare/lesson-edit.php?id=' . $lesson['id'])) ?>" title="Edit lesson" aria-label="Edit lesson">&#9998;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lessons): ?>
                    <tr>
                        <td colspan="5">No lessons have been added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
