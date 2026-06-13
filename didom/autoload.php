<?php

spl_autoload_register(static function ($class): void {
    $prefix = 'DiDom\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'DiDom' . DIRECTORY_SEPARATOR . $relativePath;

    if (file_exists($file)) {
        require_once $file;
    }
});
