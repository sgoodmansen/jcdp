<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();

$editPeriodId = (int) ($_GET['edit_period'] ?? 0);
$editPrecinctId = (int) ($_GET['edit_precinct'] ?? 0);
$editPositionId = (int) ($_GET['edit_position'] ?? 0);
$editPeriod = null;
$editPrecinct = null;
$editPosition = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_period') {
        $periodId = (int) ($_POST['period_id'] ?? 0);
        $params = [
            'name' => trim($_POST['name'] ?? ''),
            'starts_on' => $_POST['starts_on'] ?? date('Y-m-d'),
            'ends_on' => $_POST['ends_on'] ?? date('Y-m-d'),
            'notes' => trim($_POST['notes'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($periodId > 0) {
            $params['id'] = $periodId;
            $statement = db()->prepare(
                'UPDATE election_periods
                 SET name = :name, starts_on = :starts_on, ends_on = :ends_on, notes = :notes, is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($params);
            audit_event('updated', 'election_period', (string) $periodId, ['name' => $params['name']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO election_periods (name, starts_on, ends_on, notes, is_active)
                 VALUES (:name, :starts_on, :ends_on, :notes, 1)'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $periodId = (int) db()->lastInsertId();
            audit_event('created', 'election_period', (string) $periodId, ['name' => $params['name']]);
        }

        flash('success', 'Election period saved.');
        redirect_to('departments/election/setup.php');
    }

    if ($action === 'close_period') {
        $periodId = (int) ($_POST['period_id'] ?? 0);
        redirect_to('departments/election/close-period.php?id=' . $periodId);
    }

    if ($action === 'save_precinct') {
        $precinctId = (int) ($_POST['precinct_id'] ?? 0);
        $params = [
            'name' => trim($_POST['name'] ?? ''),
            'location_name' => title_case_company($_POST['location_name'] ?? ''),
            'street_address' => title_case_address($_POST['street_address'] ?? ''),
            'city' => title_case_name($_POST['city'] ?? ''),
            'state' => trim($_POST['state'] ?? ''),
            'zip_code' => trim($_POST['zip_code'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($precinctId > 0) {
            $params['id'] = $precinctId;
            $statement = db()->prepare(
                'UPDATE election_precincts
                 SET name = :name,
                     location_name = :location_name,
                     street_address = :street_address,
                     city = :city,
                     state = :state,
                     zip_code = :zip_code,
                     notes = :notes,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($params);
            audit_event('updated', 'election_precinct', (string) $precinctId, ['name' => $params['name']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO election_precincts (name, location_name, street_address, city, state, zip_code, notes, is_active)
                 VALUES (:name, :location_name, :street_address, :city, :state, :zip_code, :notes, 1)'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $precinctId = (int) db()->lastInsertId();
            audit_event('created', 'election_precinct', (string) $precinctId, ['name' => $params['name']]);
        }

        flash('success', 'Precinct saved.');
        redirect_to('departments/election/setup.php');
    }

    if ($action === 'save_position') {
        $positionId = (int) ($_POST['position_id'] ?? 0);
        $params = [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'is_chief_judge' => isset($_POST['is_chief_judge']) ? 1 : 0,
            'is_assistant_chief_judge' => isset($_POST['is_assistant_chief_judge']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($positionId > 0) {
            $params['id'] = $positionId;
            $statement = db()->prepare(
                'UPDATE election_positions
                 SET name = :name, description = :description, sort_order = :sort_order,
                     is_chief_judge = :is_chief_judge, is_assistant_chief_judge = :is_assistant_chief_judge,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $statement->execute($params);
            audit_event('updated', 'election_position', (string) $positionId, ['name' => $params['name']]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO election_positions (name, description, sort_order, is_chief_judge, is_assistant_chief_judge, is_active)
                 VALUES (:name, :description, :sort_order, :is_chief_judge, :is_assistant_chief_judge, 1)'
            );
            unset($params['is_active']);
            $statement->execute($params);
            $positionId = (int) db()->lastInsertId();
            audit_event('created', 'election_position', (string) $positionId, ['name' => $params['name']]);
        }

        flash('success', 'Position saved.');
        redirect_to('departments/election/setup.php');
    }
}

$periods = db()->query('SELECT * FROM election_periods ORDER BY starts_on DESC, name')->fetchAll();
$precincts = election_precincts(false);
$positions = election_positions(false);

if ($editPeriodId > 0) {
    $statement = db()->prepare('SELECT * FROM election_periods WHERE id = :id');
    $statement->execute(['id' => $editPeriodId]);
    $editPeriod = $statement->fetch() ?: null;
}

if ($editPrecinctId > 0) {
    $statement = db()->prepare('SELECT * FROM election_precincts WHERE id = :id');
    $statement->execute(['id' => $editPrecinctId]);
    $editPrecinct = $statement->fetch() ?: null;
}

if ($editPositionId > 0) {
    $statement = db()->prepare('SELECT * FROM election_positions WHERE id = :id');
    $statement->execute(['id' => $editPositionId]);
    $editPosition = $statement->fetch() ?: null;
}

$actions = [
    ['label' => 'Election Home', 'href' => url('departments/election/index.php'), 'primary' => true],
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php')],
    ['label' => 'Workers', 'href' => url('departments/election/workers.php')],
    ['label' => 'Reuse past workers', 'href' => url('departments/election/reuse-workers.php')],
    ['label' => 'Training classes', 'href' => url('departments/election/classes.php')],
];

page_header('Election Setup');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Setup</h1>
        <p>Manage election periods, precincts, and worker positions.</p>
        <?php election_navigation('setup'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <details class="panel setup-section" style="margin-top: 18px;" <?= $editPeriod ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1><?= $editPeriod ? 'Edit Election Period' : 'Add Election Period' ?></h1>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_period">
            <input type="hidden" name="period_id" value="<?= e((string) ($editPeriod['id'] ?? 0)) ?>">
            <label>
                Election name
                <input name="name" value="<?= e($editPeriod['name'] ?? '') ?>" placeholder="2026 General Election" required>
            </label>
            <label>
                Starts on
                <input type="date" name="starts_on" value="<?= e($editPeriod['starts_on'] ?? date('Y-m-d')) ?>" required>
            </label>
            <label>
                Ends on
                <input type="date" name="ends_on" value="<?= e($editPeriod['ends_on'] ?? date('Y-m-d')) ?>" required>
            </label>
            <?php if ($editPeriod): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) $editPeriod['is_active'] === 1 ? 'checked' : '' ?>>
                    Active election
                </label>
            <?php endif; ?>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($editPeriod['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $editPeriod ? 'Save election' : 'Add election' ?></button>
                <?php if ($editPeriod): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/setup.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </details>

    <details class="panel setup-section" style="margin-top: 18px;">
        <summary class="section-heading-row">
            <h1>Election Periods</h1>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <table class="table mobile-card-table election-period-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($periods as $period): ?>
                    <tr>
                        <td data-label="Name"><?= e($period['name']) ?></td>
                        <td data-label="Dates"><?= e(format_display_date($period['starts_on'])) ?> to <?= e(format_display_date($period['ends_on'])) ?></td>
                        <td data-label="Status">
                            <span class="badge <?= (int) $period['is_active'] === 1 ? 'badge-success' : 'badge-muted' ?>">
                                <?= (int) $period['is_active'] === 1 ? 'Active' : 'Closed' ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="table-actions election-period-actions">
                                <a class="button secondary compact-button" href="<?= e(url('departments/election/setup.php?edit_period=' . $period['id'])) ?>">Edit</a>
                                <?php if ((int) $period['is_active'] === 1): ?>
                                    <a class="button secondary compact-button" href="<?= e(url('departments/election/close-period.php?id=' . (int) $period['id'])) ?>">Close election</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$periods): ?>
                    <tr><td colspan="4">No election periods have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </details>

    <details class="panel setup-section" style="margin-top: 18px;" <?= $editPrecinct ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1><?= $editPrecinct ? 'Edit Precinct' : 'Add Precinct' ?></h1>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_precinct">
            <input type="hidden" name="precinct_id" value="<?= e((string) ($editPrecinct['id'] ?? 0)) ?>">
            <label>
                Precinct name
                <input name="name" value="<?= e($editPrecinct['name'] ?? '') ?>" required>
            </label>
            <label>
                Location name
                <input name="location_name" value="<?= e($editPrecinct['location_name'] ?? '') ?>" placeholder="Elementary School" required>
            </label>
            <label>
                Street address
                <input name="street_address" value="<?= e($editPrecinct['street_address'] ?? ($editPrecinct['building_address'] ?? '')) ?>" required>
            </label>
            <label>
                City
                <input name="city" value="<?= e($editPrecinct['city'] ?? '') ?>" required>
            </label>
            <label>
                State
                <select name="state" required>
                    <?php state_options($editPrecinct['state'] ?? 'ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code" value="<?= e($editPrecinct['zip_code'] ?? '') ?>" required>
            </label>
            <?php if ($editPrecinct): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) $editPrecinct['is_active'] === 1 ? 'checked' : '' ?>>
                    Active precinct
                </label>
            <?php endif; ?>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($editPrecinct['notes'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $editPrecinct ? 'Save precinct' : 'Add precinct' ?></button>
                <?php if ($editPrecinct): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/setup.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </details>

    <details class="panel setup-section" style="margin-top: 18px;">
        <summary class="section-heading-row">
            <h1>Precincts</h1>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Precinct</th>
                    <th>Election location</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($precincts as $precinct): ?>
                    <tr>
                        <td data-label="Precinct"><?= e($precinct['name']) ?></td>
                        <td data-label="Election location">
                            <?= nl2br(e(election_precinct_location($precinct))) ?>
                        </td>
                        <td data-label="Status"><?= (int) $precinct['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                        <td data-label="Actions"><a class="button secondary compact-button" href="<?= e(url('departments/election/setup.php?edit_precinct=' . $precinct['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$precincts): ?>
                    <tr><td colspan="4">No precincts have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </details>

    <details class="panel setup-section" style="margin-top: 18px;" <?= $editPosition ? 'open' : '' ?>>
        <summary class="section-heading-row">
            <h1><?= $editPosition ? 'Edit Position' : 'Add Position' ?></h1>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <form class="form compact-form" method="post">
            <input type="hidden" name="action" value="save_position">
            <input type="hidden" name="position_id" value="<?= e((string) ($editPosition['id'] ?? 0)) ?>">
            <label>
                Position name
                <input name="name" value="<?= e($editPosition['name'] ?? '') ?>" required>
            </label>
            <label>
                Sort order
                <input type="number" name="sort_order" value="<?= e((string) ($editPosition['sort_order'] ?? 90)) ?>" min="0">
            </label>
            <label class="check-label">
                <input type="checkbox" name="is_chief_judge" <?= (int) ($editPosition['is_chief_judge'] ?? 0) === 1 ? 'checked' : '' ?>>
                Chief Judge permissions
            </label>
            <label class="check-label">
                <input type="checkbox" name="is_assistant_chief_judge" <?= (int) ($editPosition['is_assistant_chief_judge'] ?? 0) === 1 ? 'checked' : '' ?>>
                Assistant Chief Judge permissions
            </label>
            <?php if ($editPosition): ?>
                <label class="check-label">
                    <input type="checkbox" name="is_active" <?= (int) $editPosition['is_active'] === 1 ? 'checked' : '' ?>>
                    Active position
                </label>
            <?php endif; ?>
            <label class="span-2">
                Description
                <textarea name="description"><?= e($editPosition['description'] ?? '') ?></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit"><?= $editPosition ? 'Save position' : 'Add position' ?></button>
                <?php if ($editPosition): ?>
                    <a class="button secondary" href="<?= e(url('departments/election/setup.php')) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
        </form>
    </details>

    <details class="panel setup-section" style="margin-top: 18px;">
        <summary class="section-heading-row">
            <h1>Positions</h1>
            <span class="button secondary compact-button">View section</span>
        </summary>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Permissions</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($positions as $position): ?>
                    <tr>
                        <td data-label="Position"><?= e($position['name']) ?></td>
                        <td data-label="Permissions">
                            <?= (int) $position['is_chief_judge'] === 1 || (int) $position['is_assistant_chief_judge'] === 1 ? 'Chief Judge worker management' : 'Worker access' ?>
                        </td>
                        <td data-label="Status"><?= (int) $position['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                        <td data-label="Actions"><a class="button secondary compact-button" href="<?= e(url('departments/election/setup.php?edit_position=' . $position['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$positions): ?>
                    <tr><td colspan="4">No positions have been added yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </details>
</main>
<?php page_footer(); ?>
