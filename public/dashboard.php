<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$user = current_user();

$roleLabel = friendly_user_title($user);

if ($user['role'] === 'system_admin') {
    $departments = db()->query('SELECT * FROM departments ORDER BY name')->fetchAll();
} else {
    $departments = departments_for_user((int) $user['id']);
}

$hasDmvAccess = can_access_department('dmv');
$hasDareAccess = can_access_department('dare');
$hasElectionAccess = can_access_department('election');
$dmvActions = [];
$dareActions = [];
$electionActions = [];
$recentDmvRequests = [];
$nextDareLessons = [];

if ($hasDmvAccess) {
    $dmvActions = [
        ['label' => 'DMV Home', 'href' => url('departments/dmv/index.php'), 'primary' => true],
        ['label' => 'New title request', 'href' => url('departments/dmv/title-request-create.php')],
        ['label' => 'Title requests', 'href' => url('departments/dmv/title-requests.php')],
        ['label' => 'Lienholders', 'href' => url('departments/dmv/lienholders.php')],
        ['label' => 'Reports', 'href' => url('departments/dmv/report.php')],
    ];

    if (can_manage_department('dmv')) {
        $dmvActions[] = ['label' => 'Vehicle lookups', 'href' => url('departments/dmv/vehicle-lookups.php')];
    }

    $recentStatement = db()->prepare(
        'SELECT
            dmv_title_requests.id,
            dmv_title_requests.request_date,
            dmv_title_requests.registrant_name,
            dmv_title_requests.registrant_name_2,
            dmv_title_requests.status,
            dmv_lienholders.company_name
         FROM dmv_title_requests
         INNER JOIN dmv_lienholders ON dmv_lienholders.id = dmv_title_requests.lienholder_id
         WHERE dmv_title_requests.created_by = :user_id
         ORDER BY dmv_title_requests.created_at DESC
         LIMIT 5'
    );
    $recentStatement->execute(['user_id' => $user['id']]);
    $recentDmvRequests = $recentStatement->fetchAll();
}

if ($hasDareAccess) {
    $dareActions = [
        ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
        ['label' => 'New class', 'href' => url('departments/dare/class-create.php')],
        ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
        ['label' => 'Student search', 'href' => url('departments/dare/students.php')],
        ['label' => 'Teachers', 'href' => url('departments/dare/teachers.php')],
    ];

    if (can_manage_department('dare')) {
        $dareActions[] = ['label' => 'Schools & officers', 'href' => url('departments/dare/lookups.php')];
        $dareActions[] = ['label' => 'Lessons', 'href' => url('departments/dare/lessons.php')];
    }

    $nextDareLessons = dare_next_lessons_for_user($user, 5);
}

if ($hasElectionAccess) {
    $electionActions = [
        ['label' => 'Election Home', 'href' => url('departments/election/index.php'), 'primary' => true],
        ['label' => 'Training classes', 'href' => url('departments/election/classes.php')],
        ['label' => 'Workers', 'href' => url('departments/election/workers.php')],
    ];

    if (can_access_department('election')) {
        $electionActions[] = ['label' => 'New class', 'href' => url('departments/election/class-edit.php')];
        $electionActions[] = ['label' => 'Setup', 'href' => url('departments/election/setup.php')];
    }
}

page_header('Dashboard');
?>
<main class="shell">
    <section class="panel">
        <h1>Welcome, <?= e($user['first_name']) ?></h1>
        <p>
            <?= e($roleLabel) ?>
        </p>
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <?php if (!$departments): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>No Departments Assigned</h1>
            <p>Ask an administrator to assign your account to a department.</p>
        </section>
    <?php endif; ?>

    <?php foreach ($departments as $department): ?>
        <?php if ($department['slug'] === 'dmv'): ?>
            <section class="panel" style="margin-top: 18px;">
                <h1>DMV</h1>
                <p><?= e($department['description']) ?></p>
                <?php page_actions($dmvActions); ?>

                <h2 style="margin-top: 24px;">My Recent Title Requests</h2>
                <?php if (!$recentDmvRequests): ?>
                    <p>No DMV title requests have been entered by you yet.</p>
                <?php else: ?>
                    <table class="table mobile-card-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Registrant</th>
                                <th>Lienholder</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDmvRequests as $request): ?>
                                <tr>
                                    <td data-label="Date"><?= e($request['request_date']) ?></td>
                                    <td data-label="Registrant">
                                        <?= e($request['registrant_name']) ?>
                                        <?php if (!empty($request['registrant_name_2'])): ?>
                                            <br><span class="meta"><?= e($request['registrant_name_2']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Lienholder"><?= e($request['company_name']) ?></td>
                                    <td data-label="Status"><?= e(ucfirst($request['status'])) ?></td>
                                    <td data-label="Actions">
                                        <div class="table-actions">
                                            <a class="icon-link" href="<?= e(url('departments/dmv/title-request-detail.php?id=' . $request['id'])) ?>" title="View details" aria-label="View title request details">&#9636;</a>
                                            <a class="icon-link" href="<?= e(url('departments/dmv/title-request-edit.php?id=' . $request['id'])) ?>" title="Edit title request" aria-label="Edit title request">&#9998;</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php elseif ($department['slug'] === 'dare'): ?>
            <section class="panel" style="margin-top: 18px;">
                <h1>DARE</h1>
                <p><?= e($department['description']) ?></p>
                <?php page_actions($dareActions); ?>

                <?php if ($nextDareLessons): ?>
                    <h2 style="margin-top: 24px;">Next Lessons</h2>
                    <table class="table mobile-card-table">
                        <thead>
                            <tr>
                                <th>School</th>
                                <th>School Year</th>
                                <th>Semester</th>
                                <th>Period</th>
                                <th>Teacher</th>
                                <th>Next Lesson</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nextDareLessons as $lesson): ?>
                                <tr>
                                    <td data-label="School"><?= e($lesson['school_name']) ?></td>
                                    <td data-label="School Year"><?= e($lesson['school_year'] ?: 'Not set') ?></td>
                                    <td data-label="Semester"><?= e($lesson['semester'] ?: 'Not set') ?></td>
                                    <td data-label="Period"><?= e($lesson['period'] ?: 'Not set') ?></td>
                                    <td data-label="Teacher"><?= e(trim(($lesson['teacher_first_name'] ?? '') . ' ' . ($lesson['teacher_last_name'] ?? '')) ?: 'Not assigned') ?></td>
                                    <td data-label="Next Lesson"><?= e($lesson['lesson_title']) ?></td>
                                    <td data-label="Actions">
                                        <div class="table-actions">
                                            <form method="post" action="<?= e(url('departments/dare/lesson-complete.php')) ?>">
                                                <input type="hidden" name="class_lesson_id" value="<?= e((string) $lesson['class_lesson_id']) ?>">
                                                <input type="hidden" name="return_to" value="main_dashboard">
                                                <button type="submit" class="secondary compact-button">Mark taught</button>
                                            </form>
                                            <a class="icon-link" href="<?= e(url('departments/dare/class-detail.php?id=' . $lesson['class_id'])) ?>" title="View class" aria-label="View DARE class">&#9636;</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php elseif ($department['slug'] === 'election'): ?>
            <section class="panel" style="margin-top: 18px;">
                <h1>Election Training</h1>
                <p><?= e($department['description']) ?></p>
                <?php page_actions($electionActions); ?>
            </section>
        <?php else: ?>
            <section class="panel" style="margin-top: 18px;">
                <h1><?= e($department['name']) ?></h1>
                <p><?= e($department['description']) ?></p>
                <div class="actions">
                    <span class="badge">Module coming soon</span>
                </div>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($user['role'] === 'system_admin'): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>IT Administration</h1>
            <div class="actions">
                <a class="button" href="<?= e(url('admin/users.php')) ?>">Manage users</a>
                <a class="button secondary" href="<?= e(url('admin/audit-log.php')) ?>">Audit log</a>
                <a class="button secondary" href="<?= e(url('admin/setup-election-module.php')) ?>">Setup Election Module</a>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
