<?php

declare(strict_types=1);

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT users.*, departments.name AS department_name
         FROM users
         LEFT JOIN departments ON departments.id = users.department_id
         WHERE users.id = :id AND users.is_active = 1'
    );
    $statement->execute(['id' => $_SESSION['user_id']]);
    $user = $statement->fetch();

    return $user ?: null;
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

    if (empty($user['department_id'])) {
        return false;
    }

    $statement = db()->prepare('SELECT id FROM departments WHERE slug = :slug AND id = :id');
    $statement->execute([
        'slug' => $slug,
        'id' => $user['department_id'],
    ]);

    return (bool) $statement->fetch();
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
