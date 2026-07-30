<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    redirect_to('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $testLink = null;

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $testLink = create_password_reset_for_email($email);
    }

    flash('success', 'If an active account exists for that email, password reset instructions have been sent.');

    if ($testLink) {
        flash('test_link', $testLink);
    }

    redirect_to('forgot-password.php');
}

page_header('Reset password');
?>
<main class="shell">
    <section class="panel login-panel">
        <h1>Reset Password</h1>
        <p>Enter your portal email address and we will send instructions for choosing a new password.</p>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($testLink = flash('test_link')): ?>
            <div class="notice">
                WAMP test link:<br>
                <a href="<?= e($testLink) ?>"><?= e($testLink) ?></a>
            </div>
        <?php endif; ?>

        <form class="form" method="post" autocomplete="on">
            <label>
                Email
                <input type="email" name="email" autocomplete="username" required>
            </label>
            <button type="submit">Send reset link</button>
            <a class="button secondary" href="<?= e(url('login.php')) ?>">Back to sign in</a>
        </form>
    </section>
</main>
<?php page_footer(); ?>
