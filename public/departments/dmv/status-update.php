<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('departments/dmv/title-requests.php');
}

$id = (int) ($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$returnTo = $_POST['return_to'] ?? 'letter';
$allowedStatuses = ['draft', 'sent', 'received', 'closed'];

if (!in_array($status, $allowedStatuses, true)) {
    flash('error', 'The selected status is not valid.');
    redirect_to($returnTo === 'detail' ? 'departments/dmv/title-request-detail.php?id=' . $id : 'departments/dmv/letter.php?id=' . $id);
}

$currentStatusStatement = db()->prepare('SELECT status FROM dmv_title_requests WHERE id = :id');
$currentStatusStatement->execute(['id' => $id]);
$previousStatus = (string) $currentStatusStatement->fetchColumn();

$statement = db()->prepare(
    'UPDATE dmv_title_requests
     SET status = :status
     WHERE id = :id'
);
$statement->execute([
    'status' => $status,
    'id' => $id,
]);

audit_event('status_changed', 'dmv_title_request', (string) $id, [
    'previous_status' => $previousStatus,
    'new_status' => $status,
]);

flash('success', 'Title request status updated.');
redirect_to($returnTo === 'detail' ? 'departments/dmv/title-request-detail.php?id=' . $id : 'departments/dmv/letter.php?id=' . $id);
