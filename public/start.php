<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$startPath = user_start_department_path();

if ($startPath !== null) {
    redirect_to($startPath);
}

redirect_to('dashboard.php');
