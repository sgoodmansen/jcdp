<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_manager();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_team') {
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $teamEndDate = $_POST['end_date'] ?: null;
        if ($teamEndDate !== null) {
            $isActive = 0;
        }

        db()->beginTransaction();
        try {
            $statement = db()->prepare(
                'INSERT INTO k9_handlers (user_id, officer_number, handler_name, position_start_date, position_end_date, reminder_days, is_active, notes)
                 VALUES (:user_id, :officer_number, :handler_name, :position_start_date, :position_end_date, :reminder_days, :is_active, :notes)'
            );
            $statement->execute([
                'user_id' => (int) ($_POST['user_id'] ?? 0) ?: null,
                'officer_number' => trim($_POST['officer_number'] ?? ''),
                'handler_name' => trim($_POST['handler_name'] ?? ''),
                'position_start_date' => $_POST['start_date'] ?: null,
                'position_end_date' => $teamEndDate,
                'reminder_days' => max(1, (int) ($_POST['reminder_days'] ?? 30)),
                'is_active' => $isActive,
                'notes' => trim($_POST['notes'] ?? ''),
            ]);
            $handlerId = (int) db()->lastInsertId();

            $statement = db()->prepare(
                'INSERT INTO k9_dogs (dog_name, breed, service_start_date, service_end_date, is_active, notes)
                 VALUES (:dog_name, :breed, :service_start_date, :service_end_date, :is_active, :notes)'
            );
            $statement->execute([
                'dog_name' => trim($_POST['dog_name'] ?? ''),
                'breed' => trim($_POST['breed'] ?? ''),
                'service_start_date' => $_POST['start_date'] ?: null,
                'service_end_date' => $teamEndDate,
                'is_active' => $isActive,
                'notes' => trim($_POST['notes'] ?? ''),
            ]);
            $dogId = (int) db()->lastInsertId();

            $statement = db()->prepare(
                'INSERT INTO k9_teams (dog_id, handler_id, start_date, end_date, is_active, notes)
                 VALUES (:dog_id, :handler_id, :start_date, :end_date, :is_active, :notes)'
            );
            $statement->execute([
                'dog_id' => $dogId,
                'handler_id' => $handlerId,
                'start_date' => $_POST['start_date'] ?: null,
                'end_date' => $teamEndDate,
                'is_active' => $isActive,
                'notes' => trim($_POST['notes'] ?? ''),
            ]);
            $teamId = (int) db()->lastInsertId();

            audit_event('created', 'k9_team', (string) $teamId);
            db()->commit();
            flash('success', 'K-9 team saved.');
        } catch (Throwable $exception) {
            db()->rollBack();
            flash('error', 'The K-9 team could not be saved.');
        }
    }

    if ($action === 'end_team') {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $endDate = $_POST['end_date'] ?: date('Y-m-d');

        $statement = db()->prepare('SELECT * FROM k9_teams WHERE id = :id');
        $statement->execute(['id' => $teamId]);
        $team = $statement->fetch();

        if ($team) {
            db()->beginTransaction();
            try {
                $statement = db()->prepare('UPDATE k9_teams SET end_date = :end_date, is_active = 0 WHERE id = :id');
                $statement->execute(['end_date' => $endDate, 'id' => $teamId]);

                $statement = db()->prepare('UPDATE k9_handlers SET position_end_date = :end_date, is_active = 0 WHERE id = :id');
                $statement->execute(['end_date' => $endDate, 'id' => (int) $team['handler_id']]);

                $statement = db()->prepare('UPDATE k9_dogs SET service_end_date = :end_date, is_active = 0 WHERE id = :id');
                $statement->execute(['end_date' => $endDate, 'id' => (int) $team['dog_id']]);

                audit_event('ended', 'k9_team', (string) $teamId);
                db()->commit();
                flash('success', 'K-9 team ended.');
            } catch (Throwable $exception) {
                db()->rollBack();
                flash('error', 'The K-9 team could not be ended.');
            }
        }
    }

    redirect_to('departments/k9/teams.php');
}

$users = db()->query('SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 ORDER BY last_name, first_name')->fetchAll();
$teams = db()->query(
    'SELECT k9_teams.*, k9_dogs.dog_name, k9_dogs.breed, k9_handlers.handler_name, k9_handlers.officer_number, k9_handlers.reminder_days, users.email
     FROM k9_teams
     INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
     INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
     LEFT JOIN users ON users.id = k9_handlers.user_id
     ORDER BY k9_teams.is_active DESC, k9_dogs.dog_name, k9_handlers.handler_name'
)->fetchAll();

page_header('K-9 Teams');
?>
<main class="shell">
    <section class="panel">
        <h1>K-9 Teams</h1>
        <p>Add a handler and K-9 as one team. Ending the team also ends the handler and dog record for new entries.</p>
        <?php k9_navigation('teams'); ?>
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Add K-9 Team</h1>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="add_team">
            <label>
                Handler portal user
                <select name="user_id">
                    <option value="">Not connected yet</option>
                    <?php foreach ($users as $portalUser): ?>
                        <option value="<?= e((string) $portalUser['id']) ?>"><?= e($portalUser['first_name'] . ' ' . $portalUser['last_name'] . ' - ' . $portalUser['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Handler name
                <input name="handler_name" required>
            </label>
            <label>
                Officer number
                <input name="officer_number">
            </label>
            <label>
                K-9 name
                <input name="dog_name" required>
            </label>
            <label>
                Breed
                <input name="breed">
            </label>
            <label>
                Team start date
                <input type="date" name="start_date">
            </label>
            <label>
                Team end date
                <input type="date" name="end_date">
            </label>
            <label>
                Shot reminder days
                <input type="number" name="reminder_days" min="1" value="30">
            </label>
            <label class="toggle-option">
                <input type="checkbox" name="is_active" value="1" checked>
                <span class="toggle-track" aria-hidden="true"></span>
                <span>
                    Active team
                    <small>Use this team for new K-9 activity records.</small>
                </span>
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save team</button>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Teams</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>K-9</th>
                    <th>Handler</th>
                    <th>Dates</th>
                    <th>Reminder</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teams as $team): ?>
                    <tr>
                        <td data-label="K-9">
                            <?= e($team['dog_name']) ?>
                            <?php if ($team['breed']): ?>
                                <br><span class="meta"><?= e($team['breed']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Handler">
                            <?= e($team['handler_name']) ?>
                            <?php if ($team['officer_number']): ?>
                                <br><span class="meta">#<?= e($team['officer_number']) ?></span>
                            <?php endif; ?>
                            <?php if ($team['email']): ?>
                                <br><span class="meta"><?= e($team['email']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Dates">
                            <?= e($team['start_date'] ? format_display_date($team['start_date']) : 'No start date') ?>
                            <?php if ($team['end_date']): ?>
                                <br><span class="meta">Ended <?= e(format_display_date($team['end_date'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Reminder"><?= e((string) $team['reminder_days']) ?> days</td>
                        <td data-label="Status"><?= (int) $team['is_active'] === 1 ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-muted">Ended</span>' ?></td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <a class="button secondary compact-button" href="<?= e(url('departments/k9/team-edit.php?id=' . (int) $team['id'])) ?>">Edit</a>
                            </div>
                            <?php if ((int) $team['is_active'] === 1): ?>
                                <form class="table-actions" method="post">
                                    <input type="hidden" name="action" value="end_team">
                                    <input type="hidden" name="team_id" value="<?= e((string) $team['id']) ?>">
                                    <input type="hidden" name="end_date" value="<?= e(date('Y-m-d')) ?>">
                                    <button type="submit" class="secondary compact-button">End team</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$teams): ?>
                    <tr><td colspan="6">No K-9 teams have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
