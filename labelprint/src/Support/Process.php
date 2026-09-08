<?php
declare(strict_types=1);

namespace LabelPrint\Support;

/**
 * Запуск внешней программы с одновременным чтением stdout и stderr.
 *
 * Читать оба канала параллельно обязательно. Буфер канала в Linux — 64 КБ:
 * если вычитывать stdout до конца, а stderr не трогать, то программа, написавшая
 * в stderr больше 64 КБ, навсегда заблокируется на write(2), не закроет stdout,
 * и родительский процесс навсегда заблокируется на чтении. Классический
 * взаимный клинч, и SIGTERM его не разрывает: воркер повисает мёртво.
 *
 * Ровно так вешался разбор метаданных на PDF, где pdfinfo сыпет сотнями
 * килобайт «Syntax Error: ...» в stderr.
 */
final class Process
{
    /**
     * @param  list<string> $command
     * @param  int          $timeoutSeconds жёсткий предел; по истечении процесс убивается
     * @return array{stdout:string,stderr:string,code:int}
     */
    public static function run(array $command, int $timeoutSeconds = 30, int $maxOutputBytes = 268_435_456): array
    {
        $descriptors = [
            // Явно закрываем стандартный ввод: иначе дочерний процесс может
            // заблокироваться на чтении унаследованного терминала.
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить процесс: ' . ($command[0] ?? '?'));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                self::kill($process, $open);

                throw new \RuntimeException(sprintf(
                    '%s не уложился в %d с и был остановлен',
                    basename($command[0] ?? 'процесс'),
                    $timeoutSeconds,
                ));
            }

            $read = array_values($open);
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, (int) $remaining, 200_000);

            // false бывает и при штатном прерывании сигналом (EINTR) — это не ошибка,
            // просто пробуем снова, пока не вышел срок.
            if ($ready === false) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 262_144);

                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        foreach ($open as $key => $candidate) {
                            if ($candidate === $stream) {
                                fclose($stream);
                                unset($open[$key]);
                            }
                        }
                    }
                    continue;
                }

                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    // stderr ограничиваем: диагностики хватит и первых килобайт,
                    // а битый PDF может выдать сотни мегабайт предупреждений.
                    if (strlen($stderr) < 65_536) {
                        $stderr .= $chunk;
                    }
                }

                if (strlen($stdout) > $maxOutputBytes) {
                    self::kill($process, $open);

                    throw new \RuntimeException(sprintf(
                        '%s выдал больше %s байт и был остановлен',
                        basename($command[0] ?? 'процесс'),
                        number_format($maxOutputBytes, 0, '.', ' '),
                    ));
                }
            }
        }

        return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => proc_close($process)];
    }

    /** Короткая версия stderr для сообщений об ошибках. */
    public static function tail(string $text, int $limit = 600): string
    {
        $text = trim($text);
        if ($text === '') {
            return '(stderr пуст)';
        }

        return strlen($text) > $limit ? '…' . substr($text, -$limit) : $text;
    }

    /**
     * @param resource            $process
     * @param array<int,resource> $open
     */
    private static function kill($process, array $open): void
    {
        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        proc_terminate($process, SIGKILL);
        proc_close($process);
    }
}
