<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Helpers\Logger;

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);
$pdo = Connection::get($config['database']);
$logger = new Logger($pdo);
