<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_system_admin();

const DARE_IMPORT_FILE = 'C:\\!SG\\DARE Temp\\Data from Access.xlsx';
const DARE_IMPORT_CONFIRMATION = 'IMPORT DARE ACCESS DATA';

function dare_import_column_index(string $cellReference): int
{
    preg_match('/^[A-Z]+/i', $cellReference, $matches);
    $letters = strtoupper($matches[0] ?? '');
    $index = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return max(0, $index - 1);
}

function dare_import_read_xlsx(string $path): array
{
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
    $sheetPaths = [];

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
                $attributes = $sheet->attributes();
                $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relationshipId = (string) $relationshipAttributes['id'];
                $name = (string) $attributes['name'];

                if (isset($targets[$relationshipId])) {
                    $sheetPaths[$name] = $targets[$relationshipId];
                }
            }
        }
    }

    $sheets = [];
    foreach ($sheetPaths as $sheetName => $sheetPath) {
        $xml = $zip->getFromName($sheetPath);
        $sheetRows = [];

        if ($xml !== false && ($sheet = simplexml_load_string($xml)) && isset($sheet->sheetData)) {
            foreach ($sheet->sheetData->row as $row) {
                $values = [];
                foreach ($row->c as $cell) {
                    $cellAttributes = $cell->attributes();
                    $type = (string) ($cellAttributes['t'] ?? '');
                    $value = isset($cell->v) ? trim((string) $cell->v) : '';

                    if ($type === 's') {
                        $value = trim($sharedStrings[(int) $value] ?? '');
                    } elseif ($type === 'inlineStr') {
                        $value = trim((string) ($cell->is->t ?? ''));
                    }

                    $values[dare_import_column_index((string) $cellAttributes['r'])] = $value;
                }

                if ($values) {
                    ksort($values);
                    $sheetRows[] = $values;
                }
            }
        }

        $headers = array_map(fn($value) => trim((string) $value), $sheetRows[0] ?? []);
        $records = [];

        foreach (array_slice($sheetRows, 1) as $row) {
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
                $hasValue = $hasValue || $value !== '';
            }

            if ($hasValue) {
                $records[] = $record;
            }
        }

        $sheets[$sheetName] = $records;
    }

    $zip->close();
    return $sheets;
}

function dare_import_semester(string $value): string
{
    return match (strtolower(trim($value))) {
        'fall', '1st tri' => '1st Tri',
        'spring', '2nd tri' => '2nd Tri',
        '3rd tri' => '3rd Tri',
        default => trim($value),
    };
}

function dare_import_school_year(string $value): string
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

function dare_import_gender(?string $value): ?string
{
    return match (strtolower(trim((string) $value))) {
        'f' => 'Female',
        'm', 'mm' => 'Male',
        default => null,
    };
}

function dare_import_star_exception(string $firstName, string $lastName): bool
{
    return strtolower(str_replace('*', '', trim($firstName))) === 'brynlee'
        && strtolower(str_replace('*', '', trim($lastName))) === 'chapman';
}

function dare_import_clean_name(string $value): string
{
    return title_case_name(str_replace('*', '', $value));
}

function dare_import_person_parts(string $fullName): array
{
    $fullName = title_case_name($fullName);
    $parts = preg_split('/\s+/', trim($fullName));

    if (!$parts || count($parts) === 1) {
        return ['first_name' => $fullName, 'last_name' => ''];
    }

    $lastName = array_pop($parts);
    return ['first_name' => implode(' ', $parts), 'last_name' => $lastName];
}

function dare_import_execute(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException('The source Excel file was not found.');
    }

    $sheets = dare_import_read_xlsx($path);
    $schools = $sheets['School'] ?? [];
    $officers = $sheets['DARE Instructor'] ?? [];
    $teachers = $sheets['Teacher'] ?? [];
    $classes = $sheets['DARE Class'] ?? [];
    $students = $sheets['Student'] ?? [];

    $counts = [
        'schools_imported' => 0,
        'officers_imported' => 0,
        'teachers_imported' => 0,
        'classes_imported' => 0,
        'students_imported' => 0,
        'class_students_imported' => 0,
        'classes_skipped_no_students' => 0,
        'classes_skipped_no_school' => 0,
        'students_skipped_no_class' => 0,
        'essay_winners' => 0,
    ];

    $studentCountsByClass = [];
    $studentSchoolByClass = [];
    foreach ($students as $student) {
        $classId = trim((string) ($student['dareclassID'] ?? ''));
        $schoolId = trim((string) ($student['schoolID'] ?? ''));

        if ($classId !== '') {
            $studentCountsByClass[$classId] = ($studentCountsByClass[$classId] ?? 0) + 1;

            if ($schoolId !== '') {
                $studentSchoolByClass[$classId][$schoolId] = ($studentSchoolByClass[$classId][$schoolId] ?? 0) + 1;
            }
        }
    }

    db()->beginTransaction();

    try {
        $schoolMap = [];
        $schoolStatement = db()->prepare(
            'INSERT INTO dare_schools (access_school_id, name, is_active)
             VALUES (:access_school_id, :name, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1'
        );
        $schoolLookup = db()->prepare(
            'SELECT id FROM dare_schools WHERE access_school_id = :access_school_id OR name = :name ORDER BY access_school_id IS NULL LIMIT 1'
        );

        foreach ($schools as $school) {
            $accessId = trim((string) ($school['schoolID'] ?? ''));
            $name = title_case_name($school['SchoolName'] ?? '');

            if ($accessId === '' || $name === '') {
                continue;
            }

            $schoolStatement->execute(['access_school_id' => $accessId, 'name' => $name]);
            $schoolLookup->execute(['access_school_id' => $accessId, 'name' => $name]);
            $schoolMap[$accessId] = (int) $schoolLookup->fetchColumn();
            $counts['schools_imported']++;
        }

        $officerMap = [];
        $officerStatement = db()->prepare(
            'INSERT INTO dare_officers (access_instructor_id, first_name, last_name, is_active)
             VALUES (:access_instructor_id, :first_name, :last_name, :is_active)
             ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), is_active = VALUES(is_active)'
        );
        $officerLookup = db()->prepare('SELECT id FROM dare_officers WHERE access_instructor_id = :access_instructor_id OR (first_name = :first_name AND last_name = :last_name) LIMIT 1');

        foreach ($officers as $officer) {
            $accessId = trim((string) ($officer['DareInstructorID'] ?? ''));
            $parts = dare_import_person_parts($officer['Instructor'] ?? '');

            if ($accessId === '' || $parts['first_name'] === '') {
                continue;
            }

            $isActive = strtolower(trim((string) ($officer['Status'] ?? ''))) === 'inactive' ? 0 : 1;
            $officerStatement->execute([
                'access_instructor_id' => $accessId,
                'first_name' => $parts['first_name'],
                'last_name' => $parts['last_name'],
                'is_active' => $isActive,
            ]);
            $officerLookup->execute([
                'access_instructor_id' => $accessId,
                'first_name' => $parts['first_name'],
                'last_name' => $parts['last_name'],
            ]);
            $officerMap[$accessId] = (int) $officerLookup->fetchColumn();
            $officerMap[strtolower(trim((string) ($officer['Instructor'] ?? '')))] = $officerMap[$accessId];
            $counts['officers_imported']++;
        }

        $teacherMap = [];
        $teacherRowsByAccessId = [];
        $teacherStatement = db()->prepare(
            'INSERT INTO dare_teachers (access_teacher_id, school_id, first_name, last_name, is_active)
             VALUES (:access_teacher_id, :school_id, :first_name, :last_name, :is_active)
             ON DUPLICATE KEY UPDATE school_id = VALUES(school_id), first_name = VALUES(first_name), last_name = VALUES(last_name), is_active = VALUES(is_active)'
        );
        $teacherLookup = db()->prepare('SELECT id FROM dare_teachers WHERE access_teacher_id = :access_teacher_id LIMIT 1');

        foreach ($teachers as $teacher) {
            $accessId = trim((string) ($teacher['teacherID'] ?? ''));
            $schoolAccessId = trim((string) ($teacher['schoolID'] ?? ''));
            $firstName = dare_import_clean_name($teacher['teacherFirst'] ?? '');
            $lastName = dare_import_clean_name($teacher['teacherLast'] ?? '');

            if ($accessId === '' || $firstName === '' || $lastName === '') {
                continue;
            }

            $isActive = strtolower(trim((string) ($teacher['status'] ?? ''))) === 'active' ? 1 : 0;
            $teacherStatement->execute([
                'access_teacher_id' => $accessId,
                'school_id' => $schoolMap[$schoolAccessId] ?? null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive,
            ]);
            $teacherLookup->execute(['access_teacher_id' => $accessId]);
            $teacherMap[$accessId] = (int) $teacherLookup->fetchColumn();
            $teacherRowsByAccessId[$accessId] = $teacher;
            $counts['teachers_imported']++;
        }

        $classMap = [];
        $classStatement = db()->prepare(
            'INSERT INTO dare_classes
                (access_dareclass_id, school_id, teacher_id, officer_id, created_by, school_year, class_name, semester, period, start_date, end_date, graduation_date, status, notes)
             VALUES
                (:access_dareclass_id, :school_id, :teacher_id, :officer_id, :created_by, :school_year, :class_name, :semester, :period, NULL, NULL, NULL, "closed", :notes)
             ON DUPLICATE KEY UPDATE
                school_id = VALUES(school_id),
                teacher_id = VALUES(teacher_id),
                officer_id = VALUES(officer_id),
                school_year = VALUES(school_year),
                class_name = VALUES(class_name),
                semester = VALUES(semester),
                period = VALUES(period),
                status = "closed",
                notes = VALUES(notes)'
        );
        $classLookup = db()->prepare('SELECT id FROM dare_classes WHERE access_dareclass_id = :access_dareclass_id LIMIT 1');

        foreach ($classes as $class) {
            $accessId = trim((string) ($class['dareclassID'] ?? ''));
            $studentCount = $studentCountsByClass[$accessId] ?? 0;

            if ($accessId === '' || $studentCount === 0) {
                $counts['classes_skipped_no_students']++;
                continue;
            }

            $schoolAccessId = trim((string) ($class['schoolID'] ?? ''));
            if (!isset($schoolMap[$schoolAccessId])) {
                $teacherAccessId = trim((string) ($class['teacherID'] ?? ''));
                $teacherSchoolAccessId = trim((string) ($teacherRowsByAccessId[$teacherAccessId]['schoolID'] ?? ''));
                if (isset($schoolMap[$teacherSchoolAccessId])) {
                    $schoolAccessId = $teacherSchoolAccessId;
                } elseif (!empty($studentSchoolByClass[$accessId])) {
                    arsort($studentSchoolByClass[$accessId]);
                    $schoolAccessId = (string) array_key_first($studentSchoolByClass[$accessId]);
                }
            }

            if (!isset($schoolMap[$schoolAccessId])) {
                $counts['classes_skipped_no_school']++;
                continue;
            }

            $schoolYear = dare_import_school_year((string) ($class['classyear'] ?? ''));
            $semester = dare_import_semester((string) ($class['semester'] ?? ''));
            $period = trim((string) ($class['classperiod'] ?? ''));
            $className = dare_class_label([
                'school_year' => $schoolYear,
                'semester' => $semester,
                'period' => $period,
            ]);
            $officerKey = strtolower(trim((string) ($class['dareinstructorID'] ?? '')));

            $classStatement->execute([
                'access_dareclass_id' => $accessId,
                'school_id' => $schoolMap[$schoolAccessId],
                'teacher_id' => $teacherMap[trim((string) ($class['teacherID'] ?? ''))] ?? null,
                'officer_id' => $officerMap[$officerKey] ?? null,
                'created_by' => current_user()['id'] ?? null,
                'school_year' => $schoolYear,
                'class_name' => $className,
                'semester' => $semester,
                'period' => $period,
                'notes' => 'Imported from Access. Access class status: ' . (trim((string) ($class['status'] ?? '')) ?: 'Not set') . '. Historical dates were not imported.',
            ]);
            $classLookup->execute(['access_dareclass_id' => $accessId]);
            $classMap[$accessId] = (int) $classLookup->fetchColumn();
            $counts['classes_imported']++;
        }

        $studentStatement = db()->prepare(
            'INSERT INTO dare_students (access_student_id, first_name, last_name, is_active, notes)
             VALUES (:access_student_id, :first_name, :last_name, 1, NULL)
             ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), is_active = 1'
        );
        $studentLookup = db()->prepare('SELECT id FROM dare_students WHERE access_student_id = :access_student_id LIMIT 1');
        $classStudentStatement = db()->prepare(
            'INSERT INTO dare_class_students (class_id, student_id, essay_completed, essay_winner, gender)
             VALUES (:class_id, :student_id, :essay_completed, :essay_winner, :gender)
             ON DUPLICATE KEY UPDATE essay_completed = VALUES(essay_completed), essay_winner = VALUES(essay_winner), gender = VALUES(gender)'
        );

        foreach ($students as $student) {
            $classAccessId = trim((string) ($student['dareclassID'] ?? ''));
            if (!isset($classMap[$classAccessId])) {
                $counts['students_skipped_no_class']++;
                continue;
            }

            $accessId = trim((string) ($student['studentID'] ?? ''));
            $rawFirstName = trim((string) ($student['FirstName'] ?? ''));
            $rawLastName = trim((string) ($student['LastName'] ?? ''));
            $firstName = dare_import_clean_name($rawFirstName);
            $lastName = dare_import_clean_name($rawLastName);

            if ($accessId === '' || $firstName === '' || $lastName === '') {
                $counts['students_skipped_no_class']++;
                continue;
            }

            $notes = trim((string) ($student['notes'] ?? ''));
            $hasStar = str_contains($rawFirstName, '*') || str_contains($rawLastName, '*');
            $isStarException = dare_import_star_exception($rawFirstName, $rawLastName);
            $essayWinner = !$isStarException && (
                $hasStar || preg_match('/essay\s+winner/i', $notes)
            ) ? 1 : 0;
            $essayCompleted = (
                strtolower(trim((string) ($student['Essay'] ?? ''))) === 'yes'
                || strtolower(trim((string) ($student['Graduated'] ?? ''))) === 'yes'
            ) ? 1 : 0;

            $studentStatement->execute([
                'access_student_id' => $accessId,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
            $studentLookup->execute(['access_student_id' => $accessId]);
            $studentId = (int) $studentLookup->fetchColumn();

            $classStudentStatement->execute([
                'class_id' => $classMap[$classAccessId],
                'student_id' => $studentId,
                'essay_completed' => $essayCompleted,
                'essay_winner' => $essayWinner,
                'gender' => dare_import_gender($student['Sex'] ?? ''),
            ]);

            $counts['students_imported']++;
            $counts['class_students_imported']++;
            $counts['essay_winners'] += $essayWinner;
        }

        audit_event('dare_import_completed', 'dare_import', 'access_excel', $counts);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }

    return $counts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmation = trim($_POST['confirmation'] ?? '');

    if ($confirmation !== DARE_IMPORT_CONFIRMATION) {
        flash('error', 'The confirmation phrase did not match. No records were imported.');
        redirect_to('departments/dare/import.php');
    }

    try {
        $result = dare_import_execute(DARE_IMPORT_FILE);
        flash('success', 'DARE Access import completed. Imported ' . $result['classes_imported'] . ' classes and ' . $result['students_imported'] . ' students.');
    } catch (Throwable $exception) {
        flash('error', 'Import failed: ' . $exception->getMessage());
    }

    redirect_to('departments/dare/import.php');
}

$currentCounts = [];
foreach (['dare_schools', 'dare_officers', 'dare_teachers', 'dare_classes', 'dare_students', 'dare_class_students'] as $table) {
    $currentCounts[$table] = (int) db()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

$actions = [
    ['label' => 'Import preview', 'href' => url('departments/dare/import-preview.php'), 'primary' => true],
    ['label' => 'DARE Home', 'href' => url('departments/dare/index.php')],
    ['label' => 'Cleanup', 'href' => url('departments/dare/cleanup.php')],
];

page_header('Import DARE Access Data');
?>
<main class="shell">
    <section class="panel">
        <?php if ($message = flash('success')): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($message = flash('error')): ?>
            <div class="notice error"><?= e($message) ?></div>
        <?php endif; ?>

        <h1>Import DARE Access Data</h1>
        <p>Import the reviewed Access export into the DARE module. This uses the cleanup rules from the preview page.</p>
        <?php page_actions($actions); ?>
        <p class="meta">Source file: <?= e(DARE_IMPORT_FILE) ?></p>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Current DARE Records</h1>
        <table class="table mobile-card-table">
            <thead>
                <tr>
                    <th>Table</th>
                    <th>Records</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($currentCounts as $table => $count): ?>
                    <tr>
                        <td data-label="Table"><?= e($table) ?></td>
                        <td data-label="Records"><?= e((string) $count) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (($currentCounts['dare_classes'] ?? 0) > 0 || ($currentCounts['dare_students'] ?? 0) > 0): ?>
            <div class="notice warning" style="margin-top: 14px;">DARE class or student records already exist. The importer can update Access-matched records, but cleanup is recommended before the first full import.</div>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <h1>Confirm Import</h1>
        <p>This will write DARE schools, officers, teachers, closed historical classes, students, essay completion, essay winners, and gender values.</p>
        <form class="form compact-form" method="post">
            <label class="span-2">
                Confirmation phrase
                <input name="confirmation" placeholder="<?= e(DARE_IMPORT_CONFIRMATION) ?>" autocomplete="off">
            </label>
            <div class="actions span-2">
                <button type="submit">Import Access data</button>
                <a class="button secondary" href="<?= e(url('departments/dare/import-preview.php')) ?>">Review preview</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
