<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$user = current_user();
$roleLabel = friendly_user_title($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $statement = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $statement->execute(['id' => $user['id']]);
    $passwordHash = (string) $statement->fetchColumn();

    if (!password_verify($currentPassword, $passwordHash)) {
        flash('error', 'Current password is not correct.');
        redirect_to('account.php');
    }

    if (strlen($newPassword) < 10) {
        flash('error', 'New password must be at least 10 characters.');
        redirect_to('account.php');
    }

    if ($newPassword !== $confirmPassword) {
        flash('error', 'New password and confirmation do not match.');
        redirect_to('account.php');
    }

    $statement = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $statement->execute([
        'id' => $user['id'],
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    ]);

    audit_event('password_changed', 'user', (string) $user['id'], [
        'email' => $user['email'],
        'self_service' => true,
    ]);

    flash('success', 'Password changed.');
    redirect_to('account.php');
}

page_header('My Account');
?>
<main class="shell">
    <section class="panel">
        <h1>My Account</h1>
        <p>Review your account information and change your password.</p>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="actions" style="margin-bottom: 18px;">
            <a class="button secondary" href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
        </div>
    </section>

    <section class="detail-grid" style="margin-top: 18px;">
        <article class="panel detail-panel">
            <h2>Account Details</h2>
            <dl class="detail-list">
                <dt>Name</dt>
                <dd><?= e($user['first_name'] . ' ' . $user['last_name']) ?></dd>
                <dt>Email</dt>
                <dd><?= e($user['email']) ?></dd>
                <dt>Department</dt>
                <dd><?= e($user['department_names'] ?: 'None') ?></dd>
                <dt>Title</dt>
                <dd><?= e($roleLabel) ?></dd>
            </dl>
        </article>

        <article class="panel detail-panel">
            <h2>Change Password</h2>
            <form class="form" method="post">
                <label>
                    Current password
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label>
                    New password
                    <input type="password" name="new_password" autocomplete="new-password" minlength="10" required>
                </label>
                <label>
                    Confirm new password
                    <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
                </label>
                <button type="submit">Change password</button>
            </form>
        </article>
    </section>
</main>
<?php page_footer(); ?>
