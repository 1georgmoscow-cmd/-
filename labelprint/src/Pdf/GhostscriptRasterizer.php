<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

use LabelPrint\Support\Log;

/**
 * Растеризация через Ghostscript.
 *
 * Движок по умолчанию: gs есть практически на любом сервере и понимает даже
 * кривые PDF, которые MuPDF отвергает. Платой за это является скорость —
 * около 57 мс на страницу при 203 dpi, из которых 43 мс уходит только на запуск
 * интерпретатора.
 *
 * Два принципиальных решения:
 *
 * 1. Рендерим в 8-битный серый (pgmraw) и бинаризуем сами. На однобитных устройствах
 *    (pbmraw, pngmono, bit) Ghostscript применяет полутоновое растрирование:
 *    изображения превращаются в «сеточку» из точек. Для глаза это выглядит нормально,
 *    но штрихкод после такой обработки сканер не читает. Измерено на сжатом JPEG-штрихкоде:
 *    pbmraw даёт 30 одиночных точек-мусорин, свой порог по серому — ноль.
 *
 * 2. Подгонка под размер этикетки НЕ отдаётся Ghostscript. Связка -g + -dPDFFitPage
 *    молча доворачивает страницу на 90 градусов, если так она «лучше вписывается»,
 *    и отключить это нечем. Вместе с нашим собственным поворотом получался разворот
 *    на 180. Масштаб считается заранее и передаётся дробным -r.
 */
final class GhostscriptRasterizer extends PnmRasterizer
{
    public function __construct(
        string $binary,
        Log $log,
        int $timeoutSeconds = 30,
        /** true — рендерить в серый и бинаризовать самим; false — просить у gs 1 бит. */
        private readonly bool $grayscaleThreshold = true,
        /** Сглаживание: 1 (выкл), 2 или 4. На однобитных устройствах не действует. */
        private readonly int $antialias = 4,
        int $maxDots = 40_000_000,
    ) {
        parent::__construct($binary, $log, $timeoutSeconds, $maxDots);
    }

    public function name(): string
    {
        return 'Ghostscript';
    }

    protected function magic(): string
    {
        return $this->grayscaleThreshold ? 'P5' : 'P4';
    }

    /** @return list<string> */
    protected function buildCommand(string $pdfPath, float $dpi): array
    {
        return [
            $this->binary,
            '-q',                        // без баннера
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            // Сообщения PostScript уходят в stderr, иначе они попадут в поток растра и всё сломают.
            '-sstdout=%stderr',
            '-dNOPROMPT',
            // Явно фиксируем область страницы: по умолчанию gs берёт MediaBox, но PDF
            // с CropBox не должен незаметно менять размер этикетки.
            '-dUseMediaBox',
            '-sDEVICE=' . ($this->grayscaleThreshold ? 'pgmraw' : 'pbmraw'),
            '-r' . self::formatDpi($dpi),
            // На однобитных устройствах эти ключи не действуют вовсе, а на pgmraw дают
            // субпиксельную точность краёв штрихов — после порога граница встаёт на место.
            '-dTextAlphaBits=' . $this->antialias,
            '-dGraphicsAlphaBits=' . $this->antialias,
            '-sOutputFile=-',            // весь вывод в stdout одним потоком
            '-f',
            $pdfPath,
        ];
    }
}
