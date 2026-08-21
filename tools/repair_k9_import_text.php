<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$exportPath = __DIR__ . '/k9_access_export.json';
if (!file_exists($exportPath)) {
    fwrite(STDERR, "Export file not found: $exportPath\n");
    exit(1);
}

$export = json_decode((string) file_get_contents($exportPath), true);
if (!is_array($export)) {
    fwrite(STDERR, "Export file could not be read.\n");
    exit(1);
}

function k9_repair_text(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function k9_repair_norm(mixed $value): string
{
    $value = strtolower(k9_repair_text($value));
    $value = str_replace(['&'], ['and'], $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function k9_repair_append_note(string $notes, string $line): string
{
    $line = trim($line);
    if ($line === '') {
        return $notes;
    }

    return trim($notes . ($notes !== '' ? "\n" : '') . $line);
}

function k9_repair_lookup_by_name(string $table): array
{
    $rows = db()->query("SELECT id, name FROM $table")->fetchAll();
    $lookup = [];
    foreach ($rows as $row) {
        $lookup[k9_repair_norm($row['name'])] = (int) $row['id'];
    }

    return $lookup;
}

function k9_repair_location_id(string $location, array $locations): ?int
{
    $norm = k9_repair_norm($location);
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

function k9_repair_aid_id(string $aid, array $aids): ?int
{
    $norm = k9_repair_norm($aid);
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

$locations = k9_repair_lookup_by_name('k9_locations');
$aids = k9_repair_lookup_by_name('k9_training_aids');
$activityStatement = db()->prepare(
    'UPDATE k9_activity_logs
     SET notes = :notes
     WHERE legacy_access_id = :legacy_access_id'
);
$medicalStatement = db()->prepare(
    'UPDATE k9_medical_visits
     SET notes = :notes
     WHERE legacy_access_id = :legacy_access_id'
);

$activityUpdated = 0;
$medicalUpdated = 0;

db()->beginTransaction();
try {
    foreach ($export['training'] ?? [] as $row) {
        $legacyId = (int) k9_repair_text($row['CanineLogID'] ?? '');
        if ($legacyId <= 0) {
            continue;
        }

        $notes = k9_repair_text($row['Notes'] ?? '');
        $location = k9_repair_text($row['Location'] ?? '');
        if ($location !== '' && !k9_repair_location_id($location, $locations)) {
            $notes = k9_repair_append_note($notes, 'Access location: ' . $location);
        }

        $aid = k9_repair_text($row['TrainingAidUsed'] ?? '');
        if ($aid !== '' && k9_repair_norm($aid) !== 'none' && !k9_repair_aid_id($aid, $aids)) {
            $notes = k9_repair_append_note($notes, 'Access training aid used: ' . $aid);
        }

        $activityStatement->execute([
            'legacy_access_id' => $legacyId,
            'notes' => $notes,
        ]);
        $activityUpdated += $activityStatement->rowCount();
    }

    foreach ($export['medical'] ?? [] as $row) {
        $legacyId = (int) k9_repair_text($row['K9MedicalID'] ?? '');
        if ($legacyId <= 0) {
            continue;
        }

        $medicalStatement->execute([
            'legacy_access_id' => $legacyId,
            'notes' => k9_repair_text($row['Notes'] ?? ''),
        ]);
        $medicalUpdated += $medicalStatement->rowCount();
    }

    db()->commit();
} catch (Throwable $exception) {
    db()->rollBack();
    throw $exception;
}

echo json_encode([
    'activity_rows_updated' => $activityUpdated,
    'medical_rows_updated' => $medicalUpdated,
], JSON_PRETTY_PRINT) . PHP_EOL;
