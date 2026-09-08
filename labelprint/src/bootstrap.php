<?php
declare(strict_types=1);

/**
 * Единая точка входа: автозагрузчик PSR-4 для пространства имён LabelPrint\.
 * Composer намеренно не используется — сервис должен разворачиваться копированием
 * каталога на любой Ubuntu-сервер с PHP 8.1+ и без доступа к интернету.
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "labelprint требует PHP 8.1 или новее, запущен " . PHP_VERSION . "\n");
    exit(1);
}

const LABELPRINT_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = 'LabelPrint\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
