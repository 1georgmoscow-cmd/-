<?php
declare(strict_types=1);

namespace LabelPrint\Render;

use LabelPrint\Model\PrinterProfile;
use LabelPrint\Pdf\Bitmap;

/**
 * Собирает законченное задание ZPL: ^XA ... ^XZ.
 *
 * Задание намеренно самодостаточно — ширина, длина и точка отсчёта задаются явно.
 * Иначе результат зависит от того, что осталось в настройках конкретного принтера
 * от предыдущего задания, и одна и та же этикетка печатается по-разному на двух
 * аппаратах одной модели.
 */
final class ZplLabelBuilder
{
    /**
     * @param int $offsetX смещение графики от левого края, точки
     * @param int $offsetY смещение графики от верхнего края, точки
     */
    public function build(Bitmap $bitmap, PrinterProfile $profile, int $offsetX = 0, int $offsetY = 0): string
    {
        $lines = ['^XA'];

        // Точка отсчёта в физический ноль: сбрасываем возможный сдвиг из настроек принтера.
        $lines[] = '^LH0,0';
        $lines[] = '^LT0';

        if ($profile->mediaTracking !== null) {
            $lines[] = match ($profile->mediaTracking) {
                'gap' => '^MNY',          // просвет или прорезь между этикетками
                'mark' => '^MNM',         // чёрная метка на обороте
                'continuous' => '^MNN',   // непрерывная лента
            };
        }

        if ($profile->darkness !== null) {
            $lines[] = '^MD' . $profile->darkness;
        }

        if ($profile->printRate !== null) {
            $lines[] = '^PR' . $profile->printRate;
        }

        // Ширина печати и длина этикетки — в точках, ровно по размеру растра.
        $lines[] = '^PW' . $bitmap->width;
        $lines[] = '^LL' . $bitmap->height;

        $lines[] = sprintf('^FO%d,%d', $offsetX, $offsetY)
            . ZplEncoder::graphicField($bitmap, $profile->compression)
            . '^FS';

        if ($profile->quantity > 1) {
            $lines[] = '^PQ' . $profile->quantity;
        }

        $lines[] = '^XZ';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Склеивает несколько заданий в один поток — так многостраничный PDF уезжает
     * на принтер одной посылкой, без паузы между страницами.
     *
     * @param list<string> $labels
     */
    public function concat(array $labels): string
    {
        return implode('', $labels);
    }
}
