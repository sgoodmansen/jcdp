<?php

declare(strict_types=1);

function dare_update_class_statuses(): void
{
    $statement = db()->prepare(
        'UPDATE dare_classes
         SET status = "completed"
         WHERE status = "active"
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

function dare_split_student_name(string $name): array
{
    $name = title_case_name($name);
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
