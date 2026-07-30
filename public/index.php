<?php
require_once __DIR__ . '/../app/bootstrap.php';

if (is_logged_in()) {
    redirect_to('dashboard.php');
}

page_header('Welcome');
?>
<section class="hero">
    <div class="hero-inner">
        <h1>Jefferson County Data Portal</h1>
        <p>A single employee portal for department data entry, record lookup, reports, and system administration.</p>
        <a class="button" href="<?= e(url('login.php')) ?>">Employee sign in</a>
    </div>
</section>
<?php page_footer(); ?>
