<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

header('Content-Type: application/json');

$makeId = (int) ($_GET['make_id'] ?? 0);
$statement = db()->prepare(
    'SELECT id, name
     FROM dmv_vehicle_models
     WHERE make_id = :make_id AND is_active = 1
     ORDER BY name'
);
$statement->execute(['make_id' => $makeId]);

echo json_encode($statement->fetchAll());
