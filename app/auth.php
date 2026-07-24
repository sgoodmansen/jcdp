<?php

declare(strict_types=1);

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE id = :id AND is_active = 1');
    $statement->execute(['id' => $_SESSION['user_id']]);
    $user = $statement->fetch();

    if (!$user) {
        return null;
    }

    $departments = departments_for_user((int) $user['id']);
    $departmentNames = array_column($departments, 'name');

    $user['departments'] = $departments;
    $user['department_names'] = implode(', ', $departmentNames);
    $user['department_name'] = $user['department_names'];

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_system_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'system_admin';
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect_to('login.php');
    }
}

function require_system_admin(): void
{
    require_login();

    if (!is_system_admin()) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to view this page.</p></section></main>';
        page_footer();
        exit;
    }
}

function can_access_department(string $slug): bool
{
    $user = current_user();

    if ($user === null) {
        return false;
    }

    if ($user['role'] === 'system_admin') {
        return true;
    }

    $statement = db()->prepare(
        'SELECT departments.id
         FROM departments
         INNER JOIN user_departments ON user_departments.department_id = departments.id
         WHERE departments.slug = :slug
           AND user_departments.user_id = :user_id'
    );
    $statement->execute([
        'slug' => $slug,
        'user_id' => $user['id'],
    ]);

    return (bool) $statement->fetch();
}

function departments_for_user(int $userId): array
{
    $statement = db()->prepare(
        'SELECT departments.*
         FROM departments
         INNER JOIN user_departments ON user_departments.department_id = departments.id
         WHERE user_departments.user_id = :user_id
         ORDER BY departments.name'
    );
    $statement->execute(['user_id' => $userId]);
    $departments = $statement->fetchAll();

    if ($departments) {
        return $departments;
    }

    $statement = db()->prepare(
        'SELECT departments.*
         FROM users
         INNER JOIN departments ON departments.id = users.department_id
         WHERE users.id = :user_id
         ORDER BY departments.name'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

function department_ids_for_user(int $userId): array
{
    return array_map(fn($department) => (int) $department['id'], departments_for_user($userId));
}

function sync_user_departments(int $userId, array $departmentIds): void
{
    $departmentIds = array_values(array_unique(array_filter(array_map('intval', $departmentIds))));

    $statement = db()->prepare('DELETE FROM user_departments WHERE user_id = :user_id');
    $statement->execute(['user_id' => $userId]);

    if (!$departmentIds) {
        return;
    }

    $statement = db()->prepare(
        'INSERT INTO user_departments (user_id, department_id)
         SELECT :user_id, id
         FROM departments
         WHERE id = :department_id'
    );

    foreach ($departmentIds as $departmentId) {
        $statement->execute([
            'user_id' => $userId,
            'department_id' => $departmentId,
        ]);
    }
}

function require_department_access(string $slug): void
{
    require_login();

    if (!can_access_department($slug)) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to view this department module.</p></section></main>';
        page_footer();
        exit;
    }
}

function can_manage_department(string $slug): bool
{
    $user = current_user();

    if ($user === null) {
        return false;
    }

    if ($user['role'] === 'system_admin') {
        return true;
    }

    return $user['role'] === 'department_admin' && can_access_department($slug);
}

function require_department_manager(string $slug): void
{
    require_login();

    if (!can_manage_department($slug)) {
        http_response_code(403);
        page_header('Access denied');
        echo '<main class="shell"><section class="panel"><h1>Access denied</h1><p>You do not have permission to manage this department module.</p></section></main>';
        page_footer();
        exit;
    }
}

function attempt_login(string $email, string $password): bool
{
    $statement = db()->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = $user['id'];
    session_regenerate_id(true);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
