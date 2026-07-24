<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dare');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statement = db()->prepare(
        'INSERT INTO dare_teachers (school_id, first_name, last_name, email)
         VALUES (:school_id, :first_name, :last_name, :email)'
    );
    $statement->execute([
        'school_id' => (int) ($_POST['school_id'] ?? 0) ?: null,
        'first_name' => title_case_name($_POST['first_name'] ?? ''),
        'last_name' => title_case_name($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
    ]);
    $teacherId = (int) db()->lastInsertId();
    audit_event('created', 'dare_teacher', (string) $teacherId);
    flash('success', 'Teacher saved.');

    redirect_to('departments/dare/teachers.php');
}

$schools = db()->query('SELECT * FROM dare_schools WHERE is_active = 1 ORDER BY name')->fetchAll();
$teachers = db()->query(
    'SELECT dare_teachers.*, dare_schools.name AS school_name
     FROM dare_teachers
     LEFT JOIN dare_schools ON dare_schools.id = dare_teachers.school_id
     ORDER BY dare_teachers.last_name, dare_teachers.first_name'
)->fetchAll();

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
];

page_header('DARE Teachers');
?>
<main class="shell">
    <section class="panel">
        <h1>Teachers</h1>
        <p>Add or update teacher names used for DARE classes.</p>
        <?php page_actions($actions); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <label>
                First name
                <input name="first_name" required>
            </label>
            <label>
                Last name
                <input name="last_name" required>
            </label>
            <label>
                School
                <select name="school_id">
                    <option value="">Select school</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= e((string) $school['id']) ?>"><?= e($school['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Email
                <input type="email" name="email">
            </label>
            <div class="actions span-2">
                <button type="submit">Add teacher</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Existing Teachers</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>School</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teachers as $teacher): ?>
                    <tr>
                        <td data-label="Name"><?= e($teacher['first_name'] . ' ' . $teacher['last_name']) ?></td>
                        <td data-label="School"><?= e($teacher['school_name'] ?: 'Not assigned') ?></td>
                        <td data-label="Email"><?= e($teacher['email'] ?: 'Not provided') ?></td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $teacher['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $teacher['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dare/teacher-edit.php?id=' . $teacher['id'])) ?>" title="Edit teacher" aria-label="Edit teacher">&#9998;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$teachers): ?>
                    <tr>
                        <td colspan="5">No teachers have been added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
