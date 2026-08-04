<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_manager('dare');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_certificate_settings') {
        $sheriffName = trim($_POST['sheriff_name'] ?? '');
        dare_save_setting('sheriff_name', $sheriffName);
        audit_event('updated', 'dare_setting', 'sheriff_name', ['sheriff_name' => $sheriffName]);
        flash('success', 'Certificate settings saved.');
        redirect_to('departments/dare/lookups.php');
    }

    if ($action === 'save_school') {
        $schoolId = (int) ($_POST['school_id'] ?? 0);
        $params = [
            'name' => title_case_company($_POST['name'] ?? ''),
            'address' => title_case_address($_POST['address'] ?? ''),
            'city' => title_case_name($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'zip_code' => trim($_POST['zip_code'] ?? ''),
            'principal_name' => preserve_name_case($_POST['principal_name'] ?? ''),
            'sheriff_name' => trim($_POST['sheriff_name'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($schoolId > 0) {
            $params['id'] = $schoolId;
            $statement = db()->prepare(
                'UPDATE dare_schools
                 SET name = :name,
                     address = :address,
                     city = :city,
                     state = :state,
                     zip_code = :zip_code,
                     principal_name = :principal_name,
                     sheriff_name = :sheriff_name,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($params);
            audit_event('updated', 'dare_school', (string) $schoolId, ['name' => $params['name']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO dare_schools (name, address, city, state, zip_code, principal_name, sheriff_name, is_active)
                 VALUES (:name, :address, :city, :state, :zip_code, :principal_name, :sheriff_name, 1)'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $schoolId = (int) db()->lastInsertId();
            audit_event('created', 'dare_school', (string) $schoolId, ['name' => $params['name']]);
        }

        flash('success', 'School saved.');
        redirect_to('departments/dare/lookups.php');
    }

    if ($action === 'save_officer') {
        $officerId = (int) ($_POST['officer_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            flash('error', 'Select a portal user before adding a DARE officer.');
            redirect_to('departments/dare/lookups.php');
        }

        $duplicateStatement = db()->prepare('SELECT id FROM dare_officers WHERE user_id = :user_id AND id <> :id LIMIT 1');
        $duplicateStatement->execute(['user_id' => $userId, 'id' => $officerId]);
        if ($duplicateStatement->fetchColumn()) {
            flash('error', 'That portal user is already linked to a DARE officer.');
            redirect_to('departments/dare/lookups.php');
        }

        $userStatement = db()->prepare(
            'SELECT users.first_name, users.last_name, users.email
             FROM users
             INNER JOIN user_departments ON user_departments.user_id = users.id
             INNER JOIN departments ON departments.id = user_departments.department_id
             WHERE users.id = :id
               AND users.is_active = 1
               AND departments.slug = "dare"'
        );
        $userStatement->execute(['id' => $userId]);
        $portalUser = $userStatement->fetch();

        if (!$portalUser) {
            flash('error', 'Select an active DARE portal user before adding a DARE officer.');
            redirect_to('departments/dare/lookups.php');
        }

        $params = [
            'user_id' => $userId,
            'first_name' => preserve_name_case($portalUser['first_name'] ?? ''),
            'last_name' => preserve_name_case($portalUser['last_name'] ?? ''),
            'email' => trim($portalUser['email'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($officerId > 0) {
            $params['id'] = $officerId;
            $statement = db()->prepare(
                'UPDATE dare_officers
                 SET user_id = :user_id,
                     first_name = :first_name,
                     last_name = :last_name,
                     email = :email,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($params);
            audit_event('updated', 'dare_officer', (string) $officerId, ['is_active' => $params['is_active']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO dare_officers (user_id, first_name, last_name, email, is_active)
                 VALUES (:user_id, :first_name, :last_name, :email, 1)'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $officerId = (int) db()->lastInsertId();
            audit_event('created', 'dare_officer', (string) $officerId);
        }

        flash('success', 'DARE officer saved.');
        redirect_to('departments/dare/lookups.php');
    }
}

$schools = db()->query('SELECT * FROM dare_schools ORDER BY name')->fetchAll();
$officers = db()->query(
    'SELECT dare_officers.*,
            users.first_name AS user_first_name,
            users.last_name AS user_last_name,
            users.email AS user_email
     FROM dare_officers
     LEFT JOIN users ON users.id = dare_officers.user_id
     ORDER BY dare_officers.last_name, dare_officers.first_name'
)->fetchAll();
$users = db()->query(
    'SELECT users.id, users.first_name, users.last_name, users.email
     FROM users
     INNER JOIN user_departments ON user_departments.user_id = users.id
     INNER JOIN departments ON departments.id = user_departments.department_id
     LEFT JOIN dare_officers ON dare_officers.user_id = users.id
     WHERE users.is_active = 1
       AND departments.slug = "dare"
       AND dare_officers.id IS NULL
     ORDER BY users.last_name, users.first_name'
)->fetchAll();
$sheriffName = dare_setting('sheriff_name');

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Classes', 'href' => url('departments/dare/classes.php')],
    ['label' => 'Teachers', 'href' => url('departments/dare/teachers.php')],
    ['label' => 'Lessons', 'href' => url('departments/dare/lessons.php')],
];

page_header('DARE Setup');
?>
<main class="shell">
    <section class="panel">
        <h1>Schools & Officers</h1>
        <p>Manage DARE schools and officers. This area is available to department supervisors and IT.</p>
        <?php dare_navigation('lookups'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Certificate Settings</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_certificate_settings">
            <label>
                <span class="label-with-help">
                    Sheriff name
                    <span class="info-tooltip" tabindex="0" aria-label="Default Sheriff name used on certificates unless a school has its own Sheriff name.">i</span>
                </span>
                <input name="sheriff_name" value="<?= e($sheriffName) ?>" placeholder="Name printed under Sheriff signature line">
            </label>
            <div class="actions">
                <button type="submit">Save settings</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Add School</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_school">
            <label>
                School name
                <input name="name" required>
            </label>
            <label>
                Address
                <input name="address">
            </label>
            <label>
                City
                <input name="city">
            </label>
            <label>
                State
                <select name="state">
                    <?php state_options('ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code">
            </label>
            <label>
                Principal name
                <input name="principal_name" placeholder="Name printed under Principal signature line">
            </label>
            <label>
                Sheriff name
                <input name="sheriff_name" placeholder="Leave blank to use default Sheriff">
            </label>
            <div class="actions">
                <button type="submit">Add school</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Add DARE Officer</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_officer">
            <label>
                Portal user
                <select name="user_id" required>
                    <option value="">Select portal user</option>
                    <?php foreach ($users as $portalUser): ?>
                        <option value="<?= e((string) $portalUser['id']) ?>"><?= e($portalUser['first_name'] . ' ' . $portalUser['last_name'] . ' - ' . $portalUser['email']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="meta">Name and email come from the selected portal user.</span>
            </label>
            <div class="actions">
                <button type="submit">Add officer</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Schools</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>School</th>
                    <th>Address</th>
                    <th>Principal</th>
                    <th>Sheriff</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $school): ?>
                    <tr>
                        <?php
                        $cityLine = trim(($school['city'] ?? '') . ' ' . ($school['state'] ?? '') . ' ' . ($school['zip_code'] ?? ''));
                        ?>
                        <td data-label="School"><?= e($school['name']) ?></td>
                        <td data-label="Address">
                            <?= e($school['address'] ?: 'Not provided') ?>
                            <?php if ($cityLine !== ''): ?>
                                <br><span class="meta"><?= e($cityLine) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Principal"><?= e($school['principal_name'] ?: 'Not provided') ?></td>
                        <td data-label="Sheriff"><?= e($school['sheriff_name'] ?: ($sheriffName ?: 'Not provided')) ?></td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $school['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $school['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dare/school-edit.php?id=' . $school['id'])) ?>" title="Edit school" aria-label="Edit school">&#9998;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$schools): ?>
                    <tr>
                        <td colspan="6">No schools have been added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>DARE Officers</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Portal User</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officers as $officer): ?>
                    <tr>
                        <td data-label="Name"><?= e($officer['first_name'] . ' ' . $officer['last_name']) ?></td>
                        <td data-label="Email"><?= e(($officer['user_email'] ?: $officer['email']) ?: 'Not provided') ?></td>
                        <td data-label="Portal User">
                            <?= $officer['user_id'] ? e($officer['user_first_name'] . ' ' . $officer['user_last_name']) : '<span class="meta">Not linked</span>' ?>
                        </td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $officer['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $officer['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="icon-link" href="<?= e(url('departments/dare/officer-edit.php?id=' . $officer['id'])) ?>" title="Edit DARE officer" aria-label="Edit DARE officer">&#9998;</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$officers): ?>
                    <tr>
                        <td colspan="5">No DARE officers have been added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
