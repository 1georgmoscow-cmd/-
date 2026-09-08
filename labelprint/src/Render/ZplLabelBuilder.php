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
     * @param bool $verify сверять собранное поле ^GFA с исходным растром
     */
    public function __construct(private readonly bool $verify = true)
    {
    }

    /**
     * @param int $offsetX смещение графики от левого края, точки
     * @param int $offsetY смещение графики от верхнего края, точки
     */
    public function build(Bitmap $bitmap, PrinterProfile $profile, int $offsetX = 0, int $offsetY = 0): string
    {
        if ($profile->printheadDots !== null && $bitmap->width > $profile->printheadDots) {
            throw new \RuntimeException(sprintf(
                'Растр шире печатающей головки: %d точек против %d. Принтер обрезал бы правый край '
                . 'вместе со штрихкодом — проверьте width_mm и dpi в профиле «%s».',
                $bitmap->width,
                $profile->printheadDots,
                $profile->code,
            ));
        }

        $lines = ['^XA'];

        // Сброс «липких» настроек. Все они переживают перезагрузку принтера, если были
        // сохранены через ^JUS, и молча портят каждую следующую этикетку:
        //   ^JMB   вдвое снижает разрешение и удваивает все координаты
        //   ^LH    сдвигает начало координат
        //   ^LT/^LS сдвигают формат по вертикали и горизонтали
        //   ^POI   переворачивает этикетку на 180 градусов
        //   ^LRY   печатает негатив
        //   ^PMY   зеркалит этикетку
        //   ^MCN   не очищает буфер, и предыдущая этикетка проступает на следующей
        //   ^MUi/c пересчитывает координаты в дюймы или сантиметры
        $lines[] = '^MUd';
        $lines[] = '^JMA';
        $lines[] = '^LH0,0';
        $lines[] = '^LT0';
        $lines[] = '^LS0';
        $lines[] = '^PON';
        $lines[] = '^PMN';
        $lines[] = '^LRN';
        $lines[] = '^MCY';

        if ($profile->mediaTracking !== null) {
            $lines[] = match ($profile->mediaTracking) {
                'gap' => '^MNY',          // просвет или прорезь между этикетками
                'mark' => '^MNM',         // чёрная метка на обороте
                'continuous' => '^MNN',   // непрерывная лента
            };
        }

        if ($profile->printMode !== null) {
            $lines[] = '^MM' . PrinterProfile::PRINT_MODES[$profile->printMode];
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

        $field = ZplEncoder::graphicField($bitmap, $profile->compression);

        if ($this->verify) {
            ZplEncoder::verify($field, $bitmap);
        }

        $lines[] = sprintf('^FO%d,%d', $offsetX, $offsetY) . $field . '^FS';

        // Тираж выводим всегда: явное значение читается однозначно и не зависит
        // от того, что подразумевает прошивка при отсутствии команды.
        $lines[] = '^PQ' . $profile->quantity . ',0,0,N';

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
