<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();
election_require_assignment_setup();

if (!current_election_actor_can_manage_workers()) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to view this guide.</p></section></main>';
    page_footer();
    exit;
}

$worker = current_election_worker();
$assignment = current_election_assignment();

if ($worker && !$assignment) {
    redirect_to('departments/election/select-assignment.php');
}

page_header('Chief Judge How To');
?>
<main class="shell">
    <section class="panel">
        <h1>Chief Judge How To</h1>
        <?php if ($worker && $assignment): ?>
            <p><?= e(election_person_name($worker)) ?> - <?= e($assignment['position_name']) ?>, <?= e($assignment['precinct_name']) ?></p>
        <?php else: ?>
            <p>A practical overview for Chief Judges managing precinct staffing and worker follow-up.</p>
        <?php endif; ?>
        <?php election_navigation('how-to-chief-judge'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Main Workflow</h1>
        <ol class="how-to-steps">
            <li><strong>Start with Needs Attention.</strong> Review the items for your precinct so you know what needs work first.</li>
            <li><strong>Open Precinct Staffing.</strong> Select workers for each required position. A worker already assigned in the election will not appear as an available choice for another regular position.</li>
            <li><strong>Add extra workers only when needed.</strong> If the precinct needs more than one person for a non-Chief Judge position, use the Add button for that position.</li>
            <li><strong>Choose the Assistant Chief Judge.</strong> Pick someone already assigned to your precinct. This is an extra responsibility and cannot be assigned to the Chief Judge.</li>
            <li><strong>Check training status.</strong> The staffing page shows whether each assigned worker is signed up for training or has completed training.</li>
            <li><strong>Use the Election Day checklist.</strong> Review delivery and pickup details, then check off items as they are completed.</li>
            <li><strong>Complete the debrief after election day.</strong> Use the Chief Judge Debrief page to send feedback to the election supervisors.</li>
        </ol>
        <div class="actions">
            <a class="button" href="<?= e(url('departments/election/needs-attention.php')) ?>">Needs Attention</a>
            <a class="button secondary" href="<?= e(url('departments/election/staffing.php')) ?>">Precinct Staffing</a>
            <a class="button secondary" href="<?= e(url('departments/election/election-day-checklist.php')) ?>">Precinct Checklist</a>
            <a class="button secondary" href="<?= e(url('departments/election/chief-judge-debrief.php')) ?>">Chief Judge Debrief</a>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Helpful Tools</h1>
        <div class="grid how-to-grid">
            <article class="card how-to-card">
                <h2>Address Book</h2>
                <p>Look up available contacts, add contact information, and update a person when something changes.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/workers.php')) ?>">Address Book</a>
            </article>
            <article class="card how-to-card">
                <h2>Reuse Past Workers</h2>
                <p>Bring forward people from a previous election when your precinct is likely to use the same workers again.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/reuse-workers.php')) ?>">Reuse Past Workers</a>
            </article>
            <article class="card how-to-card">
                <h2>Contact Sheet</h2>
                <p>Print or review names, phone numbers, and email addresses for the workers assigned to your precinct.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/precinct-contact-sheet.php')) ?>">Precinct Contact Sheet</a>
            </article>
            <article class="card how-to-card">
                <h2>Precinct Checklist</h2>
                <p>Review equipment delivery and pickup information, complete checklist items online, or print the checklist if you prefer a paper copy.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/election-day-checklist.php')) ?>">Precinct Checklist</a>
            </article>
            <article class="card how-to-card">
                <h2>Chief Judge Debrief</h2>
                <p>After election day, answer the debrief questions for your precinct. You can save a draft and come back, then submit when the answers are ready.</p>
                <a class="button secondary compact-button" href="<?= e(url('departments/election/chief-judge-debrief.php')) ?>">Chief Judge Debrief</a>
            </article>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Election Day Checklist</h1>
        <ol class="how-to-steps">
            <li><strong>Open the checklist before election day.</strong> Confirm the equipment delivery and pickup information for your precinct.</li>
            <li><strong>Use the checklist while preparing.</strong> Check off items as they are completed. Supervisors can also check off items when they help with the task.</li>
            <li><strong>Print when needed.</strong> If you prefer paper, use the print version and keep it with your election day materials.</li>
        </ol>
        <p class="muted">The checklist is created by the election supervisors. If something is missing or unclear, contact the supervisor so they can update the template or precinct details.</p>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>After Election Day</h1>
        <ol class="how-to-steps">
            <li><strong>Complete the debrief.</strong> Open Chief Judge Debrief and answer the questions for your precinct.</li>
            <li><strong>Save a draft if needed.</strong> Drafts are useful if you need to collect details before submitting.</li>
            <li><strong>Submit when finished.</strong> Once submitted, supervisors can review the response and follow up on any concerns.</li>
        </ol>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>How to Sign Someone Up for Training</h1>
        <ol class="how-to-steps">
            <li><strong>Open Training Classes.</strong> Choose the class that fits the worker's position or learning need.</li>
            <li><strong>Open the class.</strong> Use the view button to open the class detail page.</li>
            <li><strong>Use Sign Up Worker.</strong> Select a worker from your precinct and choose Sign up worker.</li>
            <li><strong>Confirm the roster.</strong> The worker should be listed on the class Attendance Roster.</li>
        </ol>
        <p class="muted">Chief Judges can sign up workers from their precinct. The dropdown will not show workers who are unavailable, archived, outside your precinct, or already signed up for another class unless they are Chief Judge or Assistant Chief Judge.</p>
        <div class="actions">
            <a class="button" href="<?= e(url('departments/election/classes.php')) ?>">Training Classes</a>
        </div>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>When Something Looks Wrong</h1>
        <ul class="how-to-checklist">
            <li>If the wrong person is assigned, clear that position and save the change.</li>
            <li>If a worker is missing from a dropdown, they may already be assigned somewhere else, archived, or marked unavailable.</li>
            <li>If a worker needs an address, phone, email, or note updated, open that person from the Address Book.</li>
            <li>If a worker cannot serve, mark that contact as unavailable and choose the reason when known.</li>
            <li>If you cannot complete a change, contact the election supervisor so they can review permissions, contact status, or duplicate records.</li>
        </ul>
    </section>
</main>
<?php page_footer(); ?>
