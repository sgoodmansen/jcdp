<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_manager('dmv');

function lienholder_label(array $lienholder): string
{
    $status = (int) $lienholder['is_active'] === 1 ? '' : ' (Inactive)';
    return $lienholder['company_name'] . ' - ' . $lienholder['city'] . ', ' . $lienholder['state'] . $status;
}

function fetch_lienholder(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM dmv_lienholders WHERE id = :id');
    $statement->execute(['id' => $id]);
    $lienholder = $statement->fetch();

    return $lienholder ?: null;
}

function count_lienholder_requests(int $id): int
{
    $statement = db()->prepare('SELECT COUNT(*) FROM dmv_title_requests WHERE lienholder_id = :id');
    $statement->execute(['id' => $id]);

    return (int) $statement->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceId = (int) ($_POST['source_id'] ?? 0);
    $destinationId = (int) ($_POST['destination_id'] ?? 0);

    if ($sourceId <= 0 || $destinationId <= 0 || $sourceId === $destinationId) {
        flash('error', 'Choose two different lienholders before merging.');
        redirect_to('departments/dmv/lienholder-merge.php');
    }

    $source = fetch_lienholder($sourceId);
    $destination = fetch_lienholder($destinationId);

    if (!$source || !$destination) {
        flash('error', 'One of the selected lienholders could not be found.');
        redirect_to('departments/dmv/lienholder-merge.php');
    }

    if ((int) $destination['is_active'] !== 1) {
        flash('error', 'The lienholder you keep must be active.');
        redirect_to('departments/dmv/lienholder-merge.php?source_id=' . $sourceId);
    }

    $requestCount = count_lienholder_requests($sourceId);

    db()->beginTransaction();

    $statement = db()->prepare(
        'UPDATE dmv_title_requests
         SET lienholder_id = :destination_id
         WHERE lienholder_id = :source_id'
    );
    $statement->execute([
        'destination_id' => $destinationId,
        'source_id' => $sourceId,
    ]);

    $statement = db()->prepare(
        'UPDATE dmv_lienholders
         SET is_active = 0,
             notes = TRIM(CONCAT(
                COALESCE(notes, ""),
                CASE WHEN COALESCE(notes, "") = "" THEN "" ELSE "\n\n" END,
                :merge_note
             ))
         WHERE id = :source_id'
    );
    $statement->execute([
        'source_id' => $sourceId,
        'merge_note' => 'Merged into lienholder #' . $destinationId . ' on ' . date('Y-m-d') . '.',
    ]);

    audit_event('merged', 'dmv_lienholder', (string) $destinationId, [
        'source_lienholder_id' => $sourceId,
        'source_company_name' => $source['company_name'],
        'destination_lienholder_id' => $destinationId,
        'destination_company_name' => $destination['company_name'],
        'title_requests_moved' => $requestCount,
    ]);

    db()->commit();

    flash('success', 'Lienholders merged. ' . $requestCount . ' title request' . ($requestCount === 1 ? '' : 's') . ' moved.');
    redirect_to('departments/dmv/lienholders.php?status=all');
}

$sourceId = (int) ($_GET['source_id'] ?? 0);
$destinationId = (int) ($_GET['destination_id'] ?? 0);

$lienholders = db()->query(
    'SELECT *
     FROM dmv_lienholders
     ORDER BY company_name, city, state'
)->fetchAll();
$activeLienholders = array_values(array_filter($lienholders, fn ($lienholder) => (int) $lienholder['is_active'] === 1));

$source = $sourceId > 0 ? fetch_lienholder($sourceId) : null;
$destination = $destinationId > 0 ? fetch_lienholder($destinationId) : null;
$sourceRequestCount = $source ? count_lienholder_requests((int) $source['id']) : 0;
$destinationRequestCount = $destination ? count_lienholder_requests((int) $destination['id']) : 0;
page_header('Merge Lienholders');
?>
<main class="shell">
    <section class="panel">
        <h1>Merge Lienholders</h1>
        <p>Move title requests from a duplicate lienholder to the correct lienholder, then mark the duplicate inactive.</p>

        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <?php dmv_navigation('merge-lienholders'); ?>

        <form class="form compact-form wide-form" method="get">
            <label>
                Duplicate lienholder to merge
                <select name="source_id" required>
                    <option value="">Select duplicate</option>
                    <?php foreach ($activeLienholders as $lienholder): ?>
                        <option value="<?= e((string) $lienholder['id']) ?>" <?= $sourceId === (int) $lienholder['id'] ? 'selected' : '' ?>>
                            #<?= e((string) $lienholder['id']) ?> <?= e(lienholder_label($lienholder)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Correct lienholder to keep
                <select name="destination_id" required>
                    <option value="">Select keeper</option>
                    <?php foreach ($lienholders as $lienholder): ?>
                        <option value="<?= e((string) $lienholder['id']) ?>" <?= $destinationId === (int) $lienholder['id'] ? 'selected' : '' ?>>
                            #<?= e((string) $lienholder['id']) ?> <?= e(lienholder_label($lienholder)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions span-2">
                <button type="submit">Preview merge</button>
            </div>
        </form>
    </section>

    <?php if ($source && $destination): ?>
        <section class="detail-grid" style="margin-top: 18px;">
            <article class="panel detail-panel">
                <h2>Duplicate</h2>
                <dl class="detail-list">
                    <dt>Name</dt>
                    <dd><?= e($source['company_name']) ?></dd>
                    <dt>Address</dt>
                    <dd><?= e($source['mailing_address']) ?><br><?= e($source['city']) ?>, <?= e($source['state']) ?> <?= e($source['zip_code']) ?></dd>
                    <dt>Requests</dt>
                    <dd><?= e((string) $sourceRequestCount) ?></dd>
                    <dt>Result</dt>
                    <dd>Will be marked inactive.</dd>
                </dl>
            </article>

            <article class="panel detail-panel">
                <h2>Keeper</h2>
                <dl class="detail-list">
                    <dt>Name</dt>
                    <dd><?= e($destination['company_name']) ?></dd>
                    <dt>Address</dt>
                    <dd><?= e($destination['mailing_address']) ?><br><?= e($destination['city']) ?>, <?= e($destination['state']) ?> <?= e($destination['zip_code']) ?></dd>
                    <dt>Requests</dt>
                    <dd><?= e((string) $destinationRequestCount) ?> now, <?= e((string) ($destinationRequestCount + $sourceRequestCount)) ?> after merge</dd>
                    <dt>Result</dt>
                    <dd>Will receive the duplicate's title requests.</dd>
                </dl>
            </article>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <?php if ((int) $source['id'] === (int) $destination['id']): ?>
                <div class="notice error">The duplicate and keeper must be different lienholders.</div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="source_id" value="<?= e((string) $source['id']) ?>">
                    <input type="hidden" name="destination_id" value="<?= e((string) $destination['id']) ?>">
                    <p>This will move <?= e((string) $sourceRequestCount) ?> title request<?= $sourceRequestCount === 1 ? '' : 's' ?> to the keeper and mark the duplicate inactive.</p>
                    <div class="actions">
                        <button type="submit">Merge lienholders</button>
                        <a class="button secondary" href="<?= e(url('departments/dmv/lienholder-merge.php')) ?>">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
