<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_k9_access();

$isManager = k9_user_can_manage();
[$teamWhere, $teamParams] = k9_visible_team_sql('k9_teams');
$teamSql = 'SELECT k9_teams.*, k9_dogs.dog_name, k9_handlers.handler_name
            FROM k9_teams
            INNER JOIN k9_dogs ON k9_dogs.id = k9_teams.dog_id
            INNER JOIN k9_handlers ON k9_handlers.id = k9_teams.handler_id
            WHERE k9_teams.is_active = 1' . $teamWhere . '
            ORDER BY k9_dogs.dog_name, k9_handlers.handler_name';
$statement = db()->prepare($teamSql);
$statement->execute($teamParams);
$teams = $statement->fetchAll();
$expenseCategories = k9_lookup_options('k9_expense_categories');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $selectedTeam = null;
    foreach ($teams as $team) {
        if ((int) $team['id'] === $teamId) {
            $selectedTeam = $team;
            break;
        }
    }

    if (!$selectedTeam) {
        flash('error', 'Select a valid K-9 team.');
        redirect_to('departments/k9/expense-edit.php');
    }

    $statement = db()->prepare(
        'INSERT INTO k9_expenses (dog_id, expense_date, expense_category_id, amount, vendor, notes)
         VALUES (:dog_id, :expense_date, :expense_category_id, :amount, :vendor, :notes)'
    );
    $statement->execute([
        'dog_id' => (int) $selectedTeam['dog_id'],
        'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
        'expense_category_id' => (int) ($_POST['expense_category_id'] ?? 0) ?: null,
        'amount' => k9_decimal($_POST['amount'] ?? '0'),
        'vendor' => trim($_POST['vendor'] ?? '') ?: null,
        'notes' => trim($_POST['notes'] ?? ''),
    ]);

    $expenseId = (int) db()->lastInsertId();
    audit_event('created', 'k9_expense', (string) $expenseId);
    flash('success', 'K-9 expense saved.');
    redirect_to('departments/k9/activity.php?record_type=expense');
}

page_header('Add K-9 Expense');
?>
<main class="shell">
    <section class="panel">
        <h1>Add Expense</h1>
        <p>Record K-9 program expenses without creating a training or deployment log.</p>
        <?php k9_navigation('expense-edit'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$teams): ?>
            <div class="notice error"><?= $isManager ? 'Create an active K-9 team before entering expenses.' : 'Your account is not connected to an active K-9 team yet.' ?></div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <label>
                K-9 team
                <select name="team_id" required>
                    <option value="">Select team</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= e((string) $team['id']) ?>"><?= e($team['dog_name'] . ' - ' . $team['handler_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Expense date
                <input type="date" name="expense_date" value="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>
                Category
                <select name="expense_category_id">
                    <option value="">Select category</option>
                    <?php foreach ($expenseCategories as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Amount
                <input name="amount" inputmode="decimal" placeholder="0.00">
            </label>
            <label class="span-2">
                Vendor
                <input name="vendor">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$teams ? 'disabled' : '' ?>>Save expense</button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
