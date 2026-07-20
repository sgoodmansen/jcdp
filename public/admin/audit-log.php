<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_system_admin();

$entityType = trim($_GET['entity_type'] ?? '');
$params = [];
$where = '';

if ($entityType !== '') {
    $where = 'WHERE audit_log.entity_type = :entity_type';
    $params['entity_type'] = $entityType;
}

$statement = db()->prepare(
    "SELECT
        audit_log.*,
        users.first_name,
        users.last_name,
        users.email
     FROM audit_log
     LEFT JOIN users ON users.id = audit_log.user_id
     $where
     ORDER BY audit_log.created_at DESC
     LIMIT 200"
);
$statement->execute($params);
$events = $statement->fetchAll();

$entityTypes = db()->query(
    'SELECT DISTINCT entity_type FROM audit_log ORDER BY entity_type'
)->fetchAll();

page_header('Audit Log');
?>
<main class="shell">
    <section class="panel">
        <h1>Audit Log</h1>
        <p>Review recent changes made to users, DMV title requests, lienholders, and request statuses.</p>

        <div class="actions" style="margin-bottom: 18px;">
            <a class="button secondary" href="<?= e(url('admin/users.php')) ?>">Manage users</a>
            <a class="button secondary" href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
        </div>

        <form class="form" method="get">
            <label>
                Filter by record type
                <select name="entity_type">
                    <option value="">All record types</option>
                    <?php foreach ($entityTypes as $type): ?>
                        <option value="<?= e($type['entity_type']) ?>" <?= $entityType === $type['entity_type'] ? 'selected' : '' ?>>
                            <?= e($type['entity_type']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions">
                <button type="submit">Apply filter</button>
                <a class="button secondary" href="<?= e(url('admin/audit-log.php')) ?>">Clear filter</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <table class="table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Record Type</th>
                    <th>Record ID</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <?php
                    $details = json_decode($event['details'] ?? '', true);
                    $detailText = is_array($details)
                        ? implode('; ', array_map(
                            fn($key, $value) => $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)),
                            array_keys($details),
                            $details
                        ))
                        : '';
                    ?>
                    <tr>
                        <td><?= e($event['created_at']) ?></td>
                        <td>
                            <?= e(trim(($event['first_name'] ?? '') . ' ' . ($event['last_name'] ?? '')) ?: 'System') ?>
                            <?php if (!empty($event['email'])): ?>
                                <br><span class="meta"><?= e($event['email']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($event['action']) ?></td>
                        <td><?= e($event['entity_type']) ?></td>
                        <td><?= e($event['entity_id']) ?></td>
                        <td><?= e($detailText) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$events): ?>
                    <tr>
                        <td colspan="6">No audit events found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
<?php page_footer(); ?>
