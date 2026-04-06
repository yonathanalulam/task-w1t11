<?php

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] ??= 'test';
$_ENV['APP_ENV'] ??= 'test';
$_SERVER['APP_DEBUG'] ??= '1';
$_ENV['APP_DEBUG'] ??= '1';

if ((bool) ($_SERVER['APP_DEBUG'] ?? false)) {
    umask(0000);
}
