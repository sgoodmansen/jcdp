<?php
$config = require __DIR__ . '/config/config.php';
$db = $config['database'];
$pdo = new PDO(
    'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=' . $db['charset'],
    $db['user'],
    $db['password']
);

echo 'lienholders=' . $pdo->query('SELECT COUNT(*) FROM dmv_lienholders')->fetchColumn() . PHP_EOL;
echo 'access_imported=' . $pdo->query('SELECT COUNT(*) FROM dmv_lienholders WHERE access_lienholder_id IS NOT NULL')->fetchColumn() . PHP_EOL;
echo 'with_phone_extension=' . $pdo->query("SELECT COUNT(*) FROM dmv_lienholders WHERE phone_extension <> ''")->fetchColumn() . PHP_EOL;
