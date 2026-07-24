<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_manager('dmv');

function normalize_lookup_name(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_make') {
        $makeName = normalize_lookup_name($_POST['make_name'] ?? '');
        $statement = db()->prepare('SELECT id FROM dmv_vehicle_makes WHERE LOWER(name) = LOWER(:name)');
        $statement->execute(['name' => $makeName]);

        if ($statement->fetch()) {
            flash('error', 'That vehicle make already exists.');
        } else {
            $statement = db()->prepare('INSERT INTO dmv_vehicle_makes (name) VALUES (:name)');
            $statement->execute(['name' => $makeName]);
            flash('success', 'Vehicle make saved.');
        }
    }

    if ($action === 'add_model') {
        $makeId = (int) ($_POST['make_id'] ?? 0);
        $modelName = normalize_lookup_name($_POST['model_name'] ?? '');
        $statement = db()->prepare('SELECT id FROM dmv_vehicle_models WHERE make_id = :make_id AND LOWER(name) = LOWER(:name)');
        $statement->execute(['make_id' => $makeId, 'name' => $modelName]);

        if ($statement->fetch()) {
            flash('error', 'That model already exists for the selected make.');
        } else {
            $statement = db()->prepare('INSERT INTO dmv_vehicle_models (make_id, name) VALUES (:make_id, :name)');
            $statement->execute(['make_id' => $makeId, 'name' => $modelName]);
            flash('success', 'Vehicle model saved.');
        }
    }

    if ($action === 'add_alias') {
        $makeId = (int) ($_POST['make_id'] ?? 0);
        $alias = normalize_lookup_name($_POST['alias'] ?? '');
        $statement = db()->prepare('SELECT id FROM dmv_vehicle_make_aliases WHERE LOWER(alias) = LOWER(:alias)');
        $statement->execute(['alias' => $alias]);

        if ($statement->fetch()) {
            flash('error', 'That alias already exists.');
        } else {
            $statement = db()->prepare('INSERT INTO dmv_vehicle_make_aliases (make_id, alias) VALUES (:make_id, :alias)');
            $statement->execute(['make_id' => $makeId, 'alias' => $alias]);
            flash('success', 'Vehicle make alias saved.');
        }
    }

    if ($action === 'update_make') {
        $makeId = (int) ($_POST['make_id'] ?? 0);
        $makeName = normalize_lookup_name($_POST['make_name'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $statement = db()->prepare('SELECT id FROM dmv_vehicle_makes WHERE LOWER(name) = LOWER(:name) AND id <> :id');
        $statement->execute(['name' => $makeName, 'id' => $makeId]);

        if ($statement->fetch()) {
            flash('error', 'Another make already uses that name.');
        } else {
            $statement = db()->prepare('UPDATE dmv_vehicle_makes SET name = :name, is_active = :is_active WHERE id = :id');
            $statement->execute(['name' => $makeName, 'is_active' => $isActive, 'id' => $makeId]);
            flash('success', 'Vehicle make updated.');
        }
    }

    if ($action === 'update_model') {
        $modelId = (int) ($_POST['model_id'] ?? 0);
        $makeId = (int) ($_POST['make_id'] ?? 0);
        $modelName = normalize_lookup_name($_POST['model_name'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $statement = db()->prepare(
            'SELECT id FROM dmv_vehicle_models WHERE make_id = :make_id AND LOWER(name) = LOWER(:name) AND id <> :id'
        );
        $statement->execute(['make_id' => $makeId, 'name' => $modelName, 'id' => $modelId]);

        if ($statement->fetch()) {
            flash('error', 'Another model already uses that name for the selected make.');
        } else {
            $statement = db()->prepare(
                'UPDATE dmv_vehicle_models SET make_id = :make_id, name = :name, is_active = :is_active WHERE id = :id'
            );
            $statement->execute([
                'make_id' => $makeId,
                'name' => $modelName,
                'is_active' => $isActive,
                'id' => $modelId,
            ]);
            flash('success', 'Vehicle model updated.');
        }
    }

    redirect_to('departments/dmv/vehicle-lookups.php');
}

$makes = db()->query('SELECT * FROM dmv_vehicle_makes ORDER BY name')->fetchAll();
$models = db()->query(
    'SELECT dmv_vehicle_models.*, dmv_vehicle_makes.name AS make_name
     FROM dmv_vehicle_models
     INNER JOIN dmv_vehicle_makes ON dmv_vehicle_makes.id = dmv_vehicle_models.make_id
     ORDER BY dmv_vehicle_makes.name, dmv_vehicle_models.name'
)->fetchAll();
$aliases = db()->query(
    'SELECT dmv_vehicle_make_aliases.*, dmv_vehicle_makes.name AS make_name
     FROM dmv_vehicle_make_aliases
     INNER JOIN dmv_vehicle_makes ON dmv_vehicle_makes.id = dmv_vehicle_make_aliases.make_id
     ORDER BY dmv_vehicle_makes.name, dmv_vehicle_make_aliases.alias'
)->fetchAll();
$actions = [
    ['label' => 'DMV home', 'href' => url('departments/dmv/index.php'), 'primary' => true],
    ['label' => 'Title requests', 'href' => url('departments/dmv/title-requests.php')],
    ['label' => 'New title request', 'href' => url('departments/dmv/title-request-create.php')],
    ['label' => 'Lienholders', 'href' => url('departments/dmv/lienholders.php')],
];

page_header('Vehicle Lookups');
?>
<main class="shell">
    <section class="panel">
        <h1>Vehicle Lookups</h1>
        <p>Manage standardized vehicle makes, models, and aliases for DMV title request entries.</p>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php page_actions($actions); ?>
    </section>

    <section class="grid" style="margin-top: 18px;">
        <article class="card">
            <h2>Add Make</h2>
            <form class="form" method="post">
                <input type="hidden" name="action" value="add_make">
                <label>
                    Make name
                    <input name="make_name" required>
                </label>
                <button type="submit">Save make</button>
            </form>
        </article>

        <article class="card">
            <h2>Add Model</h2>
            <form class="form" method="post">
                <input type="hidden" name="action" value="add_model">
                <label>
                    Make
                    <select name="make_id" required>
                        <option value="">Select make</option>
                        <?php foreach ($makes as $make): ?>
                            <option value="<?= e((string) $make['id']) ?>"><?= e($make['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Model name
                    <input name="model_name" required>
                </label>
                <button type="submit">Save model</button>
            </form>
        </article>

        <article class="card">
            <h2>Add Alias</h2>
            <form class="form" method="post">
                <input type="hidden" name="action" value="add_alias">
                <label>
                    Official make
                    <select name="make_id" required>
                        <option value="">Select make</option>
                        <?php foreach ($makes as $make): ?>
                            <option value="<?= e((string) $make['id']) ?>"><?= e($make['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Alias
                    <input name="alias" placeholder="Chevy">
                </label>
                <button type="submit">Save alias</button>
            </form>
        </article>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Vehicle Makes</h1>
        <table class="table">
            <thead>
                <tr>
                    <th>Make</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($makes as $make): ?>
                    <tr>
                        <td>
                            <form id="make-<?= e((string) $make['id']) ?>" method="post" class="inline-edit-form">
                                <input type="hidden" name="action" value="update_make">
                                <input type="hidden" name="make_id" value="<?= e((string) $make['id']) ?>">
                                <input name="make_name" value="<?= e($make['name']) ?>" required>
                            </form>
                        </td>
                        <td>
                            <label class="check-label" form="make-<?= e((string) $make['id']) ?>">
                                <input type="checkbox" name="is_active" form="make-<?= e((string) $make['id']) ?>" <?= $make['is_active'] ? 'checked' : '' ?>>
                                Active
                            </label>
                        </td>
                        <td>
                            <button type="submit" form="make-<?= e((string) $make['id']) ?>">Save</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$makes): ?>
                    <tr>
                        <td colspan="3">No makes have been added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Vehicle Models</h1>
        <table class="table">
            <thead>
                <tr>
                    <th>Make</th>
                    <th>Model</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($models as $model): ?>
                    <tr>
                        <td>
                            <form id="model-<?= e((string) $model['id']) ?>" method="post" class="inline-edit-form">
                                <input type="hidden" name="action" value="update_model">
                                <input type="hidden" name="model_id" value="<?= e((string) $model['id']) ?>">
                                <select name="make_id" required>
                                    <?php foreach ($makes as $make): ?>
                                        <option value="<?= e((string) $make['id']) ?>" <?= (int) $model['make_id'] === (int) $make['id'] ? 'selected' : '' ?>>
                                            <?= e($make['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <input name="model_name" form="model-<?= e((string) $model['id']) ?>" value="<?= e($model['name']) ?>" required>
                        </td>
                        <td>
                            <label class="check-label" form="model-<?= e((string) $model['id']) ?>">
                                <input type="checkbox" name="is_active" form="model-<?= e((string) $model['id']) ?>" <?= $model['is_active'] ? 'checked' : '' ?>>
                                Active
                            </label>
                        </td>
                        <td>
                            <button type="submit" form="model-<?= e((string) $model['id']) ?>">Save</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$models): ?>
                    <tr>
                        <td colspan="4">No models have been added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Make Aliases</h1>
        <table class="table">
            <thead>
                <tr>
                    <th>Alias</th>
                    <th>Official Make</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aliases as $alias): ?>
                    <tr>
                        <td><?= e($alias['alias']) ?></td>
                        <td><?= e($alias['make_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$aliases): ?>
                    <tr>
                        <td colspan="2">No aliases have been added yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
