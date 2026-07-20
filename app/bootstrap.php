<?php

declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/../config/config.php';

if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/../config/config.example.php';
}

$config = require $configPath;

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
