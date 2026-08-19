<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_sheriff_training_manager();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$request = [
    'id' => 0,
    'officer_id' => (int) ($_GET['officer_id'] ?? 0),
    'fiscal_year_id' => 0,
    'class_name' => '',
    'provider' => '',
    'location' => '',
    'start_date' => date('Y-m-d'),
    'end_date' => '',
    'estimated_training_cost' => '0.00',
    'estimated_lodging_cost' => '0.00',
    'status' => 'pending',
    'notes' => '',
];

if ($id > 0) {
    $request = sheriff_training_request_by_id($id);
    if (!$request) {
        http_response_code(404);
        page_header('Request not found');
        echo '<main class="shell"><section class="panel"><h1>Request not found</h1><p>The selected training request could not be found.</p></section></main>';
        page_footer();
        exit;
    }
}

$officers = db()->query('SELECT * FROM sheriff_training_officers WHERE is_active = 1 OR id = ' . (int) $request['officer_id'] . ' ORDER BY last_name, first_name')->fetchAll();
$years = db()->query('SELECT * FROM sheriff_training_fiscal_years WHERE is_active = 1 OR id = ' . (int) $request['fiscal_year_id'] . ' ORDER BY fiscal_year DESC')->fetchAll();
if (!$request['fiscal_year_id'] && $years) {
    $currentFiscalYear = sheriff_training_fiscal_year_for_date($request['start_date']);
    foreach ($years as $year) {
        if ((int) $year['fiscal_year'] === $currentFiscalYear) {
            $request['fiscal_year_id'] = (int) $year['id'];
            break;
        }
    }
    $request['fiscal_year_id'] = $request['fiscal_year_id'] ?: (int) $years[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'officer_id' => (int) ($_POST['officer_id'] ?? 0),
        'fiscal_year_id' => (int) ($_POST['fiscal_year_id'] ?? 0),
        'class_name' => trim($_POST['class_name'] ?? ''),
        'provider' => trim($_POST['provider'] ?? '') ?: null,
        'location' => trim($_POST['location'] ?? '') ?: null,
        'start_date' => $_POST['start_date'] ?? date('Y-m-d'),
        'end_date' => trim($_POST['end_date'] ?? '') ?: null,
        'estimated_training_cost' => sheriff_training_decimal($_POST['estimated_training_cost'] ?? '0'),
        'estimated_lodging_cost' => sheriff_training_decimal($_POST['estimated_lodging_cost'] ?? '0'),
        'notes' => trim($_POST['notes'] ?? '') ?: null,
    ];

    if ($data['officer_id'] <= 0 || $data['fiscal_year_id'] <= 0 || $data['class_name'] === '') {
        flash('error', 'Officer, fiscal year, and training class name are required.');
        redirect_to('departments/sheriff-training/request-edit.php' . ($id > 0 ? '?id=' . $id : ''));
    }

    if ($id > 0) {
        $statement = db()->prepare(
            'UPDATE sheriff_training_requests
             SET officer_id = :officer_id,
                 fiscal_year_id = :fiscal_year_id,
                 class_name = :class_name,
                 provider = :provider,
                 location = :location,
                 start_date = :start_date,
                 end_date = :end_date,
                 estimated_training_cost = :estimated_training_cost,
                 estimated_lodging_cost = :estimated_lodging_cost,
                 notes = :notes
             WHERE id = :id'
        );
        $data['id'] = $id;
        $statement->execute($data);
        audit_event('updated', 'sheriff_training_request', (string) $id);
        flash('success', 'Training request updated.');
    } else {
        $statement = db()->prepare(
            'INSERT INTO sheriff_training_requests
                (officer_id, fiscal_year_id, created_by_user_id, class_name, provider, location, start_date, end_date,
                 estimated_training_cost, estimated_lodging_cost, notes)
             VALUES
                (:officer_id, :fiscal_year_id, :created_by_user_id, :class_name, :provider, :location, :start_date, :end_date,
                 :estimated_training_cost, :estimated_lodging_cost, :notes)'
        );
        $statement->execute($data + ['created_by_user_id' => current_user()['id'] ?? null]);
        $id = (int) db()->lastInsertId();
        audit_event('created', 'sheriff_training_request', (string) $id);
        flash('success', 'Training request added.');
    }

    redirect_to('departments/sheriff-training/request-detail.php?id=' . $id);
}

page_header($id > 0 ? 'Edit Training Request' : 'New Training Request');
?>
<main class="shell">
    <section class="panel">
        <h1><?= $id > 0 ? 'Edit Training Request' : 'New Training Request' ?></h1>
        <p>Enter the paper request details, estimated costs, and payment fiscal year.</p>
        <?php sheriff_training_navigation('request-edit'); ?>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (!$officers || !$years): ?>
            <div class="notice error">Add at least one active officer and fiscal budget before entering requests.</div>
        <?php endif; ?>

        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <label>
                Officer
                <select name="officer_id" required>
                    <option value="">Select officer</option>
                    <?php foreach ($officers as $officer): ?>
                        <option value="<?= e((string) $officer['id']) ?>" <?= (int) $request['officer_id'] === (int) $officer['id'] ? 'selected' : '' ?>>
                            <?= e($officer['last_name'] . ', ' . $officer['first_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Payment fiscal year
                <select name="fiscal_year_id" required>
                    <option value="">Select fiscal year</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= e((string) $year['id']) ?>" <?= (int) $request['fiscal_year_id'] === (int) $year['id'] ? 'selected' : '' ?>>
                            <?= e($year['label']) ?> (<?= e(format_display_date($year['starts_on'])) ?> to <?= e(format_display_date($year['ends_on'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">
                Training class name
                <input name="class_name" value="<?= e($request['class_name']) ?>" required>
            </label>
            <label>
                Provider
                <input name="provider" value="<?= e($request['provider']) ?>">
            </label>
            <label>
                Location
                <input name="location" value="<?= e($request['location']) ?>">
            </label>
            <label>
                Start date
                <input type="date" name="start_date" value="<?= e($request['start_date']) ?>" required>
            </label>
            <label>
                End date
                <input type="date" name="end_date" value="<?= e($request['end_date']) ?>">
            </label>
            <label>
                Estimated class cost
                <input name="estimated_training_cost" inputmode="decimal" value="<?= e((string) $request['estimated_training_cost']) ?>">
            </label>
            <label>
                Estimated lodging cost
                <input name="estimated_lodging_cost" inputmode="decimal" value="<?= e((string) $request['estimated_lodging_cost']) ?>">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($request['notes']) ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save request</button>
                <a class="button secondary" href="<?= e($id > 0 ? url('departments/sheriff-training/request-detail.php?id=' . $id) : url('departments/sheriff-training/requests.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
