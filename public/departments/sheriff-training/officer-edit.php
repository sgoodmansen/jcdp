<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$officer = [
    'id' => 0,
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'rank_title' => '',
    'division' => '',
    'is_active' => 1,
    'notes' => '',
];

if ($id > 0) {
    $statement = db()->prepare('SELECT * FROM sheriff_training_officers WHERE id = :id');
    $statement->execute(['id' => $id]);
    $officer = $statement->fetch();
    if (!$officer) {
        http_response_code(404);
        page_header('Officer not found');
        echo '<main class="shell"><section class="panel"><h1>Officer not found</h1><p>The selected officer could not be found.</p></section></main>';
        page_footer();
        exit;
    }
}

$divisions = db()->query(
    'SELECT *
     FROM sheriff_training_divisions
     WHERE is_active = 1
        OR name = ' . db()->quote((string) $officer['division']) . '
     ORDER BY sort_order, name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'first_name' => preserve_name_case($_POST['first_name'] ?? ''),
        'last_name' => preserve_name_case($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? '') ?: null,
        'rank_title' => trim($_POST['rank_title'] ?? '') ?: null,
        'division' => trim($_POST['division'] ?? '') ?: null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'notes' => trim($_POST['notes'] ?? '') ?: null,
    ];

    if ($data['first_name'] === '' || $data['last_name'] === '') {
        flash('error', 'First and last name are required.');
        redirect_to('departments/sheriff-training/officer-edit.php' . ($id > 0 ? '?id=' . $id : ''));
    }

    if ($id > 0) {
        $statement = db()->prepare(
            'UPDATE sheriff_training_officers
             SET first_name = :first_name,
                 last_name = :last_name,
                 email = :email,
                 rank_title = :rank_title,
                 division = :division,
                 is_active = :is_active,
                 notes = :notes
             WHERE id = :id'
        );
        $data['id'] = $id;
        $statement->execute($data);
        audit_event('updated', 'sheriff_training_officer', (string) $id);
        flash('success', 'Officer updated.');
    } else {
        $statement = db()->prepare(
            'INSERT INTO sheriff_training_officers (first_name, last_name, email, rank_title, division, is_active, notes)
             VALUES (:first_name, :last_name, :email, :rank_title, :division, :is_active, :notes)'
        );
        $statement->execute($data);
        $id = (int) db()->lastInsertId();
        audit_event('created', 'sheriff_training_officer', (string) $id);
        flash('success', 'Officer added.');
    }

    redirect_to('departments/sheriff-training/officer-detail.php?id=' . $id);
}

page_header($id > 0 ? 'Edit Officer' : 'Add Officer');
?>
<main class="shell">
    <section class="panel">
        <h1><?= $id > 0 ? 'Edit Officer' : 'Add Officer' ?></h1>
        <p>Officer records are separate from portal login accounts.</p>
        <?php sheriff_training_navigation('officers'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$divisions): ?>
            <div class="notice warning">Add at least one division before assigning divisions to officers.</div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <label>
                First name
                <input name="first_name" value="<?= e($officer['first_name']) ?>" required>
            </label>
            <label>
                Last name
                <input name="last_name" value="<?= e($officer['last_name']) ?>" required>
            </label>
            <label>
                Rank / title
                <input name="rank_title" value="<?= e($officer['rank_title']) ?>">
            </label>
            <label>
                <span class="label-action-row">
                    <span>Division</span>
                    <a href="<?= e(url('departments/sheriff-training/divisions.php')) ?>">Manage divisions</a>
                </span>
                <select name="division">
                    <option value="">Select division</option>
                    <?php foreach ($divisions as $division): ?>
                        <option value="<?= e($division['name']) ?>" <?= $officer['division'] === $division['name'] ? 'selected' : '' ?>>
                            <?= e($division['name']) ?><?= (int) $division['is_active'] === 0 ? ' (Inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Email
                <input type="email" name="email" value="<?= e($officer['email']) ?>">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_active" value="1" <?= (int) $officer['is_active'] === 1 ? 'checked' : '' ?>>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>
                    Active officer
                    <small>Include this officer in new training requests.</small>
                </span>
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($officer['notes']) ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save officer</button>
                <a class="button secondary" href="<?= e(url('departments/sheriff-training/officers.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
