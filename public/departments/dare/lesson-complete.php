<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('departments/dare/index.php');
}

$lessonId = (int) ($_POST['class_lesson_id'] ?? 0);
$returnTo = $_POST['return_to'] ?? 'dashboard';
$action = $_POST['action'] ?? 'mark_taught';

$statement = db()->prepare(
    'SELECT dare_class_lessons.*, dare_classes.officer_id
     FROM dare_class_lessons
     INNER JOIN dare_classes ON dare_classes.id = dare_class_lessons.class_id
     WHERE dare_class_lessons.id = :id'
);
$statement->execute(['id' => $lessonId]);
$lesson = $statement->fetch();

if (!$lesson) {
    flash('error', 'Lesson was not found.');
    redirect_to('departments/dare/index.php');
}

$user = current_user();
$currentOfficerId = dare_current_officer_id($user);

if (!can_manage_department('dare') && $currentOfficerId !== (int) ($lesson['officer_id'] ?? 0)) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to update this lesson.</p></section></main>';
    page_footer();
    exit;
}

if ($action === 'undo_taught') {
    $statement = db()->prepare(
        'UPDATE dare_class_lessons
         SET completed_at = NULL,
             completed_by = NULL
         WHERE id = :id'
    );
    $statement->execute(['id' => $lessonId]);

    audit_event('lesson_completion_reversed', 'dare_class', (string) $lesson['class_id'], [
        'lesson_title' => $lesson['lesson_title'],
        'class_lesson_id' => $lessonId,
        'previous_completed_at' => $lesson['completed_at'],
        'previous_completed_by' => $lesson['completed_by'],
    ]);
    flash('success', 'Lesson marked not taught.');
} else {
    $statement = db()->prepare(
        'UPDATE dare_class_lessons
         SET completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP),
             completed_by = COALESCE(completed_by, :completed_by)
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $lessonId,
        'completed_by' => $user['id'],
    ]);

    audit_event('lesson_completed', 'dare_class', (string) $lesson['class_id'], [
        'lesson_title' => $lesson['lesson_title'],
        'class_lesson_id' => $lessonId,
    ]);
    flash('success', 'Lesson marked taught.');
}

if ($returnTo === 'class') {
    redirect_to('departments/dare/class-detail.php?id=' . $lesson['class_id']);
}

if ($returnTo === 'main_dashboard') {
    redirect_to('dashboard.php');
}

redirect_to('departments/dare/index.php');
