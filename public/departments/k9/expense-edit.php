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
$id = (int) ($_GET['id'] ?? 0);
$record = null;

if ($id > 0) {
    $recordStatement = db()->prepare(
        'SELECT k9_expenses.*
         FROM k9_expenses
         INNER JOIN k9_dogs ON k9_dogs.id = k9_expenses.dog_id
         INNER JOIN k9_teams ON k9_teams.dog_id = k9_dogs.id AND k9_teams.is_active = 1
         WHERE k9_expenses.id = :id' . k9_not_voided_sql('k9_expenses') . $teamWhere
    );
    $recordStatement->execute(array_merge($teamParams, ['id' => $id]));
    $record = $recordStatement->fetch();
    if (!$record) {
        http_response_code(404);
        page_header('Expense Not Found');
        echo '<main class="shell"><section class="panel"><h1>Expense not found</h1><p>The selected expense record could not be found.</p></section></main>';
        page_footer();
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $redirectPath = 'departments/k9/expense-edit.php' . ($id > 0 ? '?id=' . $id : '');
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
        redirect_to($redirectPath);
    }

    $expenseDate = trim($_POST['expense_date'] ?? '');
    $amount = k9_decimal($_POST['amount'] ?? '0');
    $validationErrors = [];

    if (!k9_is_valid_date($expenseDate)) {
        $validationErrors[] = 'Enter a valid expense date.';
    } elseif (k9_date_is_future($expenseDate)) {
        $validationErrors[] = 'Expense date cannot be in the future.';
    }
    if ($amount < 0) {
        $validationErrors[] = 'Expense amount cannot be negative.';
    }

    k9_flash_validation_errors($validationErrors, $redirectPath);

    $saveParams = [
        'dog_id' => (int) $selectedTeam['dog_id'],
        'expense_date' => $expenseDate,
        'expense_category_id' => (int) ($_POST['expense_category_id'] ?? 0) ?: null,
        'amount' => $amount,
        'vendor' => trim($_POST['vendor'] ?? '') ?: null,
        'notes' => trim($_POST['notes'] ?? ''),
    ];

    if ($id > 0) {
        $statement = db()->prepare(
            'UPDATE k9_expenses
             SET dog_id = :dog_id, expense_date = :expense_date, expense_category_id = :expense_category_id,
                 amount = :amount, vendor = :vendor, notes = :notes
             WHERE id = :id AND voided_at IS NULL'
        );
        $statement->execute(array_merge($saveParams, ['id' => $id]));
        $expenseId = $id;
    } else {
        $statement = db()->prepare(
            'INSERT INTO k9_expenses (dog_id, expense_date, expense_category_id, amount, vendor, notes)
             VALUES (:dog_id, :expense_date, :expense_category_id, :amount, :vendor, :notes)'
        );
        $statement->execute($saveParams);
        $expenseId = (int) db()->lastInsertId();
    }

    audit_event($id > 0 ? 'updated' : 'created', 'k9_expense', (string) $expenseId);
    flash('success', $id > 0 ? 'K-9 expense updated.' : 'K-9 expense saved.');
    redirect_to('departments/k9/record-detail.php?type=expense&id=' . $expenseId);
}

page_header($id > 0 ? 'Edit K-9 Expense' : 'Add K-9 Expense');
?>
<main class="shell">
    <section class="panel">
        <h1><?= $id > 0 ? 'Edit Expense' : 'Add Expense' ?></h1>
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
                        <option value="<?= e((string) $team['id']) ?>" <?= (int) ($record['dog_id'] ?? 0) === (int) $team['dog_id'] ? 'selected' : '' ?>><?= e($team['dog_name'] . ' - ' . $team['handler_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Expense date
                <input type="date" name="expense_date" value="<?= e($record['expense_date'] ?? date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            </label>
            <label>
                Category
                <select name="expense_category_id">
                    <option value="">Select category</option>
                    <?php foreach ($expenseCategories as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>" <?= (int) ($record['expense_category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Amount
                <input type="number" name="amount" inputmode="decimal" min="0" step="0.01" value="<?= e(isset($record['amount']) ? number_format((float) $record['amount'], 2, '.', '') : '') ?>" placeholder="0.00">
            </label>
            <label class="span-2">
                Vendor
                <input name="vendor" value="<?= e($record['vendor'] ?? '') ?>">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($record['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit" <?= !$teams ? 'disabled' : '' ?>><?= $id > 0 ? 'Save changes' : 'Save expense' ?></button>
                <a class="button secondary" href="<?= e(url('departments/k9/activity.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
