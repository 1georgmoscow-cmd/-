<?php
declare(strict_types=1);

namespace LabelPrint\Render;

use LabelPrint\Model\PrinterProfile;
use LabelPrint\Pdf\Bitmap;

/**
 * Превращает монохромный растр в графическое поле ZPL ^GF.
 *
 * Формат команды:  ^GFa,b,c,d,данные
 *   a — способ кодирования: A (hex-текст), B (двоичные данные), C (двоичные сжатые)
 *   b — общее число байт НЕсжатого растра
 *   c — то же значение (в реальных прошивках b и c всегда совпадают)
 *   d — число байт в одной строке растра
 *
 * Используется только вариант 'A': данные остаются печатным ASCII, поэтому поток
 * можно безопасно хранить в базе, класть в лог и передавать по любому каналу,
 * не думая об экранировании управляющих байт. Разрастание вдвое компенсируется
 * сжатием ACS или :Z64:.
 *
 * Важно: в PBM и в ^GF бит со значением 1 одинаково означает ЧЁРНУЮ точку,
 * поэтому данные из Ghostscript уходят в ^GF без инверсии.
 */
final class ZplEncoder
{
    /** Собирает команду ^GFA целиком. */
    public static function graphicField(Bitmap $bitmap, string $compression = PrinterProfile::COMPRESSION_ACS): string
    {
        $totalBytes = $bitmap->bytesPerRow * $bitmap->height;

        $payload = match ($compression) {
            PrinterProfile::COMPRESSION_HEX => strtoupper(bin2hex($bitmap->data)),
            PrinterProfile::COMPRESSION_ACS => AcsCompressor::compress($bitmap->data, $bitmap->bytesPerRow, $bitmap->height),
            PrinterProfile::COMPRESSION_Z64 => Z64Encoder::encode($bitmap->data),
            default => throw new \InvalidArgumentException("Неизвестный способ сжатия: {$compression}"),
        };

        return sprintf('^GFA,%d,%d,%d,%s', $totalBytes, $totalBytes, $bitmap->bytesPerRow, $payload);
    }

    /**
     * Самопроверка: распаковывает только что собранное поле обратно и сверяет с исходным растром.
     *
     * Ошибка кодировщика проявляется не сразу, а на складе — сканер не берёт штрихкод
     * на уже наклеенной этикетке. Обратная распаковка стоит несколько миллисекунд
     * и ловит весь класс таких ошибок до записи в базу.
     */
    public static function verify(string $field, Bitmap $expected): void
    {
        try {
            $decoded = self::decodeGraphicField($field, $expected->width);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Самопроверка ^GFA не прошла: поле не удалось распаковать — ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($decoded->height !== $expected->height) {
            throw new \RuntimeException(sprintf(
                'Самопроверка ^GFA не прошла: после распаковки %d строк вместо %d',
                $decoded->height,
                $expected->height,
            ));
        }

        if ($decoded->data !== $expected->data) {
            $diff = 0;
            $len = strlen($expected->data);
            for ($i = 0; $i < $len; $i++) {
                if ($decoded->data[$i] !== $expected->data[$i]) {
                    $diff = $i;
                    break;
                }
            }

            throw new \RuntimeException(sprintf(
                'Самопроверка ^GFA не прошла: данные разошлись с байта %d (строка %d)',
                $diff,
                intdiv($diff, max(1, $expected->bytesPerRow)),
            ));
        }
    }

    /**
     * Разбирает команду ^GFA обратно в растр — для тестов и для проверки
     * уже лежащего в базе ZPL.
     */
    public static function decodeGraphicField(string $field, int $width): Bitmap
    {
        if (preg_match('/^\^GFA,(\d+),(\d+),(\d+),(.*)$/s', $field, $m) !== 1) {
            throw new \RuntimeException('Строка не похожа на команду ^GFA');
        }

        $totalBytes = (int) $m[1];
        $bytesPerRow = (int) $m[3];
        $payload = $m[4];

        if ($bytesPerRow <= 0) {
            throw new \RuntimeException('Некорректное число байт в строке (параметр d)');
        }

        $height = intdiv($totalBytes, $bytesPerRow);

        if (str_starts_with($payload, ':Z64:') || str_starts_with($payload, ':B64:')) {
            $data = Z64Encoder::decode($payload);
        } elseif (preg_match('/^[0-9A-Fa-f]+$/', $payload) === 1) {
            $data = (string) hex2bin($payload);
        } else {
            $data = AcsCompressor::decompress($payload, $bytesPerRow, $height);
        }

        if (strlen($data) !== $totalBytes) {
            throw new \RuntimeException(
                'После распаковки получено ' . strlen($data) . " байт вместо {$totalBytes}",
            );
        }

        return Bitmap::create($width, $height, $data);
    }
}
