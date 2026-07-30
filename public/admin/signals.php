<?php
declare(strict_types=1);

use App\Auth\AdminAuth;

require dirname(__DIR__, 2) . '/bootstrap.php';
$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
$auth->requireLogin();
$title = 'Сигналы';
require dirname(__DIR__, 2) . '/templates/admin_placeholder.php';
