<?php
declare(strict_types=1);

namespace LabelPrint;

use LabelPrint\Db\Db;
use LabelPrint\Model\ProfileRegistry;
use LabelPrint\Queue\JobRepository;
use LabelPrint\Support\Log;

/**
 * Долгоживущий процесс, разбирающий очередь рендеринга.
 *
 * Запускать только из CLI и только под присмотром systemd. Под Apache/PHP-FPM
 * такому процессу делать нечего: там жёсткие лимиты времени выполнения,
 * и воркер будет убит на середине рендеринга.
 *
 * Ресурсы освобождаются самым надёжным способом из возможных: после N заданий
 * или T секунд процесс завершается с кодом 0, а systemd поднимает новый.
 * Утечки памяти в долгом PHP-процессе так лечатся гарантированно.
 */
final class Worker
{
    private bool $shouldStop = false;
    private int $jobsDone = 0;
    private int $startedAt;
    private readonly string $identity;

    public function __construct(
        private readonly Db $db,
        private readonly JobRepository $jobs,
        private readonly RenderService $renderer,
        private readonly ProfileRegistry $profiles,
        private readonly Log $log,
        private readonly int $pollIntervalMs = 250,
        private readonly int $maxJobs = 500,
        private readonly int $maxLifetimeSeconds = 3600,
        private readonly int $leaseSeconds = 120,
        private readonly int $backoffBaseSeconds = 5,
        private readonly ?Scanner $scanner = null,
        private readonly int $scanIntervalMs = 1000,
    ) {
        $this->startedAt = time();
        $this->identity = gethostname() . ':' . getmypid();
    }

    public function run(): int
    {
        $this->installSignalHandlers();

        $this->log->info('воркер запущен', [
            'id' => $this->identity,
            'skip_locked' => $this->db->supportsSkipLocked(),
            'profiles' => $this->profiles->codes(),
            'scanner' => $this->scanner !== null,
        ]);

        $nextMaintenance = 0.0;
        $nextScan = 0.0;

        while (!$this->shouldStop) {
            $now = microtime(true);

            // Раз в 30 секунд возвращаем в очередь задания умерших воркеров.
            if ($now >= $nextMaintenance) {
                $released = $this->jobs->releaseExpired();
                if ($released > 0) {
                    $this->log->warning('возвращены задания с истёкшей арендой', ['count' => $released]);
                }
                $nextMaintenance = $now + 30;
            }

            if ($this->scanner !== null && $now >= $nextScan) {
                $this->runScan();
                $nextScan = microtime(true) + $this->scanIntervalMs / 1000;
            }

            if (!$this->processOne()) {
                // Очередь пуста — короткая пауза, чтобы не крутить процессор впустую.
                $this->sleepMs($this->pollIntervalMs);
            }

            if ($this->shouldRecycle()) {
                break;
            }
        }

        // Аккуратная остановка: если задание было захвачено, оно уже завершено
        // или помечено ошибкой, поэтому просто отпускаем всё, что могло зависнуть.
        $stuck = $this->jobs->releaseOwner($this->identity);

        $this->log->info('воркер остановлен', [
            'id' => $this->identity,
            'jobs' => $this->jobsDone,
            'uptime_sec' => time() - $this->startedAt,
            'released' => $stuck,
        ]);

        return 0;
    }

    /** @return bool true, если задание было взято в работу */
    private function processOne(): bool
    {
        try {
            $job = $this->jobs->claim($this->identity, $this->leaseSeconds);
        } catch (\Throwable $e) {
            $this->log->error('не удалось захватить задание', ['error' => $e->getMessage()]);
            $this->sleepMs(1000);

            return false;
        }

        if ($job === null) {
            return false;
        }

        $started = hrtime(true);

        try {
            $profile = $this->profiles->get($job->profileCode);
            $result = $this->renderer->renderJob($job, $profile);

            $this->jobs->complete($job, (int) ((hrtime(true) - $started) / 1_000_000));
            $this->jobsDone++;

            $this->log->debug('задание выполнено', [
                'job' => $job->id,
                'path' => $job->path,
                'pages' => $result['pages'],
                'cached' => $result['cached'],
                'ms' => $result['ms'],
            ]);
        } catch (\Throwable $e) {
            $willRetry = $this->jobs->fail($job, $e->getMessage(), $this->backoffBaseSeconds);

            $this->log->error('задание провалено', [
                'job' => $job->id,
                'path' => $job->path,
                'attempt' => $job->attempts,
                'retry' => $willRetry,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function runScan(): void
    {
        try {
            $result = $this->scanner?->scan();
            if ($result !== null && $result['enqueued'] > 0) {
                $this->log->info('сканирование каталога', $result);
            }
        } catch (\Throwable $e) {
            $this->log->error('сканирование не удалось', ['error' => $e->getMessage()]);
        }
    }

    private function shouldRecycle(): bool
    {
        if ($this->maxJobs > 0 && $this->jobsDone >= $this->maxJobs) {
            $this->log->info('достигнут лимит заданий, процесс перезапускается', ['jobs' => $this->jobsDone]);

            return true;
        }

        if ($this->maxLifetimeSeconds > 0 && time() - $this->startedAt >= $this->maxLifetimeSeconds) {
            $this->log->info('достигнут лимит времени жизни, процесс перезапускается', [
                'uptime_sec' => time() - $this->startedAt,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Сон с проверкой сигналов: длинный usleep задержал бы остановку сервиса
     * и systemd в итоге прибил бы процесс по таймауту.
     */
    private function sleepMs(int $ms): void
    {
        $deadline = microtime(true) + $ms / 1000;
        while (!$this->shouldStop && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->log->warning('расширение pcntl недоступно: аккуратная остановка не гарантируется');

            return;
        }

        pcntl_async_signals(true);

        $handler = function (int $signal): void {
            $this->shouldStop = true;
            $this->log->info('получен сигнал, завершаем текущее задание', ['signal' => $signal]);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }
}
