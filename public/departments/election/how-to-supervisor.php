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

    <section class="panel guide-panel" style="margin-top: 18px;" data-guide-tabs>
        <div class="guide-topic-list" role="tablist" aria-label="Supervisor guide topics">
            <button type="button" class="guide-topic-button active" data-guide-target="setup" role="tab" aria-selected="true">Before Staffing</button>
            <button type="button" class="guide-topic-button" data-guide-target="staffing" role="tab" aria-selected="false">Staffing</button>
            <button type="button" class="guide-topic-button" data-guide-target="training" role="tab" aria-selected="false">Training</button>
            <button type="button" class="guide-topic-button" data-guide-target="election-day" role="tab" aria-selected="false">Election Day</button>
            <button type="button" class="guide-topic-button" data-guide-target="payroll" role="tab" aria-selected="false">Payroll</button>
            <button type="button" class="guide-topic-button" data-guide-target="signup" role="tab" aria-selected="false">Sign Up Training</button>
            <button type="button" class="guide-topic-button" data-guide-target="remove-training" role="tab" aria-selected="false">Remove Training</button>
            <button type="button" class="guide-topic-button" data-guide-target="review" role="tab" aria-selected="false">Daily Review</button>
        </div>

        <div class="guide-topic-content">
            <section class="guide-topic-pane active" data-guide-pane="setup" role="tabpanel">
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
                    <article class="card how-to-card">
                        <h2>4. Prepare Election Day tools</h2>
                        <p>Create the precinct checklist, enter equipment delivery and pickup details, and set up the debrief questions Chief Judges will answer after election day.</p>
                        <div class="actions">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/election-day-setup.php')) ?>">Election Day Setup</a>
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/debrief-setup.php')) ?>">Debrief Questions</a>
                        </div>
                    </article>
                </div>
            </section>

            <section class="guide-topic-pane" data-guide-pane="staffing" role="tabpanel" hidden>
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

            <section class="guide-topic-pane" data-guide-pane="training" role="tabpanel" hidden>
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
                        <h2>Training signups</h2>
                        <p>Use Training Signups to review each assigned worker, their position, and the classes they are scheduled for or have completed.</p>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/training-signups.php')) ?>">Training Signups</a>
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

            <section class="guide-topic-pane" data-guide-pane="election-day" role="tabpanel" hidden>
                <h1>Election Day Tools</h1>
                <div class="grid how-to-grid">
                    <article class="card how-to-card">
                        <h2>Precinct Checklist</h2>
                        <p>Use Election Day Setup to build the checklist for the election period. Then use the Checklist page to review each precinct, enter equipment delivery and pickup times, and check off completed items.</p>
                        <div class="actions">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/election-day-setup.php')) ?>">Checklist Setup</a>
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/election-day-checklist.php')) ?>">Precinct Checklist</a>
                        </div>
                    </article>
                    <article class="card how-to-card">
                        <h2>Precinct Notes</h2>
                        <p>Record supervisor-only notes about a precinct, including incidents, reminders, and suggestions for the next election. Notes can be added quickly without opening a precinct first.</p>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/precinct-notes.php')) ?>">Precinct Notes</a>
                    </article>
                    <article class="card how-to-card">
                        <h2>Chief Judge Debrief</h2>
                        <p>Create debrief questions before election day. After the election, review each precinct response and follow up on anything that needs attention.</p>
                        <div class="actions">
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/debrief-setup.php')) ?>">Debrief Questions</a>
                            <a class="button secondary compact-button" href="<?= e(url('departments/election/chief-judge-debrief.php')) ?>">Debrief Responses</a>
                        </div>
                    </article>
                    <article class="card how-to-card">
                        <h2>Chief Feedback</h2>
                        <p>Send internal feedback to the assigned Chief Judge by category. Messages stay with the election period and can be edited if more details are learned.</p>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/chief-feedback.php')) ?>">Chief Feedback</a>
                    </article>
                </div>
            </section>

            <section class="guide-topic-pane" data-guide-pane="payroll" role="tabpanel" hidden>
                <h1>Payroll Workflow</h1>
                <div class="grid how-to-grid">
                    <article class="card how-to-card">
                        <h2>1. Confirm payroll setup</h2>
                        <p>Open Payroll Setup for the election period and review position rates, training pay, training cap, mileage rate, and minimum mileage settings before final payroll review.</p>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/payroll-setup.php')) ?>">Payroll Setup</a>
                    </article>
                    <article class="card how-to-card">
                        <h2>2. Review election day pay</h2>
                        <p>Use Election Day Pay to confirm who worked, who did not work, and whether anyone should be paid as Chief Judge. Add payroll notes when a record needs explanation.</p>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/payroll-election-day.php')) ?>">Election Day Pay</a>
                    </article>
                    <article class="card how-to-card">
                        <h2>3. Review training and mileage</h2>
                        <p>Training Pay uses completed training records and the payroll setup rates. Enter round-trip mileage for training when mileage should be paid.</p>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/payroll-training.php')) ?>">Training Pay</a>
                    </article>
                    <article class="card how-to-card">
                        <h2>4. Finalize the summary</h2>
                        <p>Use Payroll Summary to review total election day pay, training pay, mileage pay, and each worker total. Export CSV for payroll processing or print the summary for review.</p>
                        <a class="button secondary compact-button" href="<?= e(url('departments/election/payroll.php')) ?>">Payroll Summary</a>
                    </article>
                </div>
                <ol class="how-to-steps" style="margin-top: 18px;">
                    <li><strong>Start with setup.</strong> Payroll calculations depend on the selected election period, position rates, training rate, training cap, and mileage settings.</li>
                    <li><strong>Election day pay comes from assignments.</strong> Assigned workers appear in payroll, then supervisors confirm the work status and any Chief Judge pay override.</li>
                    <li><strong>Training pay comes from completed classes.</strong> The system counts completed training and applies the training rate up to the configured cap.</li>
                    <li><strong>Mileage is entered separately.</strong> Training mileage is reviewed on the Training Pay page and calculated using the mileage settings.</li>
                    <li><strong>Use reports before locking.</strong> Export CSV or Print PDF from Payroll Summary after reviewing totals.</li>
                    <li><strong>Lock payroll when finished.</strong> Locking the period prevents accidental changes to election day pay, training mileage, and payroll rates for that election.</li>
                </ol>
            </section>

            <section class="guide-topic-pane" data-guide-pane="signup" role="tabpanel" hidden>
                <h1>How to Sign Someone Up for Training</h1>
                <ol class="how-to-steps">
                    <li><strong>Open Training Classes.</strong> Find the class the worker should attend.</li>
                    <li><strong>Open the class.</strong> Use the view button on the class row to open the class detail page.</li>
                    <li><strong>Use Sign Up Worker.</strong> Choose the worker from the dropdown and select Sign up worker.</li>
                    <li><strong>Check the roster.</strong> The worker should appear on the Attendance Roster for that class.</li>
                </ol>
                <p class="muted">The dropdown only shows eligible active workers for that election. Most workers are hidden after they sign up for a class in the same election period, but Chief Judge and Assistant Chief Judge may join multiple classes as optional training.</p>
                <div class="actions">
                    <a class="button" href="<?= e(url('departments/election/classes.php')) ?>">Training Classes</a>
                    <a class="button secondary" href="<?= e(url('departments/election/training-signups.php')) ?>">Training Signups</a>
                </div>
            </section>

            <section class="guide-topic-pane" data-guide-pane="remove-training" role="tabpanel" hidden>
                <h1>How to Remove Someone from Training</h1>
                <ol class="how-to-steps">
                    <li><strong>Start with Training Signups.</strong> Find the worker and review every class they are signed up for.</li>
                    <li><strong>Open the class.</strong> Use the class link to go to the class detail page.</li>
                    <li><strong>Review the roster.</strong> Confirm you have the correct worker and class before removing anyone.</li>
                    <li><strong>Select Remove.</strong> The worker is removed from that class roster, but their contact record and precinct assignment stay in place.</li>
                </ol>
                <p class="muted">If the worker already attended the class, keep the attendance record unless it was entered by mistake. If their position changed, use Training Signups to decide whether they should stay in the class or be moved to a better fit.</p>
                <div class="actions">
                    <a class="button" href="<?= e(url('departments/election/training-signups.php')) ?>">Training Signups</a>
                    <a class="button secondary" href="<?= e(url('departments/election/classes.php')) ?>">Training Classes</a>
                </div>
            </section>

            <section class="guide-topic-pane" data-guide-pane="review" role="tabpanel" hidden>
                <h1>Daily Review</h1>
                <p>Use Needs Attention as the main checklist. The goal is not always to make every number zero, but every item should either be fixed or intentionally understood.</p>
                <ul class="how-to-checklist">
                    <li>Staffing gaps should be filled before election day.</li>
                    <li>Training follow-ups should be reviewed until workers are signed up or complete.</li>
                    <li>Training Signups should be reviewed for workers in the wrong class, missed classes, or duplicate signups.</li>
                    <li>Missing contact information should be corrected when possible.</li>
                    <li>Status conflicts and duplicate contacts should be cleaned up before final reports.</li>
                    <li>Election Day checklist items should be reviewed as the election gets close.</li>
                    <li>After election day, debrief responses, precinct notes, and Chief Judge feedback should be reviewed for follow-up items.</li>
                    <li>Closing an election period should happen after election day, once staffing and attendance records have been reviewed.</li>
                </ul>
                <div class="actions">
                    <a class="button" href="<?= e(url('departments/election/needs-attention.php')) ?>">Needs Attention</a>
                </div>
            </section>
        </div>
    </section>
</main>
<script>
document.querySelectorAll('[data-guide-tabs]').forEach((guide) => {
    const buttons = Array.from(guide.querySelectorAll('[data-guide-target]'));
    const panes = Array.from(guide.querySelectorAll('[data-guide-pane]'));

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const target = button.dataset.guideTarget;

            buttons.forEach((item) => {
                const active = item === button;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            panes.forEach((pane) => {
                const active = pane.dataset.guidePane === target;
                pane.classList.toggle('active', active);
                pane.hidden = !active;
            });
        });
    });
});
</script>
<?php page_footer(); ?>
