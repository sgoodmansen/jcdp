<?php

declare(strict_types=1);

function app_name(): string
{
    global $config;
    return $config['app']['name'];
}

function url(string $path = ''): string
{
    global $config;
    $base = rtrim($config['app']['base_url'], '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $value = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $value;
}
