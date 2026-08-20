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

$startDepartmentSlug = trim((string) ($user['default_start_department_slug'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_start_department') {
    $postedSlug = trim((string) ($_POST['department_slug'] ?? ''));
    $checked = isset($_POST['start_here']);
    $newStartSlug = null;

    if ($checked && $postedSlug !== '') {
        if (!can_start_in_department($postedSlug)) {
            flash('error', 'That module is not available as a startup page for your account.');
            redirect_to('dashboard.php');
        }
        $newStartSlug = $postedSlug;
    }

    $statement = db()->prepare('UPDATE users SET default_start_department_slug = :slug WHERE id = :id');
    $statement->execute([
        'slug' => $newStartSlug,
        'id' => (int) $user['id'],
    ]);

    flash('success', $newStartSlug === null ? 'Dashboard startup restored.' : 'Startup module saved.');
    redirect_to('dashboard.php');
}

function dashboard_start_preference_control(string $slug, string $currentStartSlug): void
{
    if (!can_start_in_department($slug)) {
        return;
    }

    ?>
    <form class="dashboard-start-form" method="post">
        <input type="hidden" name="action" value="save_start_department">
        <input type="hidden" name="department_slug" value="<?= e($slug) ?>">
        <label class="check-option">
            <input type="checkbox" name="start_here" value="1" <?= $currentStartSlug === $slug ? 'checked' : '' ?> onchange="this.form.submit()">
            Start here when I sign in
        </label>
    </form>
    <?php
}

$hasDmvAccess = can_access_department('dmv');
$hasDareAccess = can_access_department('dare');
$hasElectionAccess = can_access_department('election');
$hasK9Access = can_access_department('k9');
$hasSheriffTrainingAccess = can_manage_department('sheriff-training');
$dmvActions = [];
$dareActions = [];
$electionActions = [];
$k9Actions = [];
$sheriffTrainingActions = [];

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

if ($hasK9Access) {
    $k9Actions = [
        ['label' => 'K-9 Home', 'href' => url('departments/k9/index.php'), 'primary' => true],
        ['label' => 'Add training', 'href' => url('departments/k9/activity-edit.php')],
        ['label' => 'Add deployment', 'href' => url('departments/k9/deployment-edit.php')],
        ['label' => 'Activity log', 'href' => url('departments/k9/activity.php')],
    ];

    if (can_manage_department('k9')) {
        $k9Actions[] = ['label' => 'Teams', 'href' => url('departments/k9/teams.php')];
        $k9Actions[] = ['label' => 'Setup', 'href' => url('departments/k9/setup.php')];
    }
}

if ($hasSheriffTrainingAccess) {
    $sheriffTrainingActions = [
        ['label' => 'Sheriff Training Home', 'href' => url('departments/sheriff-training/index.php'), 'primary' => true],
        ['label' => 'New request', 'href' => url('departments/sheriff-training/request-edit.php')],
        ['label' => 'Requests', 'href' => url('departments/sheriff-training/requests.php')],
        ['label' => 'Officers', 'href' => url('departments/sheriff-training/officers.php')],
        ['label' => 'Divisions', 'href' => url('departments/sheriff-training/divisions.php')],
        ['label' => 'Fiscal budgets', 'href' => url('departments/sheriff-training/budgets.php')],
        ['label' => 'Reports', 'href' => url('departments/sheriff-training/reports.php')],
    ];
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
                <div class="section-heading-row">
                    <div>
                        <h1>DMV</h1>
                        <p><?= e($department['description']) ?></p>
                    </div>
                    <?php dashboard_start_preference_control('dmv', $startDepartmentSlug); ?>
                </div>
                <?php page_actions($dmvActions); ?>
            </section>
        <?php elseif ($department['slug'] === 'dare'): ?>
            <section class="panel" style="margin-top: 18px;">
                <div class="section-heading-row">
                    <div>
                        <h1>DARE</h1>
                        <p><?= e($department['description']) ?></p>
                    </div>
                    <?php dashboard_start_preference_control('dare', $startDepartmentSlug); ?>
                </div>
                <?php page_actions($dareActions); ?>
            </section>
        <?php elseif ($department['slug'] === 'election'): ?>
            <section class="panel" style="margin-top: 18px;">
                <div class="section-heading-row">
                    <div>
                        <h1>Election Readiness</h1>
                        <p><?= e($department['description']) ?></p>
                    </div>
                    <?php dashboard_start_preference_control('election', $startDepartmentSlug); ?>
                </div>
                <?php page_actions($electionActions); ?>
            </section>
        <?php elseif ($department['slug'] === 'k9'): ?>
            <section class="panel" style="margin-top: 18px;">
                <div class="section-heading-row">
                    <div>
                        <h1>K-9 Activity & Records</h1>
                        <p><?= e($department['description']) ?></p>
                    </div>
                    <?php dashboard_start_preference_control('k9', $startDepartmentSlug); ?>
                </div>
                <?php page_actions($k9Actions); ?>
            </section>
        <?php elseif ($department['slug'] === 'sheriff-training'): ?>
            <section class="panel" style="margin-top: 18px;">
                <div class="section-heading-row">
                    <div>
                        <h1>Sheriff Training</h1>
                        <p><?= e($department['description']) ?></p>
                    </div>
                    <?php if ($hasSheriffTrainingAccess): ?>
                        <?php dashboard_start_preference_control('sheriff-training', $startDepartmentSlug); ?>
                    <?php endif; ?>
                </div>
                <?php if ($hasSheriffTrainingAccess): ?>
                    <?php page_actions($sheriffTrainingActions); ?>
                <?php else: ?>
                    <div class="notice error">Sheriff Training is currently limited to department supervisors.</div>
                <?php endif; ?>
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
                <a class="button secondary" href="<?= e(url('admin/setup-election-module.php')) ?>">Setup Election Readiness</a>
                <a class="button secondary" href="<?= e(url('admin/setup-k9-module.php')) ?>">Setup K-9</a>
                <a class="button secondary" href="<?= e(url('admin/setup-sheriff-training-module.php')) ?>">Setup Sheriff Training</a>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
