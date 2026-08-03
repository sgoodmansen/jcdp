<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

unset($_SESSION['election_worker_id']);
unset($_SESSION['election_assignment_id']);
redirect_to('login.php');
