#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Обход каталога с PDF и постановка новых файлов в очередь.
 *
 *   php bin/scan.php            # один проход и выход (подходит для cron/systemd timer)
 *   php bin/scan.php --watch    # постоянный обход с интервалом scanner.interval_ms
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/scan.php запускается только из командной строки\n");
}

require __DIR__ . '/../src/bootstrap.php';

use LabelPrint\App;

$options = getopt('', ['watch', 'config:', 'help']);

if (isset($options['help'])) {
    echo "Обход каталога с PDF.\n\n  --watch        не выходить, обходить каталог постоянно\n"
        . "  --config=ПУТЬ  альтернативный config.php\n\n";
    exit(0);
}

$app = App::boot(isset($options['config']) ? (string) $options['config'] : null);
$log = $app->log('scan');
$scanner = $app->scanner();

if (!isset($options['watch'])) {
    $result = $scanner->scan();
    $log->info('сканирование завершено', $result);
    exit(0);
}

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stop): void {
        $stop = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$intervalUs = max(100, $app->config->int('scanner.interval_ms', 1000)) * 1000;
$log->info('наблюдение за каталогом запущено', [
    'dir' => $app->config->string('pdf_dir'),
    'interval_ms' => (int) ($intervalUs / 1000),
]);

while (!$stop) {
    try {
        $result = $scanner->scan();
        if ($result['enqueued'] > 0) {
            $log->info('поставлены в очередь новые файлы', $result);
        }
    } catch (Throwable $e) {
        $log->error('ошибка сканирования', ['error' => $e->getMessage()]);
    }

    $deadline = microtime(true) + $intervalUs / 1_000_000;
    while (!$stop && microtime(true) < $deadline) {
        usleep(50_000);
    }
}

$log->info('наблюдение остановлено');
