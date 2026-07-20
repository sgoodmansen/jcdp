<?php
require_once __DIR__ . '/app/bootstrap.php';

$lienholdersCsv = 'C:\\!SG\\BYU-I\\SPRING 2026\\Tech Dev\\ShareTrip-1\\lienholders_import.csv';
$phonesCsv = 'C:\\!SG\\BYU-I\\SPRING 2026\\Tech Dev\\ShareTrip-1\\phones_import.csv';

function ensure_column(string $table, string $column, string $definition): void
{
    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $statement->execute([
        'table' => $table,
        'column' => $column,
    ]);

    if ((int) $statement->fetchColumn() === 0) {
        db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function read_csv_assoc(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException("Unable to open {$path}");
    }

    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    $headers = array_map(
        fn($header) => preg_replace('/^\xEF\xBB\xBF/', '', (string) $header),
        $headers
    );
    $rows = [];

    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = $data[$index] ?? '';
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

function clean_value(?string $value): string
{
    return trim((string) $value);
}

function clean_state(?string $value): string
{
    return strtoupper(trim((string) $value));
}

function format_phone(?string $value): string
{
    $value = trim((string) $value);
    $digits = preg_replace('/\D+/', '', $value);

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }

    return $value;
}

ensure_column('dmv_lienholders', 'access_lienholder_id', 'INT UNSIGNED NULL UNIQUE AFTER id');
ensure_column('dmv_lienholders', 'phone_extension', 'VARCHAR(20) NULL AFTER phone');

$phoneRows = read_csv_assoc($phonesCsv);
$phonesByLienholder = [];

foreach ($phoneRows as $phoneRow) {
    $lienholderId = (int) clean_value($phoneRow['LienHolderID'] ?? '');
    if ($lienholderId <= 0) {
        continue;
    }

    $phonesByLienholder[$lienholderId][] = [
        'number' => format_phone($phoneRow['PhoneNumber'] ?? ''),
        'type' => clean_value($phoneRow['PhoneType'] ?? ''),
        'extension' => clean_value($phoneRow['extension'] ?? ''),
    ];
}

$findStatement = db()->prepare('SELECT id FROM dmv_lienholders WHERE access_lienholder_id = :access_lienholder_id');
$insertStatement = db()->prepare(
    'INSERT INTO dmv_lienholders
        (access_lienholder_id, company_name, contact_name, mailing_address, city, state, zip_code, phone, phone_extension, fax, email, notes)
     VALUES
        (:access_lienholder_id, :company_name, :contact_name, :mailing_address, :city, :state, :zip_code, :phone, :phone_extension, :fax, :email, :notes)'
);
$updateStatement = db()->prepare(
    'UPDATE dmv_lienholders
     SET company_name = :company_name,
         contact_name = :contact_name,
         mailing_address = :mailing_address,
         city = :city,
         state = :state,
         zip_code = :zip_code,
         phone = :phone,
         phone_extension = :phone_extension,
         fax = :fax,
         email = :email,
         notes = :notes
     WHERE access_lienholder_id = :access_lienholder_id'
);

$inserted = 0;
$updated = 0;
$extraPhoneRows = 0;

foreach (read_csv_assoc($lienholdersCsv) as $row) {
    $accessId = (int) clean_value($row['LienHolderID'] ?? '');
    if ($accessId <= 0) {
        continue;
    }

    $phone = '';
    $phoneExtension = '';
    $fax = '';
    $extraContacts = [];

    foreach ($phonesByLienholder[$accessId] ?? [] as $phoneItem) {
        $type = strtolower($phoneItem['type']);

        if ($type === 'phone' && $phone === '') {
            $phone = $phoneItem['number'];
            $phoneExtension = $phoneItem['extension'];
            continue;
        }

        if ($type === 'fax' && $fax === '') {
            $fax = $phoneItem['number'];
            continue;
        }

        $extraPhoneRows++;
        $extraLine = 'Additional ' . ($phoneItem['type'] ?: 'contact') . ': ' . $phoneItem['number'];
        if ($phoneItem['extension'] !== '') {
            $extraLine .= ' ext. ' . $phoneItem['extension'];
        }
        $extraContacts[] = $extraLine;
    }

    $notes = clean_value($row['Note'] ?? '');
    if ($extraContacts) {
        $notes = trim($notes . "\n" . implode("\n", $extraContacts));
    }

    $values = [
        'access_lienholder_id' => $accessId,
        'company_name' => clean_value($row['Company'] ?? ''),
        'contact_name' => clean_value($row['Attn'] ?? ''),
        'mailing_address' => clean_value($row['Address'] ?? ''),
        'city' => clean_value($row['City'] ?? ''),
        'state' => clean_state($row['State'] ?? ''),
        'zip_code' => clean_value($row['Zip'] ?? ''),
        'phone' => $phone,
        'phone_extension' => $phoneExtension,
        'fax' => $fax,
        'email' => clean_value($row['Email'] ?? ''),
        'notes' => $notes,
    ];

    $findStatement->execute(['access_lienholder_id' => $accessId]);
    if ($findStatement->fetch()) {
        $updateStatement->execute($values);
        $updated++;
    } else {
        $insertStatement->execute($values);
        $inserted++;
    }
}

audit_event('imported', 'dmv_lienholder', 'bulk', [
    'inserted' => $inserted,
    'updated' => $updated,
    'extra_phone_rows_added_to_notes' => $extraPhoneRows,
]);

echo "DMV lienholder import complete\n";
echo "Inserted: {$inserted}\n";
echo "Updated: {$updated}\n";
echo "Extra phone/fax rows added to notes: {$extraPhoneRows}\n";
