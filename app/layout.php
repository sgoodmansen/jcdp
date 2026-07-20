<?php

declare(strict_types=1);

function page_header(string $title): void
{
    $user = current_user();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | <?= e(app_name()) ?></title>
        <link rel="stylesheet" href="<?= e(url('assets/styles.css')) ?>">
    </head>
    <body>
        <header class="topbar">
            <a class="brand" href="<?= e(url('dashboard.php')) ?>">
                <span class="brand-mark">JC</span>
                <span><?= e(app_name()) ?></span>
            </a>
            <?php if ($user): ?>
                <nav class="nav">
                    <a href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
                    <a href="<?= e(url('departments/index.php')) ?>">Departments</a>
                    <?php if ($user['role'] === 'system_admin'): ?>
                        <a href="<?= e(url('admin/users.php')) ?>">Admin</a>
                    <?php endif; ?>
                    <a href="<?= e(url('logout.php')) ?>">Sign out</a>
                </nav>
            <?php endif; ?>
        </header>
    <?php
}

function page_footer(): void
{
    ?>
        <footer class="footer">
            <span>Jefferson County internal use</span>
        </footer>
    </body>
    </html>
    <?php
}

function status_badge(string $role): string
{
    $labels = [
        'standard_user' => 'Standard User',
        'department_admin' => 'Department Admin',
        'system_admin' => 'IT System Admin',
    ];

    return $labels[$role] ?? $role;
}
