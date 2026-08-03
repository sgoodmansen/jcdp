<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
election_require_assignment_setup();

$worker = current_election_worker();

if (!$worker) {
    redirect_to('login.php');
}

$assignments = election_assignments_for_worker((int) $worker['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assignmentId = (int) ($_POST['assignment_id'] ?? 0);

    foreach ($assignments as $assignment) {
        if ((int) $assignment['id'] === $assignmentId) {
            $_SESSION['election_assignment_id'] = $assignmentId;
            redirect_to('departments/election/index.php');
        }
    }

    flash('error', 'Select an active election assignment.');
    redirect_to('departments/election/select-assignment.php');
}

if (count($assignments) === 1) {
    $_SESSION['election_assignment_id'] = $assignments[0]['id'];
    redirect_to('departments/election/index.php');
}

page_header('Select Election Assignment');
?>
<main class="shell">
    <section class="panel">
        <h1>Select Election Assignment</h1>
        <p>Choose the role you want to use right now.</p>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$assignments): ?>
            <p>No active election assignments are available. Ask your election supervisor for help.</p>
        <?php else: ?>
            <form class="form compact-form" method="post">
                <label class="span-2">
                    Assignment
                    <select name="assignment_id" required>
                        <option value="">Select assignment</option>
                        <?php foreach ($assignments as $assignment): ?>
                            <option value="<?= e((string) $assignment['id']) ?>">
                                <?= e($assignment['election_name']) ?> - <?= e($assignment['precinct_name']) ?>, <?= e($assignment['position_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="actions span-2">
                    <button type="submit">Continue</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php page_footer(); ?>
