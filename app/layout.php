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
        <script>
            (function () {
                const savedTheme = localStorage.getItem('jcdp-theme');
                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.dataset.theme = savedTheme || (prefersDark ? 'dark' : 'light');
            })();
        </script>
        <link rel="stylesheet" href="<?= e(url('assets/styles.css?v=20260730e')) ?>">
    </head>
    <body>
        <header class="topbar">
            <a class="brand" href="<?= e(url('dashboard.php')) ?>">
                <span class="brand-mark">JC</span>
                <span><?= e(app_name()) ?></span>
            </a>
            <div class="topbar-right">
                <?php if ($user): ?>
                    <nav class="nav desktop-nav">
                        <a href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
                        <a href="<?= e(url('account.php')) ?>">My Account</a>
                        <?php if ($user['role'] === 'system_admin'): ?>
                            <a href="<?= e(url('admin/users.php')) ?>">Admin</a>
                            <a href="<?= e(url('admin/audit-log.php')) ?>">Audit log</a>
                        <?php endif; ?>
                        <a href="<?= e(url('logout.php')) ?>">Sign out</a>
                    </nav>
                    <details class="site-menu">
                        <summary>
                            <span class="hamburger-lines" aria-hidden="true"></span>
                            Menu
                        </summary>
                        <nav class="site-menu-list">
                            <a href="<?= e(url('dashboard.php')) ?>">Dashboard</a>
                            <a href="<?= e(url('account.php')) ?>">My Account</a>
                            <?php if ($user['role'] === 'system_admin'): ?>
                                <a href="<?= e(url('admin/users.php')) ?>">Admin</a>
                                <a href="<?= e(url('admin/audit-log.php')) ?>">Audit log</a>
                            <?php endif; ?>
                            <a href="<?= e(url('logout.php')) ?>">Sign out</a>
                        </nav>
                    </details>
                <?php endif; ?>
                <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Switch color mode">Dark</button>
            </div>
        </header>
    <?php
}

function page_footer(): void
{
    ?>
        <footer class="footer">
            <span>Jefferson County internal use</span>
        </footer>
        <script>
            (function () {
                const button = document.getElementById('theme-toggle');
                if (!button) {
                    return;
                }

                const setButtonText = function () {
                    const isDark = document.documentElement.dataset.theme === 'dark';
                    button.textContent = isDark ? 'Light' : 'Dark';
                    button.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
                };

                button.addEventListener('click', function () {
                    const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                    document.documentElement.dataset.theme = nextTheme;
                    localStorage.setItem('jcdp-theme', nextTheme);
                    setButtonText();
                });

                setButtonText();
            })();
        </script>
        <script>
            (function () {
                document.querySelectorAll('input[type="password"]').forEach(function (input, index) {
                    if (input.closest('.password-field')) {
                        return;
                    }

                    const wrapper = document.createElement('span');
                    wrapper.className = 'password-field';
                    input.parentNode.insertBefore(wrapper, input);
                    wrapper.appendChild(input);

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'password-toggle';
                    button.setAttribute('aria-label', 'Show password');
                    button.setAttribute('aria-pressed', 'false');
                    button.setAttribute('title', 'Show password');
                    button.innerHTML = '<span aria-hidden="true"></span>';

                    const inputId = input.id || 'password-field-' + index;
                    input.id = inputId;
                    button.setAttribute('aria-controls', inputId);

                    button.addEventListener('click', function () {
                        const showing = input.type === 'text';
                        input.type = showing ? 'password' : 'text';
                        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                        button.setAttribute('aria-pressed', showing ? 'false' : 'true');
                        button.setAttribute('title', showing ? 'Show password' : 'Hide password');
                    });

                    wrapper.appendChild(button);
                });
            })();
        </script>
    </body>
    </html>
    <?php
}

function status_badge(string $role): string
{
    $labels = [
        'standard_user' => 'Standard User',
        'department_admin' => 'Department Supervisor',
        'system_admin' => 'IT System Admin',
    ];

    return $labels[$role] ?? $role;
}

function friendly_user_title(array $user): string
{
    $departments = $user['department_names'] ?? '';

    if ($user['role'] === 'standard_user' && $departments !== '') {
        return match ($departments) {
            'DMV' => 'DMV Clerk',
            'DARE' => 'DARE Officer',
            default => 'Department User',
        };
    }

    if ($user['role'] === 'department_admin' && $departments !== '') {
        return match ($departments) {
            'DMV' => 'DMV Supervisor',
            'DARE' => 'DARE Supervisor',
            default => 'Department Supervisor',
        };
    }

    return status_badge($user['role']);
}

function page_actions(array $actions): void
{
    ?>
    <div class="responsive-actions" style="margin-bottom: 18px;">
        <div class="actions desktop-page-actions">
            <?php foreach ($actions as $action): ?>
                <a class="button<?= !empty($action['primary']) ? '' : ' secondary' ?>" href="<?= e($action['href']) ?>"><?= e($action['label']) ?></a>
            <?php endforeach; ?>
        </div>

        <div class="mobile-page-actions">
            <?php foreach ($actions as $action): ?>
                <?php if (!empty($action['primary'])): ?>
                    <a class="button" href="<?= e($action['href']) ?>"><?= e($action['label']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>

            <details class="mobile-actions-menu">
                <summary>
                    <span class="hamburger-lines" aria-hidden="true"></span>
                    Actions
                </summary>
                <div class="mobile-actions-list">
                    <?php foreach ($actions as $action): ?>
                        <?php if (empty($action['primary'])): ?>
                            <a href="<?= e($action['href']) ?>"><?= e($action['label']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    </div>
    <?php
}
