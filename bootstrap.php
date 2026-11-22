<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
$_ENV += getenv();
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');
