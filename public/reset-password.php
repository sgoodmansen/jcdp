<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    redirect_to('dashboard.php');
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$reset = $token !== '' ? find_valid_password_reset($token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$reset) {
        flash('error', 'This password reset link is invalid or has expired.');
        redirect_to('forgot-password.php');
    }

    if (strlen($newPassword) < 10) {
        flash('error', 'New password must be at least 10 characters.');
        redirect_to('reset-password.php?token=' . urlencode($token));
    }

    if ($newPassword !== $confirmPassword) {
        flash('error', 'New password and confirmation do not match.');
        redirect_to('reset-password.php?token=' . urlencode($token));
    }

    if (!complete_password_reset($token, $newPassword)) {
        flash('error', 'This password reset link is invalid or has expired.');
        redirect_to('forgot-password.php');
    }

    flash('success', 'Password reset. You can now sign in with your new password.');
    redirect_to('login.php');
}

page_header('Choose new password');
?>
<main class="shell">
    <section class="panel login-panel">
        <h1>Choose New Password</h1>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$reset): ?>
            <p>This password reset link is invalid or has expired.</p>
            <div class="actions">
                <a class="button" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a>
                <a class="button secondary" href="<?= e(url('login.php')) ?>">Back to sign in</a>
            </div>
        <?php else: ?>
            <p>Enter a new password for <?= e($reset['email']) ?>.</p>
            <form class="form" method="post" autocomplete="on">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>
                    New password
                    <input type="password" name="new_password" autocomplete="new-password" minlength="10" required>
                </label>
                <label>
                    Confirm new password
                    <input type="password" name="confirm_password" autocomplete="new-password" minlength="10" required>
                </label>
                <button type="submit">Reset password</button>
                <a class="button secondary" href="<?= e(url('login.php')) ?>">Back to sign in</a>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
