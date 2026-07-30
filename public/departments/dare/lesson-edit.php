<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_manager('dare');

$lessonId = (int) ($_GET['id'] ?? $_POST['lesson_id'] ?? 0);

if ($lessonId <= 0) {
    http_response_code(404);
    exit('Lesson not found.');
}

$statement = db()->prepare('SELECT * FROM dare_lessons WHERE id = :id');
$statement->execute(['id' => $lessonId]);
$lesson = $statement->fetch();

if (!$lesson) {
    http_response_code(404);
    exit('Lesson not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $statement = db()->prepare(
        'UPDATE dare_lessons
         SET title = :title,
             sort_order = :sort_order,
             notes = :notes,
             is_active = :is_active
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $lessonId,
        'title' => trim($_POST['title'] ?? ''),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => $isActive,
    ]);

    audit_event('updated', 'dare_lesson', (string) $lessonId, [
        'title' => trim($_POST['title'] ?? ''),
        'is_active' => $isActive,
    ]);
    flash('success', 'Lesson updated. Future classes will use the active lesson list.');
    redirect_to('departments/dare/lessons.php');
}

$actions = [
    ['label' => 'Lessons', 'href' => url('departments/dare/lessons.php'), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header('Edit Lesson');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit Lesson</h1>
        <p>Update the lesson name, display order, notes, or active status.</p>
        <?php page_actions($actions); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <form class="form compact-form" method="post">
            <input type="hidden" name="lesson_id" value="<?= e((string) $lesson['id']) ?>">
            <label>
                Lesson name
                <input name="title" value="<?= e($lesson['title']) ?>" required>
            </label>
            <label>
                Sort order
                <input type="number" name="sort_order" value="<?= e((string) $lesson['sort_order']) ?>" min="0">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($lesson['notes']) ?></textarea>
            </label>
            <label class="check-label compact-check">
                <input type="checkbox" name="is_active" value="1" <?= (int) $lesson['is_active'] === 1 ? 'checked' : '' ?>>
                Active lesson
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/dare/lessons.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
