<?php
declare(strict_types=1);

namespace LabelPrint\Render;

/**
 * Сжатие графики по схеме ZPL ACS (Alternative Data Compression Scheme).
 *
 * Работает поверх ШЕСТНАДЦАТЕРИЧНОГО представления растра: повторяющиеся hex-символы
 * заменяются на «счётчик + символ». Понимается всеми прошивками ZPL II и, в отличие
 * от :Z64:, не требует ни zlib, ни контрольной суммы.
 *
 * Алфавит счётчиков:
 *   G..Y — от 1 до 19 повторов
 *   g..z — 20, 40, 60, ... 400 повторов
 * Счётчики складываются: 'h' (40) + 'G' (1) = 41 повтор, максимум за один токен 419.
 *
 * Спецсимволы, каждый завершает строку растра:
 *   ','  — добить строку нулями (белое)
 *   '!'  — добить строку символами F (чёрное)
 *   ':'  — повторить предыдущую строку целиком
 *
 * На типичной этикетке маркетплейса даёт сжатие в 5-20 раз: поля и межстрочные
 * промежутки схлопываются в ',' и ':'.
 */
final class AcsCompressor
{
    /** Максимум повторов, выражаемый одним токеном: 400 ('z') + 19 ('Y'). */
    private const MAX_RUN = 419;

    /**
     * Прогон короче этого числа символов дешевле оставить как есть:
     * счётчик + символ занимает 2 байта, поэтому выигрыш начинается с трёх повторов.
     */
    private const MIN_RUN = 3;

    /**
     * @param string $data       упакованный растр, bytesPerRow * height байт
     * @param int    $bytesPerRow
     * @param int    $height
     */
    public static function compress(string $data, int $bytesPerRow, int $height): string
    {
        $expected = $bytesPerRow * $height;
        if (strlen($data) !== $expected) {
            throw new \InvalidArgumentException(
                "Размер данных не совпадает: ожидалось {$expected} байт, получено " . strlen($data),
            );
        }

        $out = '';
        $previousRow = null;

        for ($y = 0; $y < $height; $y++) {
            $row = substr($data, $y * $bytesPerRow, $bytesPerRow);

            if ($row === $previousRow) {
                $out .= ':';
                continue;
            }

            $out .= self::compressRow(strtoupper(bin2hex($row)));
            $previousRow = $row;
        }

        return $out;
    }

    /** Сжимает одну строку, уже переведённую в hex. */
    public static function compressRow(string $hex): string
    {
        $len = strlen($hex);
        $out = '';
        $i = 0;

        while ($i < $len) {
            $char = $hex[$i];

            $run = 1;
            while ($i + $run < $len && $hex[$i + $run] === $char) {
                $run++;
            }

            // Хвост строки из одинаковых символов сворачивается в один спецсимвол.
            if ($i + $run === $len) {
                if ($char === '0') {
                    return $out . ',';
                }
                if ($char === 'F') {
                    return $out . '!';
                }
            }

            $i += $run;

            if ($run < self::MIN_RUN) {
                $out .= str_repeat($char, $run);
                continue;
            }

            // Длинные прогоны разбиваются на токены по 419 повторов.
            while ($run > 0) {
                $take = min($run, self::MAX_RUN);
                // Остаток в 1-2 повтора выгоднее дописать литералом, чем отдельным токеном.
                if ($run - $take > 0 && $run - $take < self::MIN_RUN) {
                    $take = $run - self::MIN_RUN;
                }
                $out .= self::countPrefix($take) . $char;
                $run -= $take;

                if ($run > 0 && $run < self::MIN_RUN) {
                    $out .= str_repeat($char, $run);
                    $run = 0;
                }
            }
        }

        return $out;
    }

    /** Префикс-счётчик для $count повторов; для одного повтора префикс не нужен. */
    public static function countPrefix(int $count): string
    {
        if ($count < 1 || $count > self::MAX_RUN) {
            throw new \InvalidArgumentException("Счётчик повторов вне диапазона 1..419: {$count}");
        }

        if ($count === 1) {
            return '';
        }

        $prefix = '';
        $twenties = intdiv($count, 20);   // 1..20 -> g..z
        $units = $count % 20;             // 1..19 -> G..Y

        if ($twenties > 0) {
            $prefix .= chr(ord('g') + $twenties - 1);
        }
        if ($units > 0) {
            $prefix .= chr(ord('G') + $units - 1);
        }

        return $prefix;
    }

    /**
     * Обратное преобразование — нужно только тестам, чтобы доказать, что сжатие
     * не теряет данные. Принтеру эта функция не требуется.
     */
    public static function decompress(string $compressed, int $bytesPerRow, int $height): string
    {
        $charsPerRow = $bytesPerRow * 2;
        $rows = [];
        $row = '';
        $count = 0;
        $len = strlen($compressed);

        for ($i = 0; $i < $len; $i++) {
            $c = $compressed[$i];

            if ($c === ',') {
                $row .= str_repeat('0', max(0, $charsPerRow - strlen($row)));
                $rows[] = $row;
                $row = '';
                $count = 0;
                continue;
            }

            if ($c === '!') {
                $row .= str_repeat('F', max(0, $charsPerRow - strlen($row)));
                $rows[] = $row;
                $row = '';
                $count = 0;
                continue;
            }

            if ($c === ':') {
                if ($rows === []) {
                    throw new \RuntimeException("':' без предыдущей строки");
                }
                $rows[] = $rows[count($rows) - 1];
                continue;
            }

            if ($c >= 'G' && $c <= 'Y') {
                $count += ord($c) - ord('G') + 1;
                continue;
            }

            if ($c >= 'g' && $c <= 'z') {
                $count += (ord($c) - ord('g') + 1) * 20;
                continue;
            }

            // Обычный hex-символ: записываем его $count раз (0 означает один раз).
            $row .= str_repeat($c, max(1, $count));
            $count = 0;

            if (strlen($row) >= $charsPerRow) {
                $rows[] = substr($row, 0, $charsPerRow);
                $row = '';
            }
        }

        if ($row !== '') {
            $rows[] = str_pad($row, $charsPerRow, '0');
        }

        if (count($rows) !== $height) {
            throw new \RuntimeException(
                'После распаковки получилось ' . count($rows) . " строк вместо {$height}",
            );
        }

        return (string) hex2bin(implode('', $rows));
    }
}
