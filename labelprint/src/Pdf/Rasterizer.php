<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

use LabelPrint\Support\Log;

/**
 * Растеризация PDF через Ghostscript.
 *
 * Два принципиальных решения:
 *
 * 1. Рендерим в 8-битный серый (pgmraw) и бинаризуем сами, а не просим у Ghostscript
 *    готовый 1 бит (pbmraw). На однобитном устройстве gs применяет полутоновое
 *    растрирование: растровые изображения превращаются в «сеточку» из точек. Для глаза
 *    это выглядит нормально, но штрихкод после такой обработки сканер не читает.
 *    Свой порог даёт чистые чёрно-белые штрихи.
 *
 * 2. Все страницы рендерятся ОДНИМ вызовом gs, а результат читается из stdout.
 *    Запуск процесса стоит 80-150 мс — это самая дорогая часть конвейера, и платить
 *    её за каждую страницу нет смысла. Netpbm-файлы самоописываемы, поэтому склеенный
 *    поток разбирается последовательно.
 */
final class Rasterizer
{
    public function __construct(
        private readonly string $ghostscript,
        private readonly Log $log,
        private readonly int $timeoutSeconds = 30,
        /** true — рендерить в серый и бинаризовать самим; false — просить у gs 1 бит. */
        private readonly bool $grayscaleThreshold = true,
        /** Сглаживание при рендеринге: 1 (выкл), 2 или 4. */
        private readonly int $antialias = 4,
        /** Предохранитель от «бомб»: максимум точек в растре одной страницы. */
        private readonly int $maxDots = 40_000_000,
    ) {
    }

    /**
     * Рендерит все страницы PDF с заданным разрешением.
     *
     * @param float $dpi       разрешение; дробные значения допустимы и используются
     *                         для вписывания страницы в размер этикетки
     * @param int   $threshold порог бинаризации 1..254 (для режима с полутоном)
     * @return list<Bitmap> по одному растру на страницу, в порядке страниц
     */
    public function rasterize(string $pdfPath, float $dpi, int $threshold = 128): array
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new \RuntimeException("PDF недоступен для чтения: {$pdfPath}");
        }

        if ($dpi <= 0) {
            throw new \InvalidArgumentException("Разрешение должно быть положительным, задано {$dpi}");
        }

        $command = $this->buildCommand($pdfPath, $dpi);
        $started = hrtime(true);
        [$stdout, $stderr, $exitCode] = $this->run($command);
        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Ghostscript завершился с кодом {$exitCode}: " . $this->tail($stderr),
            );
        }

        if ($stdout === '') {
            throw new \RuntimeException(
                'Ghostscript не выдал ни одной страницы. ' . $this->tail($stderr),
            );
        }

        $pages = $this->splitPages($stdout, $threshold);

        $this->log->debug('страницы отрендерены', [
            'pdf' => basename($pdfPath),
            'pages' => count($pages),
            'ms' => $elapsedMs,
            'raster_bytes' => strlen($stdout),
        ]);

        return $pages;
    }

    /**
     * Разбирает склеенный поток Netpbm на отдельные растры.
     *
     * @return list<Bitmap>
     */
    private function splitPages(string $stream, int $threshold): array
    {
        $magic = $this->grayscaleThreshold ? 'P5' : 'P4';
        $pages = [];
        $offset = 0;
        $len = strlen($stream);

        while ($offset < $len) {
            // Пропускаем возможные пустые строки между страницами.
            while ($offset < $len && ($stream[$offset] === "\n" || $stream[$offset] === "\r")) {
                $offset++;
            }
            if ($offset >= $len) {
                break;
            }

            if (substr($stream, $offset, 2) !== $magic) {
                throw new \RuntimeException(sprintf(
                    'Ожидался %s на позиции %d, получено "%s". Похоже, Ghostscript написал в stdout что-то ещё.',
                    $magic,
                    $offset,
                    substr($stream, $offset, 16),
                ));
            }

            $chunk = substr($stream, $offset);
            $bitmap = $this->grayscaleThreshold
                ? Bitmap::fromPgm($chunk, $threshold)
                : Bitmap::fromPbm($chunk);

            if ($bitmap->width * $bitmap->height > $this->maxDots) {
                throw new \RuntimeException(sprintf(
                    'Растр страницы слишком велик: %dx%d = %d точек при лимите %d',
                    $bitmap->width,
                    $bitmap->height,
                    $bitmap->width * $bitmap->height,
                    $this->maxDots,
                ));
            }

            $pages[] = $bitmap;
            $offset += $this->consumedBytes($chunk, $bitmap);
        }

        if ($pages === []) {
            throw new \RuntimeException('В выводе Ghostscript не нашлось ни одной страницы');
        }

        return $pages;
    }

    /** Сколько байт занимает одна картинка в потоке: заголовок плюс данные. */
    private function consumedBytes(string $chunk, Bitmap $bitmap): int
    {
        // Заголовок заканчивается ровно одним разделителем после последнего числа.
        $fields = $this->grayscaleThreshold ? 3 : 2;
        $pos = 2;
        $len = strlen($chunk);

        for ($i = 0; $i < $fields; $i++) {
            while ($pos < $len) {
                $ch = $chunk[$pos];
                if ($ch === '#') {
                    while ($pos < $len && $chunk[$pos] !== "\n") {
                        $pos++;
                    }
                    continue;
                }
                if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                    $pos++;
                    continue;
                }
                break;
            }
            while ($pos < $len && $chunk[$pos] >= '0' && $chunk[$pos] <= '9') {
                $pos++;
            }
        }
        $pos++;   // единственный разделитель перед данными

        $body = $this->grayscaleThreshold
            ? $bitmap->width * $bitmap->height
            : $bitmap->bytesPerRow * $bitmap->height;

        return $pos + $body;
    }

    /** @return list<string> */
    private function buildCommand(string $pdfPath, float $dpi): array
    {
        // Разрешение может быть дробным: так страница вписывается в этикетку
        // без -dPDFFitPage и без риска, что Ghostscript развернёт её сам.
        $resolution = rtrim(rtrim(number_format($dpi, 4, '.', ''), '0'), '.');

        return [
            $this->ghostscript,
            '-q',                        // без баннера
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            // Сообщения PostScript уходят в stderr, иначе они попадут в поток растра и всё сломают.
            '-sstdout=%stderr',
            '-dNOPROMPT',
            '-sDEVICE=' . ($this->grayscaleThreshold ? 'pgmraw' : 'pbmraw'),
            '-r' . $resolution,
            // На однобитных устройствах эти ключи не действуют вовсе, а на pgmraw дают
            // субпиксельную точность краёв штрихов — после порога граница встаёт на место.
            '-dTextAlphaBits=' . $this->antialias,
            '-dGraphicsAlphaBits=' . $this->antialias,
            '-sOutputFile=-',            // весь вывод в stdout одним потоком
            '-f',
            $pdfPath,
        ];
    }

    /**
     * Запускает процесс, читая stdout и stderr одновременно.
     *
     * Читать надо именно параллельно: буфер канала в Linux — 64 КБ, и если выбирать
     * только stdout, процесс намертво встанет на записи в переполненный stderr
     * (или наоборот). Растр этикетки — это сотни килобайт, так что порог достигается легко.
     *
     * @param  list<string> $command
     * @return array{0:string,1:string,2:int}
     */
    private function run(array $command): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("Не удалось запустить Ghostscript: {$this->ghostscript}");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->terminate($process, $pipes);
                throw new \RuntimeException(
                    "Ghostscript не уложился в {$this->timeoutSeconds} с и был остановлен",
                );
            }

            $ready = @stream_select($read, $write, $except, (int) $remaining, 200_000);
            if ($ready === false) {
                break;
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
                    $stderr .= $chunk;
                }
            }
        }

        foreach ($open as $stream) {
            fclose($stream);
        }

        return [$stdout, $stderr, proc_close($process)];
    }

    /**
     * @param resource                 $process
     * @param array<int,resource>      $pipes
     */
    private function terminate($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($process, SIGKILL);
        proc_close($process);
    }

    private function tail(string $text, int $limit = 600): string
    {
        $text = trim($text);
        if ($text === '') {
            return '(stderr пуст)';
        }

        return strlen($text) > $limit ? '…' . substr($text, -$limit) : $text;
    }
}
