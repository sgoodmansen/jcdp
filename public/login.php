<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    redirect_to('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_login($email, $password)) {
        redirect_to('dashboard.php');
    }

    flash('error', 'The email or password was not recognized.');
}

page_header('Sign in');
?>
<main class="shell">
    <section class="panel">
        <h1>Employee Sign In</h1>
        <p>Use your portal account to access assigned department tools.</p>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <form class="form" method="post">
            <label>
                Email
                <input type="email" name="email" autocomplete="email" required>
            </label>
            <label>
                Password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit">Sign in</button>
        </form>
    </section>
</main>
<?php page_footer(); ?>
