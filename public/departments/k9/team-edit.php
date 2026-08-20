<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_manager();

$id = (int) ($_GET['id'] ?? 0);

$statement = db()->prepare(
    'SELECT k9_teams.*, k9_dogs.dog_name, k9_dogs.breed, k9_handlers.user_id, k9_handlers.handler_name,
            k9_handlers.officer_number, k9_handlers.reminder_days
     FROM k9_teams
     INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
     INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
     WHERE k9_teams.id = :id'
);
$statement->execute(['id' => $id]);
$team = $statement->fetch();

if (!$team) {
    http_response_code(404);
    page_header('K-9 Team Not Found');
    echo '<main class="shell"><section class="panel"><h1>K-9 team not found</h1><p>The selected K-9 team could not be found.</p><p><a class="button secondary" href="' . e(url('departments/k9/teams.php')) . '">Back to teams</a></p></section></main>';
    page_footer();
    exit;
}

$usersStatement = db()->prepare(
    'SELECT id, first_name, last_name, email
     FROM users
     WHERE is_active = 1 OR id = :user_id
     ORDER BY first_name, last_name'
);
$usersStatement->execute(['user_id' => (int) ($team['user_id'] ?? 0)]);
$users = $usersStatement->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $handlerName = trim($_POST['handler_name'] ?? '');
    $dogName = trim($_POST['dog_name'] ?? '');

    if ($handlerName === '' || $dogName === '') {
        flash('error', 'Enter a handler name and K-9 name.');
        redirect_to('departments/k9/team-edit.php?id=' . $id);
    }

    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $teamEndDate = $_POST['end_date'] ?: null;
    if ($teamEndDate !== null) {
        $isActive = 0;
    }
    if ($isActive === 0 && $teamEndDate === null) {
        $teamEndDate = date('Y-m-d');
    }

    $notes = trim($_POST['notes'] ?? '');
    $startDate = $_POST['start_date'] ?: null;

    db()->beginTransaction();
    try {
        $statement = db()->prepare(
            'UPDATE k9_handlers
             SET user_id = :user_id, officer_number = :officer_number, handler_name = :handler_name,
                 position_start_date = :position_start_date, position_end_date = :position_end_date,
                 reminder_days = :reminder_days, is_active = :is_active, notes = :notes
             WHERE id = :id'
        );
        $statement->execute([
            'user_id' => (int) ($_POST['user_id'] ?? 0) ?: null,
            'officer_number' => trim($_POST['officer_number'] ?? ''),
            'handler_name' => $handlerName,
            'position_start_date' => $startDate,
            'position_end_date' => $teamEndDate,
            'reminder_days' => max(1, (int) ($_POST['reminder_days'] ?? 30)),
            'is_active' => $isActive,
            'notes' => $notes,
            'id' => (int) $team['handler_id'],
        ]);

        $statement = db()->prepare(
            'UPDATE k9_dogs
             SET dog_name = :dog_name, breed = :breed, service_start_date = :service_start_date,
                 service_end_date = :service_end_date, is_active = :is_active, notes = :notes
             WHERE id = :id'
        );
        $statement->execute([
            'dog_name' => $dogName,
            'breed' => trim($_POST['breed'] ?? ''),
            'service_start_date' => $startDate,
            'service_end_date' => $teamEndDate,
            'is_active' => $isActive,
            'notes' => $notes,
            'id' => (int) $team['dog_id'],
        ]);

        $statement = db()->prepare(
            'UPDATE k9_teams
             SET start_date = :start_date, end_date = :end_date, is_active = :is_active, notes = :notes
             WHERE id = :id'
        );
        $statement->execute([
            'start_date' => $startDate,
            'end_date' => $teamEndDate,
            'is_active' => $isActive,
            'notes' => $notes,
            'id' => $id,
        ]);

        audit_event('updated', 'k9_team', (string) $id);
        db()->commit();
        flash('success', 'K-9 team updated.');
        redirect_to('departments/k9/teams.php');
    } catch (Throwable $exception) {
        db()->rollBack();
        flash('error', 'The K-9 team could not be updated.');
        redirect_to('departments/k9/team-edit.php?id=' . $id);
    }
}

page_header('Edit K-9 Team');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit K-9 Team</h1>
        <p>Update the handler and K-9 team record in one place.</p>
        <?php k9_navigation('teams'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <label>
                Handler portal user
                <select name="user_id">
                    <option value="">Not connected yet</option>
                    <?php foreach ($users as $portalUser): ?>
                        <option value="<?= e((string) $portalUser['id']) ?>" <?= (int) ($team['user_id'] ?? 0) === (int) $portalUser['id'] ? 'selected' : '' ?>><?= e($portalUser['first_name'] . ' ' . $portalUser['last_name'] . ' - ' . $portalUser['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Handler name
                <input name="handler_name" value="<?= e($team['handler_name']) ?>" required>
            </label>
            <label>
                Officer number
                <input name="officer_number" value="<?= e($team['officer_number'] ?? '') ?>">
            </label>
            <label>
                K-9 name
                <input name="dog_name" value="<?= e($team['dog_name']) ?>" required>
            </label>
            <label>
                Breed
                <input name="breed" value="<?= e($team['breed'] ?? '') ?>">
            </label>
            <label>
                Team start date
                <input type="date" name="start_date" value="<?= e($team['start_date'] ?? '') ?>">
            </label>
            <label>
                Team end date
                <input type="date" name="end_date" value="<?= e($team['end_date'] ?? '') ?>">
            </label>
            <label>
                Shot reminder days
                <input type="number" name="reminder_days" min="1" value="<?= e((string) ($team['reminder_days'] ?? 30)) ?>">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_active" value="1" <?= (int) $team['is_active'] === 1 ? 'checked' : '' ?>>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>
                    Active team
                    <small>Use this team for new K-9 activity records.</small>
                </span>
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($team['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/k9/teams.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
