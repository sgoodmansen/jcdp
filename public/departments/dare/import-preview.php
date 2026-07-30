<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_system_admin();

const DARE_IMPORT_SOURCE_FILE = 'C:\\!SG\\DARE Temp\\Data from Access.xlsx';

function dare_preview_column_index(string $cellReference): int
{
    preg_match('/^[A-Z]+/i', $cellReference, $matches);
    $letters = strtoupper($matches[0] ?? '');
    $index = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return max(0, $index - 1);
}

function dare_preview_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');

    if ($xml === false) {
        return [];
    }

    $sharedStrings = [];
    $document = simplexml_load_string($xml);

    if (!$document) {
        return [];
    }

    foreach ($document->si as $item) {
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

    return $sharedStrings;
}

function dare_preview_sheet_paths(ZipArchive $zip): array
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

    if ($workbookXml === false || $relsXml === false) {
        return [];
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);

    if (!$workbook || !$rels) {
        return [];
    }

    $relationshipTargets = [];
    foreach ($rels->Relationship as $relationship) {
        $attributes = $relationship->attributes();
        $target = (string) $attributes['Target'];
        $relationshipTargets[(string) $attributes['Id']] = str_starts_with($target, '/')
            ? ltrim($target, '/')
            : 'xl/' . ltrim($target, '/');
    }

    $paths = [];
    foreach ($workbook->sheets->sheet as $sheet) {
        $attributes = $sheet->attributes();
        $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) $relationshipAttributes['id'];
        $name = (string) $attributes['name'];

        if (isset($relationshipTargets[$relationshipId])) {
            $paths[$name] = $relationshipTargets[$relationshipId];
        }
    }

    return $paths;
}

function dare_preview_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $attributes = $cell->attributes();
    $type = (string) ($attributes['t'] ?? '');

    if ($type === 'inlineStr') {
        return trim((string) ($cell->is->t ?? ''));
    }

    $value = isset($cell->v) ? (string) $cell->v : '';

    if ($type === 's') {
        return trim($sharedStrings[(int) $value] ?? '');
    }

    return trim($value);
}

function dare_preview_read_xlsx(string $path): array
{
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        throw new RuntimeException('The Excel file could not be opened.');
    }

    $sharedStrings = dare_preview_shared_strings($zip);
    $sheetPaths = dare_preview_sheet_paths($zip);
    $workbook = [];

    foreach ($sheetPaths as $sheetName => $sheetPath) {
        $xml = $zip->getFromName($sheetPath);

        if ($xml === false) {
            $workbook[$sheetName] = [];
            continue;
        }

        $sheet = simplexml_load_string($xml);
        $rows = [];

        if (!$sheet || !isset($sheet->sheetData)) {
            $workbook[$sheetName] = [];
            continue;
        }

        foreach ($sheet->sheetData->row as $row) {
            $values = [];

            foreach ($row->c as $cell) {
                $cellAttributes = $cell->attributes();
                $columnIndex = dare_preview_column_index((string) $cellAttributes['r']);
                $values[$columnIndex] = dare_preview_cell_value($cell, $sharedStrings);
            }

            if ($values) {
                ksort($values);
                $rows[] = $values;
            }
        }

        $headers = array_map(
            fn($value) => trim((string) $value),
            $rows[0] ?? []
        );
        $records = [];

        foreach (array_slice($rows, 1) as $row) {
            $record = [];
            $hasValue = false;
            $max = max(count($headers), count($row));

            for ($i = 0; $i < $max; $i++) {
                $header = $headers[$i] ?? '';

                if ($header === '') {
                    continue;
                }

                $value = trim((string) ($row[$i] ?? ''));
                $record[$header] = $value;

                if ($value !== '') {
                    $hasValue = true;
                }
            }

            if ($hasValue) {
                $records[] = $record;
            }
        }

        $workbook[$sheetName] = $records;
    }

    $zip->close();
    return $workbook;
}

function dare_preview_count_values(array $rows, string $field): array
{
    $counts = [];

    foreach ($rows as $row) {
        $value = trim((string) ($row[$field] ?? ''));
        $value = $value === '' ? '<blank>' : $value;
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    arsort($counts);
    return $counts;
}

function dare_preview_count_normalized_semesters(array $rows): array
{
    $counts = [];

    foreach ($rows as $row) {
        $value = dare_preview_semester_label((string) ($row['semester'] ?? ''));
        $value = $value === '' ? '<blank>' : $value;
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    arsort($counts);
    return $counts;
}

function dare_preview_duplicates(array $rows, array $fields): array
{
    $counts = [];
    $labels = [];

    foreach ($rows as $row) {
        $parts = array_map(fn($field) => strtolower(trim((string) ($row[$field] ?? ''))), $fields);

        if (!array_filter($parts)) {
            continue;
        }

        $key = implode('|', $parts);
        $labelParts = array_map(fn($field) => trim((string) ($row[$field] ?? '')), $fields);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $labels[$key] = implode(' / ', array_filter($labelParts, fn($part) => $part !== ''));
    }

    $duplicates = [];
    foreach ($counts as $key => $count) {
        if ($count > 1) {
            $duplicates[] = ['label' => $labels[$key], 'count' => $count];
        }
    }

    usort($duplicates, fn($a, $b) => $b['count'] <=> $a['count']);
    return $duplicates;
}

function dare_preview_year_label(string $value): string
{
    $value = trim($value);

    if (preg_match('/^\d{2}-\d{2}$/', $value)) {
        [$start, $end] = explode('-', $value);
        return '20' . $start . ' - 20' . $end;
    }

    if (preg_match('/^\d{4}$/', $value)) {
        $year = (int) $value;
        return ($year - 1) . ' - ' . $year;
    }

    return $value;
}

function dare_preview_semester_label(string $value): string
{
    $value = trim($value);
    $lookup = strtolower($value);

    return match ($lookup) {
        '1st tri' => '1st Tri',
        '2nd tri' => '2nd Tri',
        '3rd tri' => '3rd Tri',
        'fall' => '1st Tri',
        'spring' => '2nd Tri',
        default => $value,
    };
}

function dare_preview_gender_label(string $value): ?string
{
    $lookup = strtolower(trim($value));

    return match ($lookup) {
        'f' => 'Female',
        'm', 'mm' => 'Male',
        default => null,
    };
}

function dare_preview_is_starred_essay_winner_exception(string $firstName, string $lastName): bool
{
    $cleanFirst = strtolower(str_replace('*', '', trim($firstName)));
    $cleanLast = strtolower(str_replace('*', '', trim($lastName)));

    return $cleanFirst === 'brynlee' && $cleanLast === 'chapman';
}

function dare_preview_db_counts(): array
{
    $counts = [];

    foreach (['dare_schools', 'dare_officers', 'dare_teachers', 'dare_classes', 'dare_students', 'dare_class_students'] as $table) {
        $counts[$table] = (int) db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

function dare_preview_render_counts(array $counts, int $limit = 12): void
{
    $shown = 0;

    foreach ($counts as $label => $count) {
        if ($shown >= $limit) {
            break;
        }

        echo '<tr><td data-label="Value">' . e($label) . '</td><td data-label="Records">' . e((string) $count) . '</td></tr>';
        $shown++;
    }
}

$sourcePath = DARE_IMPORT_SOURCE_FILE;
$error = null;
$analysis = null;

try {
    if (!file_exists($sourcePath)) {
        throw new RuntimeException('The source file was not found.');
    }

    $workbook = dare_preview_read_xlsx($sourcePath);
    $schools = $workbook['School'] ?? [];
    $officers = $workbook['DARE Instructor'] ?? [];
    $teachers = $workbook['Teacher'] ?? [];
    $classes = $workbook['DARE Class'] ?? [];
    $students = $workbook['Student'] ?? [];

    $schoolIds = array_fill_keys(array_column($schools, 'schoolID'), true);
    $teacherIds = array_fill_keys(array_column($teachers, 'teacherID'), true);
    $classIds = array_fill_keys(array_column($classes, 'dareclassID'), true);
    $officerNames = array_fill_keys(array_map(fn($row) => trim((string) ($row['Instructor'] ?? '')), $officers), true);
    unset($officerNames['']);

    $studentCountsByClass = [];
    $studentSchoolByClass = [];
    foreach ($students as $student) {
        $classId = trim((string) ($student['dareclassID'] ?? ''));
        $schoolId = trim((string) ($student['schoolID'] ?? ''));

        if ($classId !== '' && isset($classIds[$classId])) {
            $studentCountsByClass[$classId] = ($studentCountsByClass[$classId] ?? 0) + 1;

            if ($schoolId !== '') {
                $studentSchoolByClass[$classId][$schoolId] = ($studentSchoolByClass[$classId][$schoolId] ?? 0) + 1;
            }
        }
    }

    $teacherById = [];
    foreach ($teachers as $teacher) {
        $teacherById[trim((string) ($teacher['teacherID'] ?? ''))] = $teacher;
    }

    $classesWithStudents = 0;
    $classesWithoutStudents = 0;
    $classesWithRecoverableSchool = 0;
    $classesSkipped = [];
    $classesNeedingDateDecision = 0;
    $classesWithBlankYear = 0;
    $classesWithBlankSemester = 0;
    $classesWithBlankPeriod = 0;

    foreach ($classes as $class) {
        $classId = trim((string) ($class['dareclassID'] ?? ''));
        $studentCount = $studentCountsByClass[$classId] ?? 0;

        if ($studentCount > 0) {
            $classesWithStudents++;
        } else {
            $classesWithoutStudents++;
        }

        $schoolId = trim((string) ($class['schoolID'] ?? ''));
        $teacherId = trim((string) ($class['teacherID'] ?? ''));
        $recoverableSchoolId = $schoolId;

        if ($recoverableSchoolId === '' || !isset($schoolIds[$recoverableSchoolId])) {
            $teacherSchoolId = trim((string) ($teacherById[$teacherId]['schoolID'] ?? ''));
            if ($teacherSchoolId !== '' && isset($schoolIds[$teacherSchoolId])) {
                $recoverableSchoolId = $teacherSchoolId;
            } elseif (!empty($studentSchoolByClass[$classId])) {
                arsort($studentSchoolByClass[$classId]);
                $studentSchoolId = (string) array_key_first($studentSchoolByClass[$classId]);
                if ($studentSchoolId !== '' && isset($schoolIds[$studentSchoolId])) {
                    $recoverableSchoolId = $studentSchoolId;
                }
            }
        }

        if ($recoverableSchoolId !== '' && isset($schoolIds[$recoverableSchoolId])) {
            $classesWithRecoverableSchool++;
        } elseif ($studentCount > 0) {
            $classesSkipped[] = [
                'id' => $classId,
                'reason' => 'No valid school could be found',
                'students' => $studentCount,
            ];
        }

        if ($studentCount > 0) {
            if (trim((string) ($class['classyear'] ?? '')) === '') {
                $classesWithBlankYear++;
            }
            if (trim((string) ($class['semester'] ?? '')) === '') {
                $classesWithBlankSemester++;
            }
            if (trim((string) ($class['classperiod'] ?? '')) === '') {
                $classesWithBlankPeriod++;
            }
            $classesNeedingDateDecision++;
        }
    }

    $linkedStudents = 0;
    $unlinkedStudents = 0;
    $studentsWithBlankName = 0;
    $studentDuplicateCounts = [];
    $possibleEssayWinners = [];
    $starredNames = [];
    $starredEssayWinnerExceptions = [];
    $oddGenderValues = [];

    foreach ($students as $student) {
        $classId = trim((string) ($student['dareclassID'] ?? ''));
        $firstName = trim((string) ($student['FirstName'] ?? ''));
        $lastName = trim((string) ($student['LastName'] ?? ''));
        $gender = trim((string) ($student['Sex'] ?? ''));
        $notes = trim((string) ($student['notes'] ?? ''));

        if ($classId !== '' && isset($classIds[$classId])) {
            $linkedStudents++;
            $duplicateKey = $classId . '|' . strtolower($lastName) . '|' . strtolower($firstName);
            $studentDuplicateCounts[$duplicateKey] = ($studentDuplicateCounts[$duplicateKey] ?? 0) + 1;
        } else {
            $unlinkedStudents++;
        }

        if ($firstName === '' || $lastName === '') {
            $studentsWithBlankName++;
        }

        if ($gender !== '' && dare_preview_gender_label($gender) === null) {
            $oddGenderValues[$gender] = ($oddGenderValues[$gender] ?? 0) + 1;
        }

        if (str_contains($firstName, '*') || str_contains($lastName, '*')) {
            $starredName = trim($firstName . ' ' . $lastName);

            if (dare_preview_is_starred_essay_winner_exception($firstName, $lastName)) {
                $starredEssayWinnerExceptions[] = $starredName;
            } else {
                $starredNames[] = $starredName;
            }
        }

        if ($notes !== '' && preg_match('/essay\s+winner|winner/i', $notes)) {
            $possibleEssayWinners[] = [
                'student' => trim($firstName . ' ' . $lastName),
                'class_id' => $classId,
                'note' => $notes,
            ];
        }
    }

    $duplicateStudentsWithinClass = count(array_filter($studentDuplicateCounts, fn($count) => $count > 1));

    $analysis = [
        'sheet_counts' => [
            'School' => count($schools),
            'DARE Instructor' => count($officers),
            'Teacher' => count($teachers),
            'DARE Class' => count($classes),
            'Student' => count($students),
        ],
        'db_counts' => dare_preview_db_counts(),
        'import_counts' => [
            'Schools to match/import' => count($schools),
            'Officers to match/import' => count($officers),
            'Teachers to match/import' => count($teachers),
            'Classes with students' => $classesWithStudents,
            'Students linked to valid classes' => $linkedStudents,
            'Classes likely skipped' => $classesWithoutStudents + count($classesSkipped),
            'Students skipped without valid class' => $unlinkedStudents,
        ],
        'review_counts' => [
            'Classes needing date decision' => $classesNeedingDateDecision,
            'Classes with blank school year' => $classesWithBlankYear,
            'Classes with blank semester' => $classesWithBlankSemester,
            'Classes with blank period' => $classesWithBlankPeriod,
            'Duplicate teachers by name and school' => count(dare_preview_duplicates($teachers, ['teacherLast', 'teacherFirst', 'schoolID'])),
            'Duplicate student names within same class' => $duplicateStudentsWithinClass,
            'Students with blank first or last name' => $studentsWithBlankName,
            'Possible essay winners from notes' => count($possibleEssayWinners),
            'Names containing asterisk marker' => count($starredNames),
            'Starred name exceptions' => count($starredEssayWinnerExceptions),
            'Odd gender values' => array_sum($oddGenderValues),
        ],
        'value_counts' => [
            'Class Status' => dare_preview_count_values($classes, 'status'),
            'Semester Source' => dare_preview_count_values($classes, 'semester'),
            'Semester After Import' => dare_preview_count_normalized_semesters($classes),
            'School Year' => dare_preview_count_values($classes, 'classyear'),
            'Student Gender Source' => dare_preview_count_values($students, 'Sex'),
            'Student Essay' => dare_preview_count_values($students, 'Essay'),
            'Student Graduated' => dare_preview_count_values($students, 'Graduated'),
        ],
        'sensitive_columns' => array_values(array_intersect(
            ['Birthday', 'ShirtSize', 'Address', 'City', 'State', 'Zip', 'Phone', 'HomePhone', 'CellPhone'],
            array_keys($students[0] ?? [])
        )),
        'duplicates' => [
            'teachers' => dare_preview_duplicates($teachers, ['teacherLast', 'teacherFirst', 'schoolID']),
            'schools' => dare_preview_duplicates($schools, ['SchoolName']),
        ],
        'possible_essay_winners' => array_slice($possibleEssayWinners, 0, 20),
        'starred_names' => array_slice(array_unique($starredNames), 0, 20),
        'starred_essay_winner_exceptions' => array_slice(array_unique($starredEssayWinnerExceptions), 0, 20),
        'classes_skipped' => array_slice($classesSkipped, 0, 20),
        'odd_gender_values' => $oddGenderValues,
    ];
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$actions = [
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php'), 'primary' => true],
    ['label' => 'Import data', 'href' => url('departments/dare/import.php')],
    ['label' => 'Cleanup', 'href' => url('departments/dare/cleanup.php')],
    ['label' => 'Reports', 'href' => url('departments/dare/report.php')],
];

page_header('DARE Import Preview');
?>
<main class="shell">
    <section class="panel">
        <h1>DARE Import Preview</h1>
        <p>Review the Access export before importing. This page does not write records to the database.</p>
        <?php page_actions($actions); ?>
        <p class="meta">Source file: <?= e($sourcePath) ?></p>
    </section>

    <?php if ($error): ?>
        <section class="panel" style="margin-top: 18px;">
            <div class="notice error"><?= e($error) ?></div>
        </section>
    <?php else: ?>
        <section class="grid dashboard-stat-grid" style="margin-top: 18px;">
            <?php foreach ($analysis['import_counts'] as $label => $count): ?>
                <article class="card dashboard-stat-card">
                    <h3><?= e((string) $count) ?></h3>
                    <p><?= e($label) ?></p>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Recommended Import Rules</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Area</th>
                        <th>Rule</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td data-label="Area">Class status</td>
                        <td data-label="Rule">Import historical classes as Closed.</td>
                    </tr>
                    <tr>
                        <td data-label="Area">Dates</td>
                        <td data-label="Rule">Do not invent start, end, or graduation dates. Historical imports need a blank-date path or a confirmed date rule.</td>
                    </tr>
                    <tr>
                        <td data-label="Area">Semester</td>
                        <td data-label="Rule">Convert Fall to 1st Tri and Spring to 2nd Tri. Keep existing trimester values normalized as 1st Tri, 2nd Tri, or 3rd Tri.</td>
                    </tr>
                    <tr>
                        <td data-label="Area">Student private data</td>
                        <td data-label="Rule">Do not import <?= e(implode(', ', $analysis['sensitive_columns'])) ?>.</td>
                    </tr>
                    <tr>
                        <td data-label="Area">Gender</td>
                        <td data-label="Rule">Convert F/f to Female, M/mm to Male, and leave blanks empty.</td>
                    </tr>
                    <tr>
                        <td data-label="Area">Essay winners</td>
                        <td data-label="Rule">Use notes containing “Essay winner” and starred names as essay winner markers, except Brynlee Chapman*, which should not be marked as an essay winner.</td>
                    </tr>
                    <tr>
                        <td data-label="Area">Empty records</td>
                        <td data-label="Rule">Skip classes with no students and students without a valid class.</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Current Database Counts</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Records</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analysis['db_counts'] as $table => $count): ?>
                        <tr>
                            <td data-label="Table"><?= e($table) ?></td>
                            <td data-label="Records"><?= e((string) $count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Workbook Sheet Counts</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Sheet</th>
                        <th>Rows</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analysis['sheet_counts'] as $sheet => $count): ?>
                        <tr>
                            <td data-label="Sheet"><?= e($sheet) ?></td>
                            <td data-label="Rows"><?= e((string) $count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="panel" style="margin-top: 18px;">
            <h1>Items To Review</h1>
            <table class="table mobile-card-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Records</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analysis['review_counts'] as $label => $count): ?>
                        <tr>
                            <td data-label="Item"><?= e($label) ?></td>
                            <td data-label="Records"><?= e((string) $count) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="grid two-column-grid" style="margin-top: 18px;">
            <?php foreach ($analysis['value_counts'] as $title => $counts): ?>
                <article class="panel">
                    <h1><?= e($title) ?></h1>
                    <table class="table mobile-card-table">
                        <thead>
                            <tr>
                                <th>Value</th>
                                <th>Records</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php dare_preview_render_counts($counts); ?>
                        </tbody>
                    </table>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($analysis['duplicates']['teachers']): ?>
            <section class="panel" style="margin-top: 18px;">
                <h1>Duplicate Teachers To Merge Or Match</h1>
                <table class="table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Teacher / School ID</th>
                            <th>Records</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysis['duplicates']['teachers'] as $duplicate): ?>
                            <tr>
                                <td data-label="Teacher / School ID"><?= e($duplicate['label']) ?></td>
                                <td data-label="Records"><?= e((string) $duplicate['count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <?php if ($analysis['possible_essay_winners']): ?>
            <section class="panel" style="margin-top: 18px;">
                <h1>Possible Essay Winners</h1>
                <table class="table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Access Class ID</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysis['possible_essay_winners'] as $winner): ?>
                            <tr>
                                <td data-label="Student"><?= e($winner['student']) ?></td>
                                <td data-label="Access Class ID"><?= e($winner['class_id']) ?></td>
                                <td data-label="Note"><?= e($winner['note']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="meta" style="margin-top: 12px;">Showing the first 20 possible essay winner notes.</p>
            </section>
        <?php endif; ?>

        <?php if ($analysis['starred_names'] || $analysis['starred_essay_winner_exceptions'] || $analysis['odd_gender_values']): ?>
            <section class="grid two-column-grid" style="margin-top: 18px;">
                <article class="panel">
                    <h1>Starred Essay Winner Names</h1>
                    <?php if (!$analysis['starred_names']): ?>
                        <p>No starred essay winner names found.</p>
                    <?php else: ?>
                        <table class="table mobile-card-table">
                            <thead>
                                <tr><th>Name</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analysis['starred_names'] as $name): ?>
                                    <tr><td data-label="Name"><?= e($name) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="meta" style="margin-top: 12px;">These starred names should be treated as essay winners during import.</p>
                    <?php endif; ?>

                    <?php if ($analysis['starred_essay_winner_exceptions']): ?>
                        <h2 style="margin-top: 18px;">Starred Exceptions</h2>
                        <table class="table mobile-card-table">
                            <thead>
                                <tr><th>Name</th><th>Import Rule</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analysis['starred_essay_winner_exceptions'] as $name): ?>
                                    <tr>
                                        <td data-label="Name"><?= e($name) ?></td>
                                        <td data-label="Import Rule">Do not mark as essay winner</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </article>
                <article class="panel">
                    <h1>Odd Gender Values</h1>
                    <?php if (!$analysis['odd_gender_values']): ?>
                        <p>No odd gender values found.</p>
                    <?php else: ?>
                        <table class="table mobile-card-table">
                            <thead>
                                <tr>
                                    <th>Value</th>
                                    <th>Records</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analysis['odd_gender_values'] as $value => $count): ?>
                                    <tr>
                                        <td data-label="Value"><?= e($value) ?></td>
                                        <td data-label="Records"><?= e((string) $count) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </article>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php page_footer(); ?>
