<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();

$subject = election_setting('access_email_subject', ELECTION_DEFAULT_ACCESS_EMAIL_SUBJECT);
$body = election_setting('access_email_body', ELECTION_DEFAULT_ACCESS_EMAIL_BODY);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($subject === '' || $body === '') {
        flash('error', 'Subject and message are required.');
        redirect_to('departments/election/email-template.php');
    }

    if (!str_contains($body, '[Access Link]')) {
        flash('error', 'The message must include [Access Link].');
        redirect_to('departments/election/email-template.php');
    }

    election_save_setting('access_email_subject', $subject);
    election_save_setting('access_email_body', $body);
    audit_event('updated', 'election_setting', 'access_email_template');
    flash('success', 'Email template saved.');
    redirect_to('departments/election/email-template.php');
}

$sampleReplacements = [
    '[Worker Name]' => 'Jane Smith',
    '[Election Name]' => 'November 3, 2026 General Election',
    '[Precinct Name]' => 'Labelle',
    '[Position Name]' => 'Receiving Clerk',
    '[Access Link]' => 'https://example.com/election-access.php?worker=123&token=sample',
];
$sampleSubject = strtr($subject, $sampleReplacements);
$sampleBody = strtr($body, $sampleReplacements);

$actions = [
    ['label' => 'Bulk Email', 'href' => url('departments/election/bulk-email.php'), 'primary' => true],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
    ['label' => 'Contacts', 'href' => url('departments/election/workers.php')],
];

page_header('Email Template');
?>
<main class="shell">
    <section class="panel">
        <h1>Email Template</h1>
        <p>Set the wording used when sending election access links.</p>
        <?php election_navigation('email-template'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Edit Message</h1>
        <form class="form" method="post">
            <label>
                Subject
                <input name="subject" value="<?= e($subject) ?>" required>
            </label>
            <label>
                Message
                <textarea name="body" rows="12" required><?= e($body) ?></textarea>
            </label>
            <div>
                <p class="meta">Available placeholders: [Worker Name], [Election Name], [Precinct Name], [Position Name], [Access Link]</p>
            </div>
            <div class="actions">
                <button type="submit">Save template</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Preview</h1>
        <div class="email-template-preview">
            <p><strong>Subject:</strong> <?= e($sampleSubject) ?></p>
            <pre><?= e($sampleBody) ?></pre>
        </div>
    </section>
</main>
<?php page_footer(); ?>
