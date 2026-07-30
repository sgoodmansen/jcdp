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

function auth_client_ip(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function auth_email_hash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function login_is_throttled(string $email): bool
{
    $emailHash = auth_email_hash($email);
    $ipAddress = auth_client_ip();

    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM login_attempts
         WHERE was_success = 0
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
           AND (email_hash = :email_hash OR request_ip = :request_ip)'
    );
    $statement->execute([
        'email_hash' => $emailHash,
        'request_ip' => $ipAddress,
    ]);

    return (int) $statement->fetchColumn() >= 8;
}

function record_login_attempt(string $email, bool $wasSuccess): void
{
    $statement = db()->prepare(
        'INSERT INTO login_attempts (email_hash, request_ip, was_success)
         VALUES (:email_hash, :request_ip, :was_success)'
    );
    $statement->execute([
        'email_hash' => auth_email_hash($email),
        'request_ip' => auth_client_ip(),
        'was_success' => $wasSuccess ? 1 : 0,
    ]);

    if ($wasSuccess) {
        $statement = db()->prepare(
            'DELETE FROM login_attempts
             WHERE email_hash = :email_hash
                OR request_ip = :request_ip'
        );
        $statement->execute([
            'email_hash' => auth_email_hash($email),
            'request_ip' => auth_client_ip(),
        ]);
    }
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
    if (login_is_throttled($email)) {
        audit_event('login_throttled', 'user', 'unknown', [
            'email_hash' => auth_email_hash($email),
            'request_ip' => auth_client_ip(),
        ]);
        return false;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($email, false);
        return false;
    }

    record_login_attempt($email, true);
    $_SESSION['user_id'] = $user['id'];
    session_regenerate_id(true);
    return true;
}

function password_reset_is_throttled(string $email): bool
{
    $emailHash = auth_email_hash($email);
    $ipAddress = auth_client_ip();

    $statement = db()->prepare(
        'SELECT COUNT(*)
         FROM password_reset_requests
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
           AND (email_hash = :email_hash OR request_ip = :request_ip)'
    );
    $statement->execute([
        'email_hash' => $emailHash,
        'request_ip' => $ipAddress,
    ]);

    return (int) $statement->fetchColumn() >= 5;
}

function record_password_reset_request(string $email): void
{
    $statement = db()->prepare(
        'INSERT INTO password_reset_requests (email_hash, request_ip)
         VALUES (:email_hash, :request_ip)'
    );
    $statement->execute([
        'email_hash' => auth_email_hash($email),
        'request_ip' => auth_client_ip(),
    ]);
}

function send_password_reset_email(array $user, string $resetUrl): bool
{
    global $config;

    $from = $config['password_reset']['from_email'] ?? 'no-reply@jeffersoncounty.local';
    $subject = app_name() . ' password reset';
    $message = "A password reset was requested for your Jefferson County Data Portal account.\n\n"
        . "Use this link to choose a new password. The link expires in 30 minutes:\n\n"
        . $resetUrl . "\n\n"
        . "If you did not request this, you can ignore this email.";
    $headers = 'From: ' . $from;

    return @mail((string) $user['email'], $subject, $message, $headers);
}

function create_password_reset_for_email(string $email): ?string
{
    global $config;

    if (password_reset_is_throttled($email)) {
        record_password_reset_request($email);
        audit_event('password_reset_throttled', 'user', 'unknown', [
            'email_hash' => auth_email_hash($email),
            'request_ip' => auth_client_ip(),
        ]);
        return null;
    }

    record_password_reset_request($email);

    $statement = db()->prepare('SELECT * FROM users WHERE email = :email AND is_active = 1');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if (!$user) {
        return null;
    }

    $statement = db()->prepare(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = :user_id
           AND used_at IS NULL'
    );
    $statement->execute(['user_id' => $user['id']]);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $resetUrl = absolute_url('reset-password.php?token=' . urlencode($token));

    $statement = db()->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, request_ip, expires_at)
         VALUES (:user_id, :token_hash, :request_ip, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );
    $statement->execute([
        'user_id' => $user['id'],
        'token_hash' => $tokenHash,
        'request_ip' => auth_client_ip(),
    ]);

    $emailSent = send_password_reset_email($user, $resetUrl);
    audit_event('password_reset_requested', 'user', (string) $user['id'], [
        'email_sent' => $emailSent,
        'request_ip' => auth_client_ip(),
    ]);

    return ($config['password_reset']['show_test_link'] ?? false) ? $resetUrl : null;
}

function find_valid_password_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT password_reset_tokens.*, users.email, users.first_name, users.last_name
         FROM password_reset_tokens
         INNER JOIN users ON users.id = password_reset_tokens.user_id
         WHERE password_reset_tokens.token_hash = :token_hash
           AND password_reset_tokens.used_at IS NULL
           AND password_reset_tokens.expires_at >= NOW()
           AND users.is_active = 1
         LIMIT 1'
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    $reset = $statement->fetch();

    return $reset ?: null;
}

function complete_password_reset(string $token, string $newPassword): bool
{
    $reset = find_valid_password_reset($token);

    if (!$reset) {
        return false;
    }

    db()->beginTransaction();

    $statement = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $statement->execute([
        'id' => $reset['user_id'],
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
    ]);

    $statement = db()->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id');
    $statement->execute(['id' => $reset['id']]);

    $statement = db()->prepare('DELETE FROM login_attempts WHERE email_hash = :email_hash');
    $statement->execute(['email_hash' => auth_email_hash((string) $reset['email'])]);

    db()->commit();

    audit_event('password_reset_completed', 'user', (string) $reset['user_id'], [
        'request_ip' => auth_client_ip(),
    ]);

    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
