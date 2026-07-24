<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

header('Content-Type: application/json');

$vin = normalize_vin($_GET['vin'] ?? '');
$currentId = (int) ($_GET['current_id'] ?? 0);

if ($vin === '') {
    echo json_encode(['duplicate' => false]);
    exit;
}

$statement = db()->prepare(
    'SELECT id, registrant_name, status, request_date
     FROM dmv_title_requests
     WHERE vin = :vin
       AND id <> :current_id
     ORDER BY request_date DESC, id DESC
     LIMIT 1'
);
$statement->execute([
    'vin' => $vin,
    'current_id' => $currentId,
]);
$match = $statement->fetch();

if (!$match) {
    echo json_encode(['duplicate' => false]);
    exit;
}

echo json_encode([
    'duplicate' => true,
    'request_id' => (int) $match['id'],
    'registrant_name' => $match['registrant_name'],
    'status' => ucfirst($match['status']),
    'request_date' => $match['request_date'],
]);
