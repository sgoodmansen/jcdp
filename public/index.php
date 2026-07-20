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
<main class="shell">
    <section class="grid">
        <article class="card">
            <h2>Department Modules</h2>
            <p>Each Access database can be rebuilt as its own protected area with separate forms and reports.</p>
        </article>
        <article class="card">
            <h2>Role-Based Access</h2>
            <p>Employees see only the department tools assigned to them, while IT can manage the full portal.</p>
        </article>
        <article class="card">
            <h2>Migration Ready</h2>
            <p>The site is structured so data can move from Access to MySQL one department at a time.</p>
        </article>
    </section>
</main>
<?php page_footer(); ?>
