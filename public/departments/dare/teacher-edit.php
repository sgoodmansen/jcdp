<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

$teacherId = (int) ($_GET['id'] ?? $_POST['teacher_id'] ?? 0);

if ($teacherId <= 0) {
    http_response_code(404);
    exit('Teacher not found.');
}

$statement = db()->prepare('SELECT * FROM dare_teachers WHERE id = :id');
$statement->execute(['id' => $teacherId]);
$teacher = $statement->fetch();

if (!$teacher) {
    http_response_code(404);
    exit('Teacher not found.');
}

$schools = db()->query('SELECT * FROM dare_schools WHERE is_active = 1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $statement = db()->prepare(
        'UPDATE dare_teachers
         SET school_id = :school_id,
             first_name = :first_name,
             last_name = :last_name,
             email = :email,
             is_active = :is_active
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $teacherId,
        'school_id' => (int) ($_POST['school_id'] ?? 0) ?: null,
        'first_name' => title_case_name($_POST['first_name'] ?? ''),
        'last_name' => title_case_name($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'is_active' => $isActive,
    ]);

    audit_event('updated', 'dare_teacher', (string) $teacherId, ['is_active' => $isActive]);
    flash('success', 'Teacher updated.');
    redirect_to('departments/dare/teachers.php');
}

$actions = [
    ['label' => 'Teachers', 'href' => url('departments/dare/teachers.php'), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header('Edit Teacher');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit Teacher</h1>
        <p>Update the teacher name, school, email, or active status.</p>
        <?php page_actions($actions); ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <form class="form compact-form" method="post">
            <input type="hidden" name="teacher_id" value="<?= e((string) $teacher['id']) ?>">
            <label>
                First name
                <input name="first_name" value="<?= e($teacher['first_name']) ?>" required>
            </label>
            <label>
                Last name
                <input name="last_name" value="<?= e($teacher['last_name']) ?>" required>
            </label>
            <label>
                School
                <select name="school_id">
                    <option value="">Select school</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= e((string) $school['id']) ?>" <?= (int) ($teacher['school_id'] ?? 0) === (int) $school['id'] ? 'selected' : '' ?>>
                            <?= e($school['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Email
                <input type="email" name="email" value="<?= e($teacher['email']) ?>">
            </label>
            <label class="check-label compact-check">
                <input type="checkbox" name="is_active" value="1" <?= (int) $teacher['is_active'] === 1 ? 'checked' : '' ?>>
                Active teacher
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/dare/teachers.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
