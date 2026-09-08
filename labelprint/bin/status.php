#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Состояние очереди и хранилища: php bin/status.php [--failures] [--retry-failed] */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/status.php запускается только из командной строки\n");
}

require __DIR__ . '/../src/bootstrap.php';

use LabelPrint\App;

$options = getopt('', ['failures', 'retry-failed', 'config:']);
$app = App::boot(isset($options['config']) ? (string) $options['config'] : null);

$jobs = $app->jobs();
$labels = $app->labels();

if (isset($options['retry-failed'])) {
    printf("Возвращено в очередь: %d\n\n", $jobs->retryFailed());
}

$counts = $jobs->counts();
$stats = $labels->stats();

echo "Очередь\n";
printf("  ожидают:     %d\n", $counts['pending']);
printf("  в работе:    %d\n", $counts['running']);
printf("  готово:      %d\n", $counts['done']);
printf("  ошибки:      %d\n", $counts['failed']);

echo "\nХранилище ZPL\n";
printf("  этикеток:    %s\n", number_format($stats['labels']));
printf("  объём:       %s МБ\n", number_format($stats['bytes'] / 1048576, 1));
printf("  средний ZPL: %s байт\n", number_format($stats['avg_bytes']));
printf("  среднее время рендеринга: %d мс\n", $stats['avg_render_ms']);

if (isset($options['failures'])) {
    $failures = $jobs->recentFailures();
    if ($failures === []) {
        echo "\nОшибок нет.\n";
    } else {
        echo "\nПоследние ошибки\n";
        foreach ($failures as $row) {
            printf(
                "  #%d %s [%s] попыток %d\n      %s\n",
                $row['id'],
                $row['path'],
                $row['profile_code'],
                $row['attempts'],
                trim((string) $row['error_message']),
            );
        }
    }
}
