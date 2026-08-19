<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configPath = __DIR__ . '/../config/config.php';

if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/../config/config.example.php';
}

$config = require $configPath;

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/dmv.php';
require_once __DIR__ . '/dare.php';
require_once __DIR__ . '/election.php';
require_once __DIR__ . '/k9.php';
require_once __DIR__ . '/sheriff_training.php';
require_once __DIR__ . '/layout.php';
