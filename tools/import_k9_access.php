<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

const K9_ACCESS_SOURCE = 'C:\\Users\\spencer\\OneDrive\\Desktop\\K9\\JCSO K9.accdb';

$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--import'], true)) {
    fwrite(STDERR, "Usage: php tools/import_k9_access.php [--dry-run|--import]\n");
    exit(1);
}

function k9_import_access_connection(): COM
{
    if (!file_exists(K9_ACCESS_SOURCE)) {
        throw new RuntimeException('Access source file was not found: ' . K9_ACCESS_SOURCE);
    }

    $connection = new COM('ADODB.Connection');
    $connection->Open('Provider=Microsoft.ACE.OLEDB.16.0;Data Source=' . K9_ACCESS_SOURCE . ';Persist Security Info=False;');

    return $connection;
}

function k9_import_rows(COM $connection, string $sql): array
{
    $recordset = $connection->Execute($sql);
    $rows = [];

    while (!$recordset->EOF) {
        $row = [];
        for ($i = 0; $i < $recordset->Fields->Count; $i++) {
            $field = $recordset->Fields($i);
            $row[$field->Name] = $field->Value;
        }
        $rows[] = $row;
        $recordset->MoveNext();
    }

    $recordset->Close();
    return $rows;
}

function k9_import_text(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function k9_import_norm(mixed $value): string
{
    $value = strtolower(k9_import_text($value));
    $value = str_replace(['&'], ['and'], $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function k9_import_date(mixed $value): ?string
{
    $text = k9_import_text($value);
    if ($text === '') {
        return null;
    }

    $timestamp = strtotime($text);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function k9_import_time(mixed $value): ?string
{
    $text = k9_import_text($value);
    if ($text === '') {
        return null;
    }

    $timestamp = strtotime($text);
    return $timestamp ? date('H:i:s', $timestamp) : null;
}

function k9_import_number(mixed $value): float
{
    $text = str_replace(',', '', k9_import_text($value));
    return $text === '' ? 0.0 : round((float) $text, 2);
}

function k9_import_table_by_legacy(string $table): array
{
    $rows = db()->query("SELECT id, legacy_access_id FROM $table WHERE legacy_access_id IS NOT NULL")->fetchAll();
    $lookup = [];
    foreach ($rows as $row) {
        $lookup[(int) $row['legacy_access_id']] = (int) $row['id'];
    }

    return $lookup;
}

function k9_import_lookup_by_name(string $table): array
{
    $rows = db()->query("SELECT id, name FROM $table")->fetchAll();
    $lookup = [];
    foreach ($rows as $row) {
        $lookup[k9_import_norm($row['name'])] = (int) $row['id'];
    }

    return $lookup;
}

function k9_import_location_id(string $location, array $locations): ?int
{
    $norm = k9_import_norm($location);
    if ($norm === '') {
        return null;
    }
    if (isset($locations[$norm])) {
        return $locations[$norm];
    }
    if (str_contains($norm, 'residence') || str_contains($norm, 'home')) {
        return $locations['residence off duty'] ?? null;
    }
    if (str_contains($norm, 'bonneville county fair')) {
        return $locations['bonneville county fair grounds'] ?? null;
    }
    if (str_contains($norm, 'court house') || str_contains($norm, 'courthouse')) {
        return $locations['jefferson county courthouse'] ?? null;
    }
    if (str_contains($norm, 'sheriff')) {
        return $locations['jefferson county sheriff office'] ?? null;
    }

    return null;
}

function k9_import_aid_id(string $aid, array $aids): ?int
{
    $norm = k9_import_norm($aid);
    if ($norm === '' || $norm === 'none') {
        return null;
    }

    $aliases = [
        'toys' => 'toy',
        'tooy' => 'toy',
        'treat' => 'treats',
        'hot dogs' => 'treats',
        'bite suite' => 'bite suit',
        'bite sute' => 'bite suit',
        'bite pillow' => 'bite pillow',
        'bite sleeve' => 'bite sleeve',
        'bite sleeves' => 'bite sleeve',
        'meth' => 'methamphetamine',
        'methamphetamine' => 'methamphetamine',
        'cocain' => 'cocaine',
        'marijauana' => 'marijuana',
        'marjuana' => 'marijuana',
        'merijuana cotton ball' => 'marijuana cotton balls',
        'herion' => 'heroin',
        'scent logic cocaine' => 'scent logic cocaine',
        'scentlogic cocaine' => 'scent logic cocaine',
        'scent logic herion' => 'scent logic heroin',
        'scent logic heroin' => 'scent logic heroin',
        'scent logic marijuana' => 'scent logic marijuana',
        'scent logic methamphetamine' => 'scent logic methamphetamine',
    ];

    $target = $aliases[$norm] ?? $norm;
    return $aids[$target] ?? null;
}

function k9_import_expense_category_id(string $category, array $categories): ?int
{
    $norm = k9_import_norm($category);
    if ($norm === '') {
        return $categories['other'] ?? null;
    }

    if (in_array($norm, ['dog food', 'food'], true)) {
        return $categories['food'] ?? null;
    }
    if (in_array($norm, ['dr visit', 'teeth cleaning', 'first aid supplies'], true)) {
        return $categories['vet medical'] ?? null;
    }
    if ($norm === 'boarding') {
        return $categories['kennel boarding'] ?? null;
    }

    return $categories[$norm] ?? ($categories['other'] ?? null);
}

function k9_import_append_note(string $notes, string $line): string
{
    $line = trim($line);
    if ($line === '') {
        return $notes;
    }

    return trim($notes . ($notes !== '' ? "\n" : '') . $line);
}

function k9_import_current_counts(): array
{
    $tables = ['k9_activity_logs', 'k9_activity_log_aids', 'k9_medical_visits', 'k9_medical_shots', 'k9_expenses'];
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) db()->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    return $counts;
}

function k9_import_analyze(array $source, array $lookups): array
{
    $analysis = [
        'source_counts' => array_map('count', $source),
        'current_counts' => k9_import_current_counts(),
        'activity_to_import' => 0,
        'activity_blank_skipped' => 0,
        'activity_missing_type_defaulted' => 0,
        'activity_missing_team_skipped' => 0,
        'activity_locations_matched' => 0,
        'activity_locations_preserved_in_notes' => 0,
        'activity_aids_matched' => 0,
        'activity_aids_preserved_in_notes' => 0,
        'medical_to_import' => 0,
        'medical_missing_dog_skipped' => 0,
        'shots_to_import' => 0,
        'shots_missing_visit_skipped' => 0,
        'expenses_to_import' => 0,
        'expenses_missing_dog_skipped' => 0,
    ];

    foreach ($source['training'] as $row) {
        $dogLegacyId = (int) k9_import_text($row['K-9ID'] ?? '');
        $handlerLegacyId = (int) k9_import_text($row['HandlerID'] ?? '');
        $date = k9_import_date($row['LogDate'] ?? null);
        $notes = k9_import_text($row['Notes'] ?? '');
        $hours = k9_import_number($row['TrainingHours'] ?? 0);
        $aid = k9_import_text($row['TrainingAidUsed'] ?? '');

        if ($dogLegacyId <= 0 && $handlerLegacyId <= 0 && !$date && $notes === '' && $hours == 0.0 && $aid === '') {
            $analysis['activity_blank_skipped']++;
            continue;
        }
        if (!$date || empty($lookups['dogs'][$dogLegacyId]) || empty($lookups['handlers'][$handlerLegacyId]) || empty($lookups['teams'][$dogLegacyId])) {
            $analysis['activity_missing_team_skipped']++;
            continue;
        }

        $analysis['activity_to_import']++;
        if ((int) k9_import_text($row['ActivityTypeID'] ?? '') <= 0) {
            $analysis['activity_missing_type_defaulted']++;
        }

        $location = k9_import_text($row['Location'] ?? '');
        if ($location !== '') {
            if (k9_import_location_id($location, $lookups['locations'])) {
                $analysis['activity_locations_matched']++;
            } else {
                $analysis['activity_locations_preserved_in_notes']++;
            }
        }

        if ($aid !== '' && k9_import_norm($aid) !== 'none') {
            if (k9_import_aid_id($aid, $lookups['aids'])) {
                $analysis['activity_aids_matched']++;
            } else {
                $analysis['activity_aids_preserved_in_notes']++;
            }
        }
    }

    foreach ($source['medical'] as $row) {
        $dogLegacyId = (int) k9_import_text($row['K-9ID'] ?? '');
        if (empty($lookups['dogs'][$dogLegacyId]) || !k9_import_date($row['MedicalDate'] ?? null)) {
            $analysis['medical_missing_dog_skipped']++;
            continue;
        }
        $analysis['medical_to_import']++;
    }

    $medicalIds = array_fill_keys(array_map(fn($row) => (int) k9_import_text($row['K9MedicalID'] ?? ''), $source['medical']), true);
    foreach ($source['shots'] as $row) {
        $medicalLegacyId = (int) k9_import_text($row['K9MedicalID'] ?? '');
        $description = k9_import_text($row['ShotDescription'] ?? '');
        if (!$medicalLegacyId || !isset($medicalIds[$medicalLegacyId]) || $description === '') {
            $analysis['shots_missing_visit_skipped']++;
            continue;
        }
        $analysis['shots_to_import']++;
    }

    foreach ($source['expenses'] as $row) {
        $dogLegacyId = (int) k9_import_text($row['K9ID'] ?? '');
        if (empty($lookups['dogs'][$dogLegacyId]) || !k9_import_date($row['ExpenseDate'] ?? null)) {
            $analysis['expenses_missing_dog_skipped']++;
            continue;
        }
        $analysis['expenses_to_import']++;
    }

    return $analysis;
}

function k9_import_run(array $source, array $lookups): array
{
    db()->beginTransaction();

    try {
        db()->exec('DELETE FROM k9_activity_log_aids');
        db()->exec('DELETE FROM k9_medical_shots');
        db()->exec('DELETE FROM k9_expenses');
        db()->exec('DELETE FROM k9_medical_visits');
        db()->exec('DELETE FROM k9_activity_logs');

        $activityStatement = db()->prepare(
            'INSERT INTO k9_activity_logs (
                legacy_access_id, team_id, dog_id, handler_id, activity_date, activity_type_id, location_id,
                training_area_id, indication_id, training_hours, is_post_training, incident_number, notes
            ) VALUES (
                :legacy_access_id, :team_id, :dog_id, :handler_id, :activity_date, :activity_type_id, :location_id,
                :training_area_id, :indication_id, :training_hours, :is_post_training, :incident_number, :notes
            )'
        );
        $aidStatement = db()->prepare(
            'INSERT INTO k9_activity_log_aids (activity_log_id, training_aid_id, amount_grams)
             VALUES (:activity_log_id, :training_aid_id, :amount_grams)'
        );

        $activityCount = 0;
        foreach ($source['training'] as $row) {
            $legacyId = (int) k9_import_text($row['CanineLogID'] ?? '');
            $dogLegacyId = (int) k9_import_text($row['K-9ID'] ?? '');
            $handlerLegacyId = (int) k9_import_text($row['HandlerID'] ?? '');
            $date = k9_import_date($row['LogDate'] ?? null);
            $notes = k9_import_text($row['Notes'] ?? '');
            $hours = k9_import_number($row['TrainingHours'] ?? 0);
            $aid = k9_import_text($row['TrainingAidUsed'] ?? '');

            if ($dogLegacyId <= 0 && $handlerLegacyId <= 0 && !$date && $notes === '' && $hours == 0.0 && $aid === '') {
                continue;
            }
            if (!$date || empty($lookups['dogs'][$dogLegacyId]) || empty($lookups['handlers'][$handlerLegacyId]) || empty($lookups['teams'][$dogLegacyId])) {
                continue;
            }

            $activityTypeLegacyId = (int) k9_import_text($row['ActivityTypeID'] ?? '');
            $activityTypeId = $lookups['activity_types'][$activityTypeLegacyId] ?? $lookups['activity_types'][1] ?? null;
            if ($activityTypeLegacyId <= 0) {
                $notes = k9_import_append_note($notes, 'Access activity type was blank; imported as Training.');
            }

            $location = k9_import_text($row['Location'] ?? '');
            $locationId = k9_import_location_id($location, $lookups['locations']);
            if ($location !== '' && !$locationId) {
                $notes = k9_import_append_note($notes, 'Access location: ' . $location);
            }

            $aidId = k9_import_aid_id($aid, $lookups['aids']);
            $amountGrams = max(0.0, k9_import_number($row['AmountGrams'] ?? 0));
            if ($aid !== '' && k9_import_norm($aid) !== 'none' && !$aidId) {
                $notes = k9_import_append_note($notes, 'Access training aid used: ' . $aid . ($amountGrams > 0 ? ' (' . number_format($amountGrams, 2, '.', '') . ' grams)' : ''));
            }

            $activityStatement->execute([
                'legacy_access_id' => $legacyId ?: null,
                'team_id' => $lookups['teams'][$dogLegacyId],
                'dog_id' => $lookups['dogs'][$dogLegacyId],
                'handler_id' => $lookups['handlers'][$handlerLegacyId],
                'activity_date' => $date,
                'activity_type_id' => $activityTypeId,
                'location_id' => $locationId,
                'training_area_id' => $lookups['training_areas'][(int) k9_import_text($row['TrainingAreasID'] ?? '')] ?? null,
                'indication_id' => $lookups['indications'][(int) k9_import_text($row['K9IndicationsID'] ?? '')] ?? null,
                'training_hours' => max(0.0, $hours),
                'is_post_training' => strtolower(k9_import_text($row['PostTraining'] ?? '')) === 'yes' ? 1 : 0,
                'incident_number' => k9_import_text($row['IncidentNumber'] ?? '') ?: null,
                'notes' => $notes,
            ]);
            $activityId = (int) db()->lastInsertId();
            $activityCount++;

            if ($aidId) {
                $aidStatement->execute([
                    'activity_log_id' => $activityId,
                    'training_aid_id' => $aidId,
                    'amount_grams' => $amountGrams,
                ]);
            }
        }

        $medicalStatement = db()->prepare(
            'INSERT INTO k9_medical_visits (
                legacy_access_id, dog_id, vet_office_id, vet_doctor_id, visit_date, vet_office_name, doctor_name,
                reason_for_visit, notes, next_appointment_date, next_appointment_time, next_appointment_scheduled
            ) VALUES (
                :legacy_access_id, :dog_id, :vet_office_id, :vet_doctor_id, :visit_date, :vet_office_name, :doctor_name,
                :reason_for_visit, :notes, :next_appointment_date, :next_appointment_time, :next_appointment_scheduled
            )'
        );
        $medicalLegacyToId = [];
        foreach ($source['medical'] as $row) {
            $legacyId = (int) k9_import_text($row['K9MedicalID'] ?? '');
            $dogLegacyId = (int) k9_import_text($row['K-9ID'] ?? '');
            $visitDate = k9_import_date($row['MedicalDate'] ?? null);
            if (!$legacyId || empty($lookups['dogs'][$dogLegacyId]) || !$visitDate) {
                continue;
            }

            $vetOfficeName = k9_import_text($row['VetOfficeName'] ?? '');
            $doctorName = k9_import_text($row['DoctorName'] ?? '');
            $vetOfficeId = $lookups['vet_offices'][k9_import_norm($vetOfficeName)] ?? null;
            if (!$vetOfficeId && in_array(k9_import_norm($vetOfficeName), ['mnt river vet', 'mountain river vet'], true)) {
                $vetOfficeId = $lookups['vet_offices']['mountain river vet'] ?? null;
            }

            $doctorId = null;
            if ($doctorName !== '') {
                $doctorKey = ($vetOfficeId ?: 0) . '|' . k9_import_norm($doctorName);
                $doctorId = $lookups['vet_doctors'][$doctorKey] ?? null;
            }

            $medicalStatement->execute([
                'legacy_access_id' => $legacyId,
                'dog_id' => $lookups['dogs'][$dogLegacyId],
                'vet_office_id' => $vetOfficeId,
                'vet_doctor_id' => $doctorId,
                'visit_date' => $visitDate,
                'vet_office_name' => $vetOfficeName ?: null,
                'doctor_name' => $doctorName ?: null,
                'reason_for_visit' => k9_import_text($row['ReasonForVisit'] ?? '') ?: null,
                'notes' => k9_import_text($row['Notes'] ?? ''),
                'next_appointment_date' => k9_import_date($row['NextAppt'] ?? null),
                'next_appointment_time' => k9_import_time($row['NextApptTime'] ?? null),
                'next_appointment_scheduled' => k9_import_text($row['NextApptScheduled'] ?? '') ?: null,
            ]);
            $medicalLegacyToId[$legacyId] = (int) db()->lastInsertId();
        }

        $shotStatement = db()->prepare(
            'INSERT INTO k9_medical_shots (legacy_access_id, medical_visit_id, dog_id, shot_description, shot_expiration)
             VALUES (:legacy_access_id, :medical_visit_id, :dog_id, :shot_description, :shot_expiration)'
        );
        foreach ($source['shots'] as $row) {
            $legacyId = (int) k9_import_text($row['K9MedicalShotsID'] ?? '');
            $medicalLegacyId = (int) k9_import_text($row['K9MedicalID'] ?? '');
            $description = k9_import_text($row['ShotDescription'] ?? '');
            if (!$legacyId || !$description || empty($medicalLegacyToId[$medicalLegacyId])) {
                continue;
            }

            $dogStatement = db()->prepare('SELECT dog_id FROM k9_medical_visits WHERE id = :id');
            $dogStatement->execute(['id' => $medicalLegacyToId[$medicalLegacyId]]);
            $dogId = (int) $dogStatement->fetchColumn();

            $shotStatement->execute([
                'legacy_access_id' => $legacyId,
                'medical_visit_id' => $medicalLegacyToId[$medicalLegacyId],
                'dog_id' => $dogId,
                'shot_description' => $description,
                'shot_expiration' => k9_import_date($row['ShotExpiration'] ?? null),
            ]);
        }

        $expenseStatement = db()->prepare(
            'INSERT INTO k9_expenses (legacy_access_id, dog_id, expense_date, expense_category_id, amount, notes)
             VALUES (:legacy_access_id, :dog_id, :expense_date, :expense_category_id, :amount, :notes)'
        );
        foreach ($source['expenses'] as $row) {
            $legacyId = (int) k9_import_text($row['ExpenseID'] ?? '');
            $dogLegacyId = (int) k9_import_text($row['K9ID'] ?? '');
            $expenseDate = k9_import_date($row['ExpenseDate'] ?? null);
            if (!$legacyId || empty($lookups['dogs'][$dogLegacyId]) || !$expenseDate) {
                continue;
            }

            $category = k9_import_text($row['ExpenseCategory'] ?? '');
            $categoryId = k9_import_expense_category_id($category, $lookups['expense_categories']);
            $notes = $category !== '' ? 'Access category: ' . $category : '';

            $expenseStatement->execute([
                'legacy_access_id' => $legacyId,
                'dog_id' => $lookups['dogs'][$dogLegacyId],
                'expense_date' => $expenseDate,
                'expense_category_id' => $categoryId,
                'amount' => max(0.0, k9_import_number($row['ExpenseAmount'] ?? 0)),
                'notes' => $notes,
            ]);
        }

        db()->commit();
        return ['activity_inserted' => $activityCount] + k9_import_current_counts();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

$connection = k9_import_access_connection();
$source = [
    'training' => k9_import_rows($connection, 'SELECT * FROM [K9Training] ORDER BY [CanineLogID]'),
    'medical' => k9_import_rows($connection, 'SELECT * FROM [K9Medical] ORDER BY [K9MedicalID]'),
    'shots' => k9_import_rows($connection, 'SELECT * FROM [K9MedicalShots] ORDER BY [K9MedicalShotsID]'),
    'expenses' => k9_import_rows($connection, 'SELECT * FROM [K9Expenses] ORDER BY [ExpenseID]'),
];
$activityTypes = [];
foreach (k9_import_rows($connection, 'SELECT * FROM [ActivityType]') as $row) {
    $statement = db()->prepare('SELECT id FROM k9_activity_types WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $statement->execute(['name' => k9_import_text($row['ActivityType'] ?? '')]);
    $activityTypes[(int) k9_import_text($row['ActivityTypeID'] ?? '')] = (int) $statement->fetchColumn();
}
$trainingAreas = [];
foreach (k9_import_rows($connection, 'SELECT * FROM [TrainingAreas]') as $row) {
    $statement = db()->prepare('SELECT id FROM k9_training_areas WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $statement->execute(['name' => k9_import_text($row['TrainingAreaDesc'] ?? '')]);
    $trainingAreas[(int) k9_import_text($row['TrainingAreasID'] ?? '')] = (int) $statement->fetchColumn();
}
$indications = [];
foreach (k9_import_rows($connection, 'SELECT * FROM [Indications]') as $row) {
    $statement = db()->prepare('SELECT id FROM k9_indications WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $statement->execute(['name' => k9_import_text($row['IndicationsDesc'] ?? '')]);
    $indications[(int) k9_import_text($row['K9IndicationsID'] ?? '')] = (int) $statement->fetchColumn();
}
$connection->Close();

$vetDoctors = [];
foreach (db()->query('SELECT id, vet_office_id, name FROM k9_vet_doctors') as $row) {
    $vetDoctors[((int) $row['vet_office_id']) . '|' . k9_import_norm($row['name'])] = (int) $row['id'];
}

$lookups = [
    'dogs' => k9_import_table_by_legacy('k9_dogs'),
    'handlers' => k9_import_table_by_legacy('k9_handlers'),
    'teams' => k9_import_table_by_legacy('k9_teams'),
    'training_areas' => $trainingAreas,
    'indications' => $indications,
    'activity_types' => $activityTypes,
    'locations' => k9_import_lookup_by_name('k9_locations'),
    'aids' => k9_import_lookup_by_name('k9_training_aids'),
    'expense_categories' => k9_import_lookup_by_name('k9_expense_categories'),
    'vet_offices' => k9_import_lookup_by_name('k9_vet_offices'),
    'vet_doctors' => $vetDoctors,
];

$analysis = k9_import_analyze($source, $lookups);
echo json_encode($analysis, JSON_PRETTY_PRINT) . PHP_EOL;

if ($mode === '--import') {
    echo json_encode(['final_counts' => k9_import_run($source, $lookups)], JSON_PRETTY_PRINT) . PHP_EOL;
}
