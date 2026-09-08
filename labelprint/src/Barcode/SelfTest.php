<?php
declare(strict_types=1);

namespace LabelPrint\Barcode;

use LabelPrint\Pdf\Bitmap;

/**
 * Эталонный QR-код для самопроверки.
 *
 * Нужен, чтобы bin/doctor.php мог не просто убедиться в наличии zbarimg,
 * а действительно прогнать распознавание и сравнить результат. Проверка
 * «файл существует и запускается» пропустила бы сборку без поддержки QR
 * или неработающую библиотеку — а выяснилось бы это уже на складе.
 *
 * Растр встроен в исходник: самопроверка не должна зависеть ни от генератора
 * кодов, ни от каталога с файлами. В сжатом виде это 260 символов.
 */
final class SelfTest
{
    public const QR_VALUE = 'LABELPRINT-SELFTEST';

    /** Однобитный растр 232x232 с QR-кодом, значение — self::QR_VALUE. */
    private const QR_PBM_GZ_BASE64 = 'eNrtmMERgzAMBO9NFamBpJD034zAgy3LDuQNOzrAg2f52BInwfezrO/1tV+LUqnUrWWH9pty+owH62jDjAjLon0T2owLe7jR0A8s9NEuM54Aqy35Fpw5GADGEvO3/jwctuXLx/AQCZYI15ir11kcbGmt+tAUchJsXZJalTUmDO3DzysMgiG5Pe48ODb50x6QoEL/YJosigS7Q2k2KSCU3KaMDINNXXwBPB3GJl/nPxsIcGjyj9wmwlQqdU9tVESrkQ==';

    public static function qrBitmap(): ?Bitmap
    {
        $packed = base64_decode(self::QR_PBM_GZ_BASE64, true);
        if ($packed === false) {
            return null;
        }

        $pbm = @gzuncompress($packed, 1_048_576);
        if ($pbm === false) {
            return null;
        }

        try {
            return Bitmap::fromPbm($pbm);
        } catch (\Throwable) {
            return null;
        }
    }
}
