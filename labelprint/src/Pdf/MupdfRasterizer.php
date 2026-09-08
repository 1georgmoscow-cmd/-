<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

use LabelPrint\Support\Log;

/**
 * Растеризация через MuPDF (mutool из пакета mupdf-tools).
 *
 * Примерно в пять раз быстрее Ghostscript на этикетках: около 10 мс на страницу
 * против 57 при 203 dpi. Разница почти целиком в запуске процесса — MuPDF стартует
 * за миллисекунды, тогда как gs тратит 43 мс на инициализацию интерпретатора.
 * Даже резидентный Ghostscript-демон (16,7 мс на задание) проигрывает холодному запуску mutool.
 *
 * Рендерим в серый (-F pgm) и бинаризуем сами: у mutool режим -F pbm тоже даёт
 * упорядоченный дизеринг и портит штрихкоды.
 *
 * Ограничения по сравнению с Ghostscript:
 *   * не читает PDF со стандартного ввода (нам это не нужно — файл уже на диске);
 *   * строже относится к повреждённым PDF; если движок отказывается, есть смысл
 *     переключить профиль на Ghostscript.
 */
final class MupdfRasterizer extends PnmRasterizer
{
    public function name(): string
    {
        return 'MuPDF';
    }

    protected function magic(): string
    {
        return 'P5';
    }

    /** @return list<string> */
    protected function buildCommand(string $pdfPath, float $dpi): array
    {
        return [
            $this->binary,
            'draw',
            '-q',                     // без предупреждений в stdout
            '-F', 'pgm',              // 8 бит серого, порог накладываем сами
            // По умолчанию mutool берёт CropBox, а Ghostscript — MediaBox.
            // Фиксируем MediaBox, чтобы движки давали одинаковый размер.
            '-b', 'MediaBox',
            '-r', self::formatDpi($dpi),
            '-o', '-',                // весь вывод в stdout одним потоком
            $pdfPath,
        ];
    }
}
