<?php
require_once __DIR__ . '/../app/bootstrap.php';

$workerId = (int) ($_GET['worker'] ?? 0);
$token = trim($_GET['token'] ?? '');

$worker = election_find_worker_by_token($workerId, $token);

if (!$worker) {
    http_response_code(403);
    page_header('Election Readiness access');
    ?>
    <main class="shell">
        <section class="panel">
            <h1>Election Readiness access link expired</h1>
            <p>Ask your election supervisor for a new access link.</p>
        </section>
    </main>
    <?php
    page_footer();
    exit;
}

$_SESSION['election_worker_id'] = $worker['id'];
unset($_SESSION['election_assignment_id']);
session_regenerate_id(true);

if (empty($worker['reminder_preferences_asked_at'])) {
    redirect_to('departments/election/reminders.php');
}

redirect_to('departments/election/index.php');
