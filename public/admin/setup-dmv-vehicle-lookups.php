<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

db()->exec(
    "CREATE TABLE IF NOT EXISTS dmv_vehicle_makes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

db()->exec(
    "CREATE TABLE IF NOT EXISTS dmv_vehicle_models (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        make_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dmv_model_make_name (make_id, name),
        CONSTRAINT fk_dmv_vehicle_models_make
            FOREIGN KEY (make_id) REFERENCES dmv_vehicle_makes(id)
            ON DELETE CASCADE
    )"
);

db()->exec(
    "CREATE TABLE IF NOT EXISTS dmv_vehicle_make_aliases (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        make_id INT UNSIGNED NOT NULL,
        alias VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_dmv_vehicle_make_aliases_make
            FOREIGN KEY (make_id) REFERENCES dmv_vehicle_makes(id)
            ON DELETE CASCADE
    )"
);

$columns = db()->query(
    "SELECT COLUMN_NAME
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'dmv_title_requests'"
)->fetchAll();

$existingColumns = [];
foreach ($columns as $column) {
    $existingColumns[$column['COLUMN_NAME']] = true;
}

if (empty($existingColumns['vehicle_make_id'])) {
    db()->exec('ALTER TABLE dmv_title_requests ADD COLUMN vehicle_make_id INT UNSIGNED NULL AFTER vehicle_year');
}

if (empty($existingColumns['vehicle_model_id'])) {
    db()->exec('ALTER TABLE dmv_title_requests ADD COLUMN vehicle_model_id INT UNSIGNED NULL AFTER vehicle_make_id');
}

$seedMakes = [
    'Chevrolet' => ['Colorado', 'Equinox', 'Impala', 'Malibu', 'Silverado', 'Suburban', 'Tahoe', 'Traverse', 'Other'],
    'Ford' => ['Bronco', 'Edge', 'Escape', 'Expedition', 'Explorer', 'F-150', 'F-250', 'F-350', 'Mustang', 'Ranger', 'Other'],
    'Dodge' => ['Challenger', 'Charger', 'Durango', 'Grand Caravan', 'Ram 1500', 'Ram 2500', 'Ram 3500', 'Other'],
    'GMC' => ['Acadia', 'Canyon', 'Sierra', 'Terrain', 'Yukon', 'Other'],
    'Honda' => ['Accord', 'Civic', 'CR-V', 'Odyssey', 'Pilot', 'Ridgeline', 'Other'],
    'Toyota' => ['4Runner', 'Camry', 'Corolla', 'Highlander', 'RAV4', 'Tacoma', 'Tundra', 'Other'],
    'Other' => ['Other'],
];

$makeStatement = db()->prepare('INSERT IGNORE INTO dmv_vehicle_makes (name) VALUES (:name)');
$modelStatement = db()->prepare('INSERT IGNORE INTO dmv_vehicle_models (make_id, name) VALUES (:make_id, :name)');
$aliasStatement = db()->prepare('INSERT IGNORE INTO dmv_vehicle_make_aliases (make_id, alias) VALUES (:make_id, :alias)');
$makeIdStatement = db()->prepare('SELECT id FROM dmv_vehicle_makes WHERE name = :name');

foreach ($seedMakes as $makeName => $models) {
    $makeStatement->execute(['name' => $makeName]);
    $makeIdStatement->execute(['name' => $makeName]);
    $makeId = (int) $makeIdStatement->fetchColumn();

    foreach ($models as $modelName) {
        $modelStatement->execute(['make_id' => $makeId, 'name' => $modelName]);
    }

    if ($makeName === 'Chevrolet') {
        foreach (['Chevy', 'Chev', 'Chev.', 'CHEV'] as $alias) {
            $aliasStatement->execute(['make_id' => $makeId, 'alias' => $alias]);
        }
    }
}

page_header('DMV vehicle lookups ready');
?>
<main class="shell">
    <section class="panel">
        <h1>DMV Vehicle Lookups Ready</h1>
        <p>The vehicle make, model, and alias tables have been created and seeded.</p>
        <a class="button" href="<?= e(url('departments/dmv/vehicle-lookups.php')) ?>">Manage vehicle lookups</a>
    </section>
</main>
<?php page_footer(); ?>
