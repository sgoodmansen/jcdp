<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

if (!is_election_worker_logged_in()) {
    redirect_to('login.php');
}

$worker = current_election_worker();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statement = db()->prepare(
        'UPDATE election_workers
         SET wants_email_reminders = :wants_email,
             wants_text_reminders = :wants_text,
             reminder_preferences_asked_at = NOW()
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $worker['id'],
        'wants_email' => isset($_POST['wants_email_reminders']) ? 1 : 0,
        'wants_text' => isset($_POST['wants_text_reminders']) ? 1 : 0,
    ]);

    flash('success', 'Reminder preferences saved.');
    redirect_to('departments/election/index.php');
}

page_header('Reminder Preferences');
?>
<main class="shell">
    <section class="panel">
        <h1>Reminder Preferences</h1>
        <p>Choose how you want to receive election training reminders.</p>

        <form class="form compact-form" method="post">
            <label class="check-label">
                <input type="checkbox" name="wants_email_reminders" <?= (int) $worker['wants_email_reminders'] === 1 ? 'checked' : '' ?>>
                Email reminders
            </label>
            <label class="check-label">
                <input type="checkbox" name="wants_text_reminders" <?= (int) $worker['wants_text_reminders'] === 1 ? 'checked' : '' ?>>
                Text reminders
            </label>
            <div class="actions span-2">
                <button type="submit">Save preferences</button>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
