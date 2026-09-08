#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Воркер рендеринга: разбирает очередь render_jobs, кладёт готовый ZPL в MySQL.
 *
 * Запуск:
 *   php bin/worker.php                # только рендеринг
 *   php bin/worker.php --with-scanner # ещё и обход /upload/pdf (для одного процесса)
 *   php bin/worker.php --once         # обработать всё, что есть, и выйти
 *
 * В продакшене запускается через systemd: см. deploy/labelprint-worker@.service
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/worker.php запускается только из командной строки\n");
}

require __DIR__ . '/../src/bootstrap.php';

use LabelPrint\App;

$options = getopt('', ['with-scanner', 'once', 'config:', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
    Воркер рендеринга PDF -> ZPL.

      --with-scanner   дополнительно обходить каталог с PDF и ставить новые файлы в очередь
      --once           обработать текущую очередь и выйти (для cron или отладки)
      --config=ПУТЬ    альтернативный config.php
      --help           эта справка

    TXT;
    exit(0);
}

try {
    $app = App::boot(isset($options['config']) ? (string) $options['config'] : null);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка конфигурации: ' . $e->getMessage() . "\n");
    exit(2);
}

$log = $app->log('worker');

try {
    if (isset($options['once'])) {
        $jobs = $app->jobs();
        $renderer = $app->renderer();
        $profiles = $app->profiles();
        $identity = gethostname() . ':' . getmypid();
        $done = 0;
        $failed = 0;

        if (isset($options['with-scanner'])) {
            $result = $app->scanner()->scan();
            $log->info('сканирование завершено', $result);
        }

        while (($job = $jobs->claim($identity, $app->config->int('worker.lease_sec', 120))) !== null) {
            $started = hrtime(true);
            try {
                $renderer->renderJob($job, $profiles->get($job->profileCode));
                $jobs->complete($job, (int) ((hrtime(true) - $started) / 1_000_000));
                $done++;
            } catch (Throwable $e) {
                $jobs->fail($job, $e->getMessage(), $app->config->int('worker.backoff_base_sec', 5));
                $log->error('задание провалено', ['job' => $job->id, 'error' => $e->getMessage()]);
                $failed++;
            }
        }

        $log->info('разовый прогон завершён', ['done' => $done, 'failed' => $failed]);
        exit($failed > 0 ? 1 : 0);
    }

    exit($app->worker(isset($options['with-scanner']))->run());
} catch (Throwable $e) {
    $log->error('воркер аварийно завершился', [
        'error' => $e->getMessage(),
        'where' => $e->getFile() . ':' . $e->getLine(),
    ]);
    exit(1);
}
