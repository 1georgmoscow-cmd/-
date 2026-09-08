<?php
declare(strict_types=1);

namespace LabelPrint\Render;

/**
 * Кодирование графики в формат ZPL :Z64: — zlib-сжатие плюс base64.
 *
 * Формат:  :Z64:<base64(zlib-поток)>:<CRC16, 4 hex-символа>
 *
 * Плюс перед ACS: на плотных растрах (фотографии, крупные логотипы) zlib выигрывает
 * заметно — данные не раздуваются вдвое hex-представлением. Минус: требует прошивки
 * с поддержкой B64/Z64 (Link-OS и большинство ZPL-принтеров начиная с середины 2000-х)
 * и корректной контрольной суммы — при расхождении принтер молча отбросит графику.
 *
 * Поэтому по умолчанию используется ACS, а :Z64: включается в профиле осознанно.
 */
final class Z64Encoder
{
    /**
     * Уровень сжатия по умолчанию — 6, а не 9. На типовой этикетке девятый уровень
     * стоит впятеро больше процессорного времени (около 10 мс против 2 мс) и даёт
     * выигрыш в размере примерно на процент.
     */
    public const DEFAULT_LEVEL = 6;

    /** @var list<int>|null Таблица CRC-16: втрое-вшестеро быстрее побитового цикла. */
    private static ?array $crcTable = null;

    public static function encode(string $binary, int $level = self::DEFAULT_LEVEL): string
    {
        $deflated = gzcompress($binary, $level);
        if ($deflated === false) {
            throw new \RuntimeException('Не удалось сжать графику zlib');
        }

        $encoded = base64_encode($deflated);

        return ':Z64:' . $encoded . ':' . sprintf('%04x', self::crc16($encoded));
    }

    /** Вариант без сжатия — те же base64 и CRC, но данные как есть. */
    public static function encodeB64(string $binary): string
    {
        $encoded = base64_encode($binary);

        return ':B64:' . $encoded . ':' . sprintf('%04x', self::crc16($encoded));
    }

    /** Разбор — нужен тестам, чтобы убедиться в отсутствии потерь. */
    public static function decode(string $field): string
    {
        if (preg_match('/^:(Z64|B64):(.*):([0-9a-fA-F]{4})$/s', $field, $m) !== 1) {
            throw new \RuntimeException('Строка не похожа на поле :Z64:/:B64:');
        }

        [, $kind, $payload, $crc] = $m;

        $actual = sprintf('%04x', self::crc16($payload));
        if (strtolower($crc) !== $actual) {
            throw new \RuntimeException("Контрольная сумма не сошлась: в поле {$crc}, посчитано {$actual}");
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new \RuntimeException('Повреждённый base64 в поле Z64');
        }

        if ($kind === 'B64') {
            return $raw;
        }

        $inflated = @gzuncompress($raw);
        if ($inflated === false) {
            throw new \RuntimeException('Не удалось распаковать zlib-поток поля Z64');
        }

        return $inflated;
    }

    /**
     * CRC-16/CCITT в варианте XMODEM: полином 0x1021, начальное значение 0x0000,
     * без отражения бит и без финального XOR. Считается по СИМВОЛАМ base64-строки,
     * а не по исходным двоичным данным.
     */
    public static function crc16(string $data): int
    {
        $table = self::$crcTable ??= self::buildCrcTable();

        $crc = 0x0000;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $crc = (($crc << 8) & 0xFFFF) ^ $table[(($crc >> 8) ^ ord($data[$i])) & 0xFF];
        }

        return $crc;
    }

    /** @return list<int> */
    private static function buildCrcTable(): array
    {
        $table = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $crc = $byte << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
            $table[$byte] = $crc;
        }

        return $table;
    }
}
