<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_access();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$currentWorker = current_election_worker();
$isManager = can_manage_election_module();
$canManageWorkers = current_election_actor_can_manage_workers();

$worker = null;
if ($id > 0) {
    $statement = db()->prepare('SELECT * FROM election_workers WHERE id = :id');
    $statement->execute(['id' => $id]);
    $worker = $statement->fetch();

    if (!$worker) {
        http_response_code(404);
        page_header('Worker not found');
        echo '<main class="shell"><section class="panel"><h1>Worker not found</h1><p>The selected election worker could not be found.</p></section></main>';
        page_footer();
        exit;
    }
}

$isSelfEdit = $currentWorker && $worker && (int) $currentWorker['id'] === (int) $worker['id'];

if (!$isSelfEdit && !$canManageWorkers) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to edit this worker.</p></section></main>';
    page_footer();
    exit;
}

if ($currentWorker && $worker && !$isManager) {
    if ((int) $worker['precinct_id'] !== (int) $currentWorker['precinct_id']
        || (int) $worker['election_period_id'] !== (int) $currentWorker['election_period_id']) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You can only manage workers in your assigned precinct.</p></section></main>';
        page_footer();
        exit;
    }
}

if ($id === 0 && !$canManageWorkers) {
    http_response_code(403);
    page_header('Access denied');
    echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to add workers.</p></section></main>';
    page_footer();
    exit;
}

$generatedAccessUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_worker';

    if ($action === 'generate_token' && $worker && !$isSelfEdit && $canManageWorkers) {
        $token = election_generate_worker_token((int) $worker['id']);
        $generatedAccessUrl = election_worker_access_url((int) $worker['id'], $token);
        audit_event('generated_access_link', 'election_worker', (string) $worker['id']);
    } else {
        $periodId = (int) ($_POST['election_period_id'] ?? ($worker['election_period_id'] ?? 0));
        $precinctId = (int) ($_POST['precinct_id'] ?? ($worker['precinct_id'] ?? 0));
        $positionId = (int) ($_POST['position_id'] ?? ($worker['position_id'] ?? 0));

        if ($currentWorker && !$isManager) {
            $periodId = (int) $currentWorker['election_period_id'];
            $precinctId = (int) $currentWorker['precinct_id'];
        }

        if ($isSelfEdit) {
            $positionId = (int) $worker['position_id'];
            $periodId = (int) $worker['election_period_id'];
            $precinctId = (int) $worker['precinct_id'];
        }

        $params = [
            'election_period_id' => $periodId,
            'precinct_id' => $precinctId,
            'position_id' => $positionId,
            'first_name' => title_case_name($_POST['first_name'] ?? ''),
            'last_name' => title_case_name($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'mailing_address' => title_case_address($_POST['mailing_address'] ?? ''),
            'city' => title_case_name($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'zip_code' => trim($_POST['zip_code'] ?? ''),
            'wants_email_reminders' => isset($_POST['wants_email_reminders']) ? 1 : 0,
            'wants_text_reminders' => isset($_POST['wants_text_reminders']) ? 1 : 0,
            'is_active' => $isSelfEdit ? (int) ($worker['is_active'] ?? 1) : (isset($_POST['is_active']) ? 1 : 0),
            'notes' => $isSelfEdit ? (string) ($worker['notes'] ?? '') : trim($_POST['notes'] ?? ''),
        ];

        if ($id > 0) {
            $params['id'] = $id;
            $statement = db()->prepare(
                'UPDATE election_workers
                 SET election_period_id = :election_period_id,
                     precinct_id = :precinct_id,
                     position_id = :position_id,
                     first_name = :first_name,
                     last_name = :last_name,
                     email = :email,
                     phone = :phone,
                     mailing_address = :mailing_address,
                     city = :city,
                     state = :state,
                     zip_code = :zip_code,
                     wants_email_reminders = :wants_email_reminders,
                     wants_text_reminders = :wants_text_reminders,
                     reminder_preferences_asked_at = COALESCE(reminder_preferences_asked_at, NOW()),
                     is_active = :is_active,
                     notes = :notes
                 WHERE id = :id'
            );
            $statement->execute($params);
            audit_event('updated', 'election_worker', (string) $id, ['name' => $params['first_name'] . ' ' . $params['last_name']]);
            flash('success', 'Worker saved.');
            redirect_to($isSelfEdit ? 'departments/election/index.php' : 'departments/election/workers.php');
        }

        $params['created_by_user_id'] = current_user()['id'] ?? null;
        $params['recruited_by_worker_id'] = $currentWorker['id'] ?? null;
        $statement = db()->prepare(
            'INSERT INTO election_workers (
                election_period_id, precinct_id, position_id, recruited_by_worker_id, created_by_user_id,
                first_name, last_name, email, phone, mailing_address, city, state, zip_code,
                wants_email_reminders, wants_text_reminders, is_active, notes
             )
             VALUES (
                :election_period_id, :precinct_id, :position_id, :recruited_by_worker_id, :created_by_user_id,
                :first_name, :last_name, :email, :phone, :mailing_address, :city, :state, :zip_code,
                :wants_email_reminders, :wants_text_reminders, 1, :notes
             )'
        );
        unset($params['is_active']);
        $statement->execute($params);
        $id = (int) db()->lastInsertId();
        $token = election_generate_worker_token($id);
        $generatedAccessUrl = election_worker_access_url($id, $token);
        audit_event('created', 'election_worker', (string) $id, ['name' => $params['first_name'] . ' ' . $params['last_name']]);

        $statement = db()->prepare('SELECT * FROM election_workers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $worker = $statement->fetch();
        flash('success', 'Worker added. Copy the access link before leaving this page.');
    }
}

$periods = election_active_periods();
$precincts = election_precincts();
$positions = election_positions();

if ($currentWorker && !$isManager) {
    $periods = array_values(array_filter($periods, fn($period) => (int) $period['id'] === (int) $currentWorker['election_period_id']));
    $precincts = array_values(array_filter($precincts, fn($precinct) => (int) $precinct['id'] === (int) $currentWorker['precinct_id']));
}

$actions = [
    ['label' => 'Workers', 'href' => url('departments/election/workers.php'), 'primary' => true],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

page_header($id > 0 ? 'Edit Election Worker' : 'Add Election Worker');
?>
<main class="shell">
    <section class="panel">
        <h1><?= $id > 0 ? 'Edit Election Worker' : 'Add Election Worker' ?></h1>
        <p><?= $isSelfEdit ? 'Update your contact information and reminder preferences.' : 'Assign the worker to an election, precinct, and position.' ?></p>
        <?php if (!$isSelfEdit): ?>
            <?php page_actions($actions); ?>
        <?php endif; ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($generatedAccessUrl): ?>
            <div class="notice success">
                Access link: <a href="<?= e($generatedAccessUrl) ?>"><?= e($generatedAccessUrl) ?></a>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
            <?php if (!$isSelfEdit): ?>
                <label class="span-2">
                    Election
                    <select name="election_period_id" required>
                        <option value="">Select election</option>
                        <?php foreach ($periods as $period): ?>
                            <option value="<?= e((string) $period['id']) ?>" <?= (int) ($worker['election_period_id'] ?? 0) === (int) $period['id'] ? 'selected' : '' ?>><?= e($period['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Precinct
                    <select name="precinct_id" required>
                        <option value="">Select precinct</option>
                        <?php foreach ($precincts as $precinct): ?>
                            <option value="<?= e((string) $precinct['id']) ?>" <?= (int) ($worker['precinct_id'] ?? 0) === (int) $precinct['id'] ? 'selected' : '' ?>><?= e($precinct['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Position
                    <select name="position_id" required>
                        <option value="">Select position</option>
                        <?php foreach ($positions as $position): ?>
                            <option value="<?= e((string) $position['id']) ?>" <?= (int) ($worker['position_id'] ?? 0) === (int) $position['id'] ? 'selected' : '' ?>><?= e($position['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label>
                First name
                <input name="first_name" value="<?= e($worker['first_name'] ?? '') ?>" required>
            </label>
            <label>
                Last name
                <input name="last_name" value="<?= e($worker['last_name'] ?? '') ?>" required>
            </label>
            <label>
                Email
                <input type="email" name="email" value="<?= e($worker['email'] ?? '') ?>">
            </label>
            <label>
                Phone
                <input name="phone" class="phone-input" inputmode="tel" placeholder="(208) 555-1234" value="<?= e($worker['phone'] ?? '') ?>">
            </label>
            <label>
                Mailing address
                <input name="mailing_address" value="<?= e($worker['mailing_address'] ?? '') ?>">
            </label>
            <label>
                City
                <input name="city" value="<?= e($worker['city'] ?? '') ?>">
            </label>
            <label>
                State
                <select name="state">
                    <?php state_options($worker['state'] ?? 'ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code" value="<?= e($worker['zip_code'] ?? '') ?>">
            </label>
            <label class="check-label">
                <input type="checkbox" name="wants_email_reminders" <?= (int) ($worker['wants_email_reminders'] ?? 0) === 1 ? 'checked' : '' ?>>
                Email reminders
            </label>
            <label class="check-label">
                <input type="checkbox" name="wants_text_reminders" <?= (int) ($worker['wants_text_reminders'] ?? 0) === 1 ? 'checked' : '' ?>>
                Text reminders
            </label>
            <?php if (!$isSelfEdit): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) ($worker['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    Active worker
                </label>
                <label class="span-2">
                    Notes
                    <textarea name="notes"><?= e($worker['notes'] ?? '') ?></textarea>
                </label>
            <?php endif; ?>
            <div class="actions span-2">
                <button type="submit">Save worker</button>
                <?php if ($isSelfEdit): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/index.php')) ?>">Cancel</a>
                <?php else: ?>
                    <a class="button secondary" href="<?= e(url('departments/election/workers.php')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if ($worker && !$isSelfEdit && $canManageWorkers): ?>
        <section class="panel" style="margin-top: 18px;">
            <h1>Access Link</h1>
            <p>Generate a fresh access link when a worker needs to sign in without a username and password.</p>
            <form method="post">
                <input type="hidden" name="id" value="<?= e((string) $worker['id']) ?>">
                <input type="hidden" name="action" value="generate_token">
                <button type="submit" class="secondary">Generate new link</button>
            </form>
        </section>
    <?php endif; ?>
</main>
<script src="<?= e(url('assets/forms.js?v=20260730c')) ?>"></script>
<?php page_footer(); ?>
