<?php
// Core tests need no Craft, only the two libraries and this package's own classes.
$autoload = getenv('PORTERO_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
require $autoload;
require __DIR__ . '/Core/Support.php';
spl_autoload_register(function (string $class) {
    $prefix = 'facilo\\portero\\tests\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/' . strtr(substr($class, strlen($prefix)), '\\', '/') . '.php';
    }
});
