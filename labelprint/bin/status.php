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
use LabelPrint\Support\Args;

try {
    $options = Args::parse($argv, ['failures', 'retry-failed', 'retry-dead'], ['config']);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$app = App::boot($options->value('config'));

$jobs = $app->jobs();
$labels = $app->labels();

if ($options->has('retry-failed') || $options->has('retry-dead')) {
    printf("Возвращено в очередь: %d\n\n", $jobs->retryFailed(null, $options->has('retry-dead')));
}

$counts = $jobs->counts();
$stats = $labels->stats();

echo "Очередь\n";
printf("  ожидают:     %d\n", $counts['pending']);
printf("  в работе:    %d\n", $counts['running']);
printf("  готово:      %d\n", $counts['done']);
printf("  ошибки:      %d\n", $counts['failed']);
printf("  тупиковые:   %d%s\n", $counts['dead'], $counts['dead'] > 0 ? '  <- требуют разбора' : '');

echo "\nХранилище ZPL\n";
printf("  этикеток:    %s\n", number_format($stats['labels']));
printf("  объём:       %s МБ\n", number_format($stats['bytes'] / 1048576, 1));
printf("  средний ZPL: %s байт\n", number_format($stats['avg_bytes']));
printf("  среднее время рендеринга: %d мс\n", $stats['avg_render_ms']);

if ($options->has('failures')) {
    $failures = $jobs->recentFailures();
    if ($failures === []) {
        echo "\nОшибок нет.\n";
    } else {
        echo "\nПоследние ошибки\n";
        foreach ($failures as $row) {
            printf(
                "  #%d %s [%s] %s, попыток %d\n      %s\n",
                $row['id'],
                $row['path'],
                $row['profile_code'],
                $row['state'] === 'dead' ? 'ТУПИК' : 'ошибка',
                $row['attempts'],
                trim((string) $row['error_message']),
            );
        }
    }
}
