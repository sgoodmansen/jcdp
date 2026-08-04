<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_worker_manager();
election_require_assignment_setup();

const ELECTION_WORKER_IMPORT_SESSION_KEY = 'election_worker_import_preview';
const ELECTION_WORKER_IMPORT_RESULT_SESSION_KEY = 'election_worker_import_result';
const ELECTION_WORKER_IMPORT_LIMIT = 500;

function election_import_column_index(string $cellReference): int
{
    preg_match('/^[A-Z]+/i', $cellReference, $matches);
    $letters = strtoupper($matches[0] ?? '');
    $index = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return max(0, $index - 1);
}

function election_import_header_key(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
}

function election_import_value(array $row, array $headerMap, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($headerMap[$key])) {
            return trim((string) ($row[$headerMap[$key]] ?? ''));
        }
    }

    return '';
}

function election_import_split_name(string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
        return ['', ''];
    }

    if (str_contains($name, ',')) {
        [$lastName, $firstName] = array_pad(array_map('trim', explode(',', $name, 2)), 2, '');
        return [$firstName, $lastName];
    }

    $parts = explode(' ', $name);
    if (count($parts) === 1) {
        return [$parts[0], ''];
    }

    $lastName = array_pop($parts);
    return [implode(' ', $parts), $lastName];
}

function election_import_contact_from_row(array $row, array $headerMap): array
{
    $firstName = election_import_value($row, $headerMap, ['firstname', 'first']);
    $lastName = election_import_value($row, $headerMap, ['lastname', 'last', 'surname']);

    if ($firstName === '' || $lastName === '') {
        [$splitFirstName, $splitLastName] = election_import_split_name(
            election_import_value($row, $headerMap, ['fullname', 'name', 'workername'])
        );
        $firstName = $firstName !== '' ? $firstName : $splitFirstName;
        $lastName = $lastName !== '' ? $lastName : $splitLastName;
    }

    $state = strtoupper(election_import_value($row, $headerMap, ['state']));

    return [
        'first_name' => preserve_name_case($firstName),
        'last_name' => preserve_name_case($lastName),
        'email' => trim(election_import_value($row, $headerMap, ['email', 'emailaddress', 'eemail', 'mail'])),
        'phone' => election_normalize_phone(election_import_value($row, $headerMap, ['phone', 'phonenumber', 'mobile', 'cell', 'cellphone', 'telephone'])),
        'mailing_address' => title_case_address(election_import_value($row, $headerMap, ['mailingaddress', 'address', 'streetaddress', 'street'])),
        'city' => title_case_name(election_import_value($row, $headerMap, ['city'])),
        'state' => $state,
        'zip_code' => trim(election_import_value($row, $headerMap, ['zip', 'zipcode', 'postalcode', 'postal'])),
    ];
}

function election_import_preview_row(array $contact, int $rowNumber): array
{
    $contact['email_normalized'] = election_normalized_email($contact['email']);
    $contact['phone_digits'] = election_phone_digits($contact['phone']);
    $contact['name_key'] = election_worker_name_key($contact['first_name'], $contact['last_name']);

    $preview = [
        'row_number' => $rowNumber,
        'contact' => $contact,
        'status' => 'new',
        'status_label' => 'Create new worker',
        'match_worker_id' => null,
        'match_name' => '',
        'message' => '',
    ];

    if ($contact['first_name'] === '' || $contact['last_name'] === '') {
        $preview['status'] = 'skip';
        $preview['status_label'] = 'Skipped';
        $preview['message'] = 'Missing first or last name.';
        return $preview;
    }

    $matches = election_find_possible_worker_matches($contact);
    if (!$matches) {
        return $preview;
    }

    $exactMatches = [];
    foreach ($matches as $match) {
        $emailMatches = $contact['email_normalized'] !== null
            && $contact['email_normalized'] === ($match['email_normalized'] ?? null);
        $phoneMatches = $contact['phone_digits'] !== null
            && $contact['phone_digits'] === ($match['phone_digits'] ?? null);

        if ($emailMatches || $phoneMatches) {
            $exactMatches[] = $match;
        }
    }

    if (count($exactMatches) === 1) {
        $preview['status'] = 'update';
        $preview['status_label'] = 'Matched existing worker';
        $preview['match_worker_id'] = (int) $exactMatches[0]['id'];
        $preview['match_name'] = election_person_name($exactMatches[0]);
        $preview['message'] = 'Matched by email or phone.';
        return $preview;
    }

    $preview['status'] = 'review';
    $preview['status_label'] = 'Review manually';
    $preview['match_name'] = implode(', ', array_map('election_person_name', $matches));
    $preview['message'] = 'Possible duplicate. This row will not import until reviewed.';

    return $preview;
}

function election_import_preview_from_rows(array $headers, array $rows): array
{
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
    }

    $headerMap = [];
    foreach ($headers as $index => $header) {
        $key = election_import_header_key((string) $header);
        if ($key !== '') {
            $headerMap[$key] = $index;
        }
    }

    $previewRows = [];
    foreach ($rows as $rowNumber => $row) {
        if (count(array_filter($row, fn($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }

        if (count($previewRows) >= ELECTION_WORKER_IMPORT_LIMIT) {
            throw new RuntimeException('The importer can preview up to ' . ELECTION_WORKER_IMPORT_LIMIT . ' rows at a time.');
        }

        $previewRows[] = election_import_preview_row(election_import_contact_from_row($row, $headerMap), $rowNumber);
    }

    if (!$previewRows) {
        throw new RuntimeException('No worker rows were found in the file.');
    }

    return $previewRows;
}

function election_import_parse_csv(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('The CSV file could not be opened.');
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        throw new RuntimeException('The CSV file does not contain a header row.');
    }

    $rows = [];
    $rowNumber = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        $rows[$rowNumber] = $row;
    }

    fclose($handle);

    return election_import_preview_from_rows($headers, $rows);
}

function election_import_parse_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel upload is not available because ZIP support is missing in PHP.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The Excel file could not be opened.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        if ($shared) {
            foreach ($shared->si as $item) {
                $text = '';
                if (isset($item->t)) {
                    $text = (string) $item->t;
                } else {
                    foreach ($item->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $sheetPath = null;

    if ($workbookXml !== false && $relsXml !== false) {
        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        $targets = [];

        if ($workbook && $rels) {
            foreach ($rels->Relationship as $relationship) {
                $attributes = $relationship->attributes();
                $target = (string) $attributes['Target'];
                $targets[(string) $attributes['Id']] = str_starts_with($target, '/')
                    ? ltrim($target, '/')
                    : 'xl/' . ltrim($target, '/');
            }

            foreach ($workbook->sheets->sheet as $sheet) {
                $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relationshipId = (string) $relationshipAttributes['id'];
                if (isset($targets[$relationshipId])) {
                    $sheetPath = $targets[$relationshipId];
                    break;
                }
            }
        }
    }

    if ($sheetPath === null) {
        $sheetPath = 'xl/worksheets/sheet1.xml';
    }

    $xml = $zip->getFromName($sheetPath);
    if ($xml === false) {
        $zip->close();
        throw new RuntimeException('The first worksheet could not be read.');
    }

    $sheet = simplexml_load_string($xml);
    if (!$sheet || !isset($sheet->sheetData)) {
        $zip->close();
        throw new RuntimeException('The first worksheet does not contain readable rows.');
    }

    $sheetRows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowAttributes = $row->attributes();
        $rowNumber = (int) ($rowAttributes['r'] ?? 0);
        $values = [];

        foreach ($row->c as $cell) {
            $cellAttributes = $cell->attributes();
            $type = (string) ($cellAttributes['t'] ?? '');
            $value = isset($cell->v) ? trim((string) $cell->v) : '';

            if ($type === 's') {
                $value = trim($sharedStrings[(int) $value] ?? '');
            } elseif ($type === 'inlineStr') {
                $value = trim((string) ($cell->is->t ?? ''));
            } elseif ($type === 'b') {
                $value = $value === '1' ? 'Yes' : 'No';
            }

            $values[election_import_column_index((string) $cellAttributes['r'])] = $value;
        }

        if ($values) {
            ksort($values);
            $sheetRows[$rowNumber > 0 ? $rowNumber : count($sheetRows) + 1] = $values;
        }
    }

    $zip->close();

    if (!$sheetRows) {
        throw new RuntimeException('The Excel file does not contain any rows.');
    }

    $firstRowNumber = min(array_keys($sheetRows));
    $headers = $sheetRows[$firstRowNumber];
    unset($sheetRows[$firstRowNumber]);

    return election_import_preview_from_rows($headers, $sheetRows);
}

function election_import_parse_uploaded_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a CSV or Excel file before previewing the import.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === 'xlsx') {
        return election_import_parse_xlsx($file['tmp_name']);
    }

    if ($extension === 'csv' || $extension === '') {
        return election_import_parse_csv($file['tmp_name']);
    }

    throw new RuntimeException('Upload a .xlsx or .csv file. Older .xls files should be saved as .xlsx first.');
}

function election_import_fill_missing_expression(string $column): string
{
    return "{$column} = CASE WHEN {$column} IS NULL OR {$column} = '' THEN :{$column} ELSE {$column} END";
}

function election_import_save_rows(array $previewRows): array
{
    $counts = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'review' => 0,
    ];

    db()->beginTransaction();

    try {
        foreach ($previewRows as $preview) {
            $contact = $preview['contact'];
            if ($preview['status'] === 'new') {
                $statement = db()->prepare(
                    'INSERT INTO election_workers (
                        election_period_id, precinct_id, position_id, recruited_by_worker_id, created_by_user_id,
                        first_name, last_name, email, email_normalized, phone, phone_digits, name_key,
                        mailing_address, city, state, zip_code,
                        wants_email_reminders, wants_text_reminders, availability_status, unavailable_reason, is_active, notes
                     )
                     VALUES (
                        NULL, NULL, NULL, NULL, :created_by_user_id,
                        :first_name, :last_name, :email, :email_normalized, :phone, :phone_digits, :name_key,
                        :mailing_address, :city, :state, :zip_code,
                        0, 0, :availability_status, "", 1, NULL
                     )'
                );
                $statement->execute([
                    'created_by_user_id' => current_user()['id'] ?? null,
                    'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'email' => $contact['email'],
                    'email_normalized' => $contact['email_normalized'],
                    'phone' => $contact['phone'],
                    'phone_digits' => $contact['phone_digits'],
                    'name_key' => $contact['name_key'],
                    'mailing_address' => $contact['mailing_address'],
                    'city' => $contact['city'],
                    'state' => $contact['state'],
                    'zip_code' => $contact['zip_code'],
                ]);
                $counts['created']++;
            } elseif ($preview['status'] === 'update' && (int) ($preview['match_worker_id'] ?? 0) > 0) {
                $statement = db()->prepare(
                    'UPDATE election_workers
                     SET ' . implode(', ', [
                         election_import_fill_missing_expression('email'),
                         'email_normalized = COALESCE(email_normalized, :email_normalized)',
                         election_import_fill_missing_expression('phone'),
                         'phone_digits = COALESCE(phone_digits, :phone_digits)',
                         election_import_fill_missing_expression('mailing_address'),
                         election_import_fill_missing_expression('city'),
                         election_import_fill_missing_expression('state'),
                         election_import_fill_missing_expression('zip_code'),
                         'name_key = COALESCE(name_key, :name_key)',
                         'availability_status = CASE WHEN availability_status = "unavailable" THEN availability_status ELSE :availability_status END',
                         'unavailable_reason = CASE WHEN availability_status = "unavailable" THEN unavailable_reason ELSE "" END',
                         'is_active = CASE WHEN availability_status = "unavailable" THEN is_active ELSE 1 END',
                     ]) . '
                     WHERE id = :id'
                );
                $statement->execute([
                    'id' => (int) $preview['match_worker_id'],
                    'availability_status' => ELECTION_WORKER_STATUS_ACTIVE,
                    'email' => $contact['email'],
                    'email_normalized' => $contact['email_normalized'],
                    'phone' => $contact['phone'],
                    'phone_digits' => $contact['phone_digits'],
                    'name_key' => $contact['name_key'],
                    'mailing_address' => $contact['mailing_address'],
                    'city' => $contact['city'],
                    'state' => $contact['state'],
                    'zip_code' => $contact['zip_code'],
                ]);
                $counts['updated']++;
            } elseif ($preview['status'] === 'review') {
                $counts['review']++;
            } else {
                $counts['skipped']++;
            }
        }

        audit_event('imported_workers_csv', 'election_worker', 'csv', $counts);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return $counts;
}

$previewRows = $_SESSION[ELECTION_WORKER_IMPORT_SESSION_KEY] ?? [];
$counts = [
    'new' => 0,
    'update' => 0,
    'review' => 0,
    'skip' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'preview') {
            $previewRows = election_import_parse_uploaded_file($_FILES['csv_file'] ?? []);
            $_SESSION[ELECTION_WORKER_IMPORT_SESSION_KEY] = $previewRows;
            unset($_SESSION[ELECTION_WORKER_IMPORT_RESULT_SESSION_KEY]);
            flash('success', 'Import preview is ready. Review the rows below, then import when ready.');
            redirect_to('departments/election/import-workers.php');
        } elseif ($action === 'import') {
            if (!$previewRows) {
                throw new RuntimeException('Preview a CSV file before importing.');
            }

            $importCounts = election_import_save_rows($previewRows);
            unset($_SESSION[ELECTION_WORKER_IMPORT_SESSION_KEY]);
            $_SESSION[ELECTION_WORKER_IMPORT_RESULT_SESSION_KEY] = $importCounts;
            flash(
                'success',
                'Import complete. Created ' . $importCounts['created']
                . ' new worker contact' . ($importCounts['created'] === 1 ? '' : 's')
                . ', matched ' . $importCounts['updated']
                . ' existing worker contact' . ($importCounts['updated'] === 1 ? '' : 's')
                . ', left ' . $importCounts['review']
                . ' possible duplicate' . ($importCounts['review'] === 1 ? '' : 's') . ' for review'
                . ', and skipped ' . $importCounts['skipped']
                . ' row' . ($importCounts['skipped'] === 1 ? '' : 's') . '.'
            );
            redirect_to('departments/election/import-workers.php');
        } elseif ($action === 'clear') {
            unset($_SESSION[ELECTION_WORKER_IMPORT_SESSION_KEY]);
            unset($_SESSION[ELECTION_WORKER_IMPORT_RESULT_SESSION_KEY]);
            flash('success', 'Import preview cleared.');
            redirect_to('departments/election/import-workers.php');
        }
    } catch (Throwable $exception) {
        flash('error', $exception->getMessage());
        redirect_to('departments/election/import-workers.php');
    }
}

foreach ($previewRows as $preview) {
    if (isset($counts[$preview['status']])) {
        $counts[$preview['status']]++;
    }
}

$lastImportResult = $_SESSION[ELECTION_WORKER_IMPORT_RESULT_SESSION_KEY] ?? null;
unset($_SESSION[ELECTION_WORKER_IMPORT_RESULT_SESSION_KEY]);

$actions = [
    ['label' => 'Address Book', 'href' => url('departments/election/workers.php'), 'primary' => true],
    ['label' => 'Add contact', 'href' => url('departments/election/worker-edit.php')],
    ['label' => 'Precinct Staffing', 'href' => url('departments/election/staffing.php')],
    ['label' => 'Election Home', 'href' => url('departments/election/index.php')],
];

page_header('Import Election Contacts');
?>
<main class="shell">
    <section class="panel">
        <h1>Import Election Contacts</h1>
        <p>Upload an Excel or CSV file to add names to the contact address book. Assignments are handled separately on Precinct Staffing.</p>
        <?php election_navigation('import-workers'); ?>

        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>
    </section>

    <?php if (is_array($lastImportResult)): ?>
        <section class="dashboard-stat-row election-home-stat-row" style="margin-top: 18px;">
            <div class="dashboard-stat-group summary-stat-group">
                <h2>Last Import Result</h2>
                <div class="grid dashboard-stat-grid election-home-stat-grid">
                    <article class="card dashboard-stat-card">
                        <h3><?= e((string) (int) ($lastImportResult['created'] ?? 0)) ?></h3>
                        <p>New contacts created</p>
                    </article>
                    <article class="card dashboard-stat-card">
                        <h3><?= e((string) (int) ($lastImportResult['updated'] ?? 0)) ?></h3>
                        <p>Existing contacts matched</p>
                    </article>
                    <article class="card dashboard-stat-card">
                        <h3><?= e((string) (int) ($lastImportResult['review'] ?? 0)) ?></h3>
                        <p>Possible duplicates not imported</p>
                    </article>
                    <article class="card dashboard-stat-card">
                        <h3><?= e((string) (int) ($lastImportResult['skipped'] ?? 0)) ?></h3>
                        <p>Rows skipped</p>
                    </article>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel" style="margin-top: 18px;">
        <h1>Upload File</h1>
        <p>Accepted columns: First Name, Last Name, Name, Email, Phone, Mailing Address, City, State, Zip.</p>
        <form class="form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">
            <label>
                Excel or CSV file
                <input type="file" name="csv_file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </label>
            <div class="actions">
                <button type="submit">Preview File</button>
            </div>
        </form>
    </section>

    <?php if ($previewRows): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="section-heading-row">
                <h1>Preview</h1>
                <div class="badge-group">
                    <span class="badge badge-success"><?= e((string) $counts['new']) ?> create new</span>
                    <span class="badge"><?= e((string) $counts['update']) ?> matched existing</span>
                    <span class="badge badge-warning"><?= e((string) $counts['review']) ?> possible duplicates</span>
                    <span class="badge badge-muted"><?= e((string) $counts['skip']) ?> skipped</span>
                </div>
            </div>
            <form method="post" class="actions" style="margin-bottom: 12px;">
                <button type="submit" name="action" value="import">Import ready rows</button>
                <button type="submit" name="action" value="clear" class="secondary">Clear preview</button>
            </form>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th>Match</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $preview): ?>
                        <?php $contact = $preview['contact']; ?>
                        <tr>
                            <td data-label="Row"><?= e((string) $preview['row_number']) ?></td>
                            <td data-label="Name"><?= e(trim($contact['first_name'] . ' ' . $contact['last_name'])) ?></td>
                            <td data-label="Contact">
                                <?= e($contact['email'] ?: 'No email') ?><br>
                                <span class="meta"><?= e($contact['phone'] ?: 'No phone') ?></span>
                            </td>
                            <td data-label="Address">
                                <?= e($contact['mailing_address'] ?: 'No address') ?><br>
                                <span class="meta"><?= e(trim($contact['city'] . ', ' . $contact['state'] . ' ' . $contact['zip_code'], ', ')) ?></span>
                            </td>
                            <td data-label="Status">
                                <span class="badge <?= $preview['status'] === 'new' ? 'badge-success' : ($preview['status'] === 'review' ? 'badge-warning' : 'badge-muted') ?>">
                                    <?= e($preview['status_label']) ?>
                                </span>
                                <?php if ($preview['message']): ?>
                                    <br><span class="meta"><?= e($preview['message']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Match"><?= e($preview['match_name'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
