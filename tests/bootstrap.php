<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'RedundantRequireOnce\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativePath = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $path = dirname(__DIR__) . '/src/' . $relativePath;
    if (is_file($path)) {
        require $path;
    }
});
