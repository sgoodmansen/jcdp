<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();

page_header('Supervisor How To');
?>
<main class="shell">
    <section class="panel">
        <h1>Supervisor How To</h1>
        <p>A practical overview for preparing an election, filling precinct staffing, tracking training, and wrapping up after election day.</p>
        <?php election_navigation('how-to-supervisor'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Before Staffing Begins</h1>
        <div class="grid how-to-grid">
            <article class="card how-to-card">
                <h2>1. Confirm setup</h2>
                <p>Check the open election period, precincts, positions, and Chief Judge permissions before assignments begin.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/setup.php')) ?>">Open Setup</a>
            </article>
            <article class="card how-to-card">
                <h2>2. Prepare contacts</h2>
                <p>Use the address book for individual contacts, import a spreadsheet when you have many names, and merge duplicates before staffing.</p>
                <div class="actions">
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/workers.php')) ?>">Address Book</a>
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/import-workers.php')) ?>">Import Contacts</a>
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/merge-workers.php')) ?>">Merge Contacts</a>
                </div>
            </article>
            <article class="card how-to-card">
                <h2>3. Reuse known workers</h2>
                <p>Copy assignments from a previous election when the same people are likely to serve again.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/reuse-workers.php')) ?>">Reuse Past Workers</a>
            </article>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Staffing the Election</h1>
        <ol class="how-to-steps">
            <li><strong>Fill each precinct.</strong> Use Precinct Staffing to choose one person for each required position. Add extra workers only when a precinct needs more than the base staffing model.</li>
            <li><strong>Assign Assistant Chief Judge carefully.</strong> The Assistant Chief Judge is an extra responsibility for someone already assigned in that precinct, but not the Chief Judge.</li>
            <li><strong>Watch the dashboard.</strong> Use Staffing Progress to see missing positions, missing chiefs, missing assistant chiefs, and extra workers.</li>
            <li><strong>Print working sheets.</strong> Use the Staffing Sheet for assignment review and the Precinct Contact Sheet when precinct workers need contact details.</li>
        </ol>
        <div class="actions">
            <a class="button" href="<?= e(url('departments/election/staffing.php')) ?>">Precinct Staffing</a>
            <a class="button secondary" href="<?= e(url('departments/election/staffing-progress.php')) ?>">Staffing Progress</a>
            <a class="button secondary" href="<?= e(url('departments/election/staffing-sheet.php')) ?>">Staffing Sheet</a>
            <a class="button secondary" href="<?= e(url('departments/election/precinct-contact-sheet.php')) ?>">Precinct Contact Sheet</a>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Training and Communication</h1>
        <div class="grid how-to-grid">
            <article class="card how-to-card">
                <h2>Training classes</h2>
                <p>Create classes, sign workers up, and mark attendance after class. Precinct Staffing shows whether each assigned worker has completed or signed up for training.</p>
                <div class="actions">
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/classes.php')) ?>">Training Classes</a>
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/class-edit.php')) ?>">New Class</a>
                </div>
            </article>
            <article class="card how-to-card">
                <h2>Email access links</h2>
                <p>Review the email wording first, then send access links so workers can sign up for training and update reminder preferences.</p>
                <div class="actions">
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/email-template.php')) ?>">Email Template</a>
                    <a class="button secondary compact-button" href="<?= e(url('departments/election/bulk-email.php')) ?>">Bulk Email</a>
                </div>
            </article>
            <article class="card how-to-card">
                <h2>Follow-up notes</h2>
                <p>Use the worker edit page to record calls, follow-up notes, unavailable reasons, and other contact history.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/workers.php')) ?>">Find Contact</a>
            </article>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>How to Sign Someone Up for Training</h1>
        <ol class="how-to-steps">
            <li><strong>Open Training Classes.</strong> Find the class the worker should attend.</li>
            <li><strong>Open the class.</strong> Use the view button on the class row to open the class detail page.</li>
            <li><strong>Use Sign Up Worker.</strong> Choose the worker from the dropdown and select Sign up worker.</li>
            <li><strong>Check the roster.</strong> The worker should appear on the Attendance Roster for that class.</li>
        </ol>
        <p class="muted">The dropdown only shows eligible active workers for that election who are not already signed up for another class in the same election period. Chief Judge and Assistant Chief Judge may join any class as optional training.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('departments/election/classes.php')) ?>">Training Classes</a>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Daily Review</h1>
        <p>Use Needs Attention as the main checklist. The goal is not always to make every number zero, but every item should either be fixed or intentionally understood.</p>
        <ul class="how-to-checklist">
            <li>Staffing gaps should be filled before election day.</li>
            <li>Training follow-ups should be reviewed until workers are signed up or complete.</li>
            <li>Missing contact information should be corrected when possible.</li>
            <li>Status conflicts and duplicate contacts should be cleaned up before final reports.</li>
            <li>Closing an election period should happen after election day, once staffing and attendance records have been reviewed.</li>
        </ul>
        <div class="actions">
            <a class="button" href="<?= e(url('departments/election/needs-attention.php')) ?>">Needs Attention</a>
        </div>
    </section>
</main>
<?php page_footer(); ?>
