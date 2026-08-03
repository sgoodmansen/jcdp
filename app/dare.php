<?php

declare(strict_types=1);

function dare_update_class_statuses(): void
{
    $statement = db()->prepare(
        'UPDATE dare_classes
         SET status = "completed"
         WHERE status = "active"
           AND end_date IS NOT NULL
           AND end_date < CURDATE()'
    );
    $statement->execute();
}

function dare_class_status_options(): array
{
    return [
        'active' => 'Active',
        'completed' => 'Completed',
        'graduated' => 'Graduated',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];
}

function dare_person_name(array $person): string
{
    return trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
}

function dare_class_status_label(string $status): string
{
    return dare_class_status_options()[$status] ?? ucfirst($status);
}

function dare_class_label(array $class): string
{
    $parts = array_filter([
        trim($class['school_year'] ?? ''),
        trim($class['semester'] ?? ''),
        trim($class['period'] ?? ''),
    ]);

    return $parts ? implode(' - ', $parts) : ($class['class_name'] ?? 'DARE Class');
}

function dare_class_end_countdown(?string $endDate): string
{
    $endDate = trim((string) $endDate);

    if ($endDate === '') {
        return 'No end date';
    }

    try {
        $today = new DateTimeImmutable('today');
        $end = new DateTimeImmutable($endDate);
    } catch (Throwable) {
        return 'Invalid end date';
    }

    $days = (int) $today->diff($end)->format('%r%a');

    if ($days >= 7) {
        $weeks = (int) ceil($days / 7);
        return $weeks === 1 ? '1 week left' : $weeks . ' weeks left';
    }

    if ($days > 0) {
        return 'Less than 1 week left';
    }

    if ($days === 0) {
        return 'Ends today';
    }

    $daysPast = abs($days);
    if ($daysPast < 7) {
        return 'Ended less than 1 week ago';
    }

    $weeksPast = (int) floor($daysPast / 7);
    return $weeksPast === 1 ? 'Ended 1 week ago' : 'Ended ' . $weeksPast . ' weeks ago';
}

function dare_seed_class_lessons(int $classId): void
{
    $statement = db()->prepare(
        'INSERT IGNORE INTO dare_class_lessons (class_id, lesson_id, lesson_title, sort_order)
         SELECT :class_id, id, title, sort_order
         FROM dare_lessons
         WHERE is_active = 1
         ORDER BY sort_order, title'
    );
    $statement->execute(['class_id' => $classId]);
}

function dare_lesson_progress(int $classId): array
{
    $statement = db()->prepare(
        'SELECT
            COUNT(*) AS total_lessons,
            SUM(completed_at IS NOT NULL) AS completed_lessons
         FROM dare_class_lessons
         WHERE class_id = :class_id'
    );
    $statement->execute(['class_id' => $classId]);
    $progress = $statement->fetch() ?: ['total_lessons' => 0, 'completed_lessons' => 0];

    return [
        'total' => (int) ($progress['total_lessons'] ?? 0),
        'completed' => (int) ($progress['completed_lessons'] ?? 0),
    ];
}

function dare_split_student_name(string $name): array
{
    $name = preserve_name_case($name);
    $parts = preg_split('/\s+/', trim($name));

    if (!$parts || count($parts) === 1) {
        return ['first_name' => $name, 'last_name' => ''];
    }

    $lastName = array_pop($parts);

    return [
        'first_name' => implode(' ', $parts),
        'last_name' => $lastName,
    ];
}

function dare_current_officer_id(array $user): ?int
{
    $statement = db()->prepare(
        'SELECT id
         FROM dare_officers
         WHERE user_id = :user_id
            OR LOWER(email) = LOWER(:email)
         ORDER BY user_id IS NULL
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $user['id'],
        'email' => $user['email'],
    ]);
    $officerId = $statement->fetchColumn();

    return $officerId ? (int) $officerId : null;
}

function dare_setting(string $key, string $default = ''): string
{
    $statement = db()->prepare('SELECT setting_value FROM dare_settings WHERE setting_key = :setting_key');
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function dare_save_setting(string $key, string $value): void
{
    $statement = db()->prepare(
        'INSERT INTO dare_settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

function dare_next_lessons_for_user(array $user, int $limit = 10): array
{
    $currentOfficerId = dare_current_officer_id($user);

    if (!$currentOfficerId && !can_manage_department('dare')) {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $where = 'WHERE dare_classes.status IN ("active", "completed")';
    $params = [];

    if (!can_manage_department('dare') && $currentOfficerId) {
        $where .= ' AND dare_classes.officer_id = :officer_id';
        $params['officer_id'] = $currentOfficerId;
    }

    $statement = db()->prepare(
        "SELECT
            dare_classes.id AS class_id,
            dare_classes.school_year,
            dare_classes.semester,
            dare_classes.period,
            dare_schools.name AS school_name,
            dare_teachers.first_name AS teacher_first_name,
            dare_teachers.last_name AS teacher_last_name,
            dare_officers.first_name AS officer_first_name,
            dare_officers.last_name AS officer_last_name,
            dare_class_lessons.id AS class_lesson_id,
            dare_class_lessons.lesson_title,
            dare_class_lessons.sort_order
         FROM dare_classes
         INNER JOIN dare_schools ON dare_schools.id = dare_classes.school_id
         LEFT JOIN dare_teachers ON dare_teachers.id = dare_classes.teacher_id
         LEFT JOIN dare_officers ON dare_officers.id = dare_classes.officer_id
         INNER JOIN dare_class_lessons ON dare_class_lessons.class_id = dare_classes.id
         $where
           AND dare_class_lessons.completed_at IS NULL
           AND NOT EXISTS (
                SELECT 1
                FROM dare_class_lessons earlier_lessons
                WHERE earlier_lessons.class_id = dare_classes.id
                  AND earlier_lessons.completed_at IS NULL
                  AND (
                    earlier_lessons.sort_order < dare_class_lessons.sort_order
                    OR (earlier_lessons.sort_order = dare_class_lessons.sort_order AND earlier_lessons.id < dare_class_lessons.id)
                  )
           )
         ORDER BY dare_classes.end_date IS NULL, dare_classes.end_date, dare_schools.name, dare_classes.period
         LIMIT $limit"
    );
    $statement->execute($params);

    return $statement->fetchAll();
}
