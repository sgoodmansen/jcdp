<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_manager('dare');

$officerId = (int) ($_GET['id'] ?? $_POST['officer_id'] ?? 0);

if ($officerId <= 0) {
    http_response_code(404);
    exit('DARE officer not found.');
}

$statement = db()->prepare('SELECT * FROM dare_officers WHERE id = :id');
$statement->execute(['id' => $officerId]);
$officer = $statement->fetch();

if (!$officer) {
    http_response_code(404);
    exit('DARE officer not found.');
}

$userStatement = db()->prepare(
    'SELECT users.id, users.first_name, users.last_name, users.email
     FROM users
     INNER JOIN user_departments ON user_departments.user_id = users.id
     INNER JOIN departments ON departments.id = user_departments.department_id
     LEFT JOIN dare_officers ON dare_officers.user_id = users.id
     WHERE departments.slug = "dare"
       AND users.is_active = 1
       AND (users.id = :current_user_id OR dare_officers.id IS NULL)
     ORDER BY users.last_name, users.first_name'
);
$userStatement->execute(['current_user_id' => (int) ($officer['user_id'] ?? 0)]);
$users = $userStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        flash('error', 'Select a portal user before saving the DARE officer.');
        redirect_to('departments/dare/officer-edit.php?id=' . $officerId);
    }

    $duplicateStatement = db()->prepare('SELECT id FROM dare_officers WHERE user_id = :user_id AND id <> :id LIMIT 1');
    $duplicateStatement->execute(['user_id' => $userId, 'id' => $officerId]);
    if ($duplicateStatement->fetchColumn()) {
        flash('error', 'That portal user is already linked to a DARE officer.');
        redirect_to('departments/dare/officer-edit.php?id=' . $officerId);
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
        flash('error', 'Select an active DARE portal user before saving the DARE officer.');
        redirect_to('departments/dare/officer-edit.php?id=' . $officerId);
    }

    $params = [
        'id' => $officerId,
        'user_id' => $userId,
        'first_name' => title_case_name($portalUser['first_name'] ?? ''),
        'last_name' => title_case_name($portalUser['last_name'] ?? ''),
        'email' => trim($portalUser['email'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

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

    audit_event('updated', 'dare_officer', (string) $officerId, [
        'name' => $params['first_name'] . ' ' . $params['last_name'],
        'is_active' => $params['is_active'],
    ]);

    flash('success', 'DARE officer updated.');
    redirect_to('departments/dare/lookups.php');
}

$actions = [
    ['label' => 'Schools & Officers', 'href' => url('departments/dare/lookups.php'), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
];

page_header('Edit DARE Officer');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit DARE Officer</h1>
        <p>Update the linked portal user or active status.</p>
        <?php page_actions($actions); ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <form class="form compact-form" method="post">
            <input type="hidden" name="officer_id" value="<?= e((string) $officer['id']) ?>">
            <label>
                Portal user
                <select name="user_id" required>
                    <option value="">Select portal user</option>
                    <?php foreach ($users as $portalUser): ?>
                        <option value="<?= e((string) $portalUser['id']) ?>" <?= (int) ($officer['user_id'] ?? 0) === (int) $portalUser['id'] ? 'selected' : '' ?>>
                            <?= e($portalUser['first_name'] . ' ' . $portalUser['last_name'] . ' - ' . $portalUser['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="meta">Name and email come from the selected portal user.</span>
            </label>
            <label class="check-label compact-check">
                <input type="checkbox" name="is_active" value="1" <?= (int) $officer['is_active'] === 1 ? 'checked' : '' ?>>
                Active officer
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/dare/lookups.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
