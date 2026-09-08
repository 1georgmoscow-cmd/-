<?php
declare(strict_types=1);

use LabelPrint\Model\PrinterProfile;
use LabelPrint\Pdf\Bitmap;
use LabelPrint\Render\AcsCompressor;
use LabelPrint\Render\Z64Encoder;
use LabelPrint\Render\ZplEncoder;
use LabelPrint\Render\ZplLabelBuilder;

return [
    'счётчики повторов ACS' => static function (): void {
        assertSame('', AcsCompressor::countPrefix(1), '1 повтор — префикс не нужен');
        assertSame('H', AcsCompressor::countPrefix(2));
        assertSame('I', AcsCompressor::countPrefix(3));
        assertSame('Y', AcsCompressor::countPrefix(19), 'Y — максимум для заглавных');
        assertSame('g', AcsCompressor::countPrefix(20), 'g — ровно 20');
        assertSame('gG', AcsCompressor::countPrefix(21), '20 + 1');
        assertSame('h', AcsCompressor::countPrefix(40));
        assertSame('hG', AcsCompressor::countPrefix(41), '40 + 1');
        assertSame('k', AcsCompressor::countPrefix(100));
        assertSame('z', AcsCompressor::countPrefix(400), 'z — максимум для строчных');
        assertSame('zY', AcsCompressor::countPrefix(419), '400 + 19 — предел одного токена');
    },

    'счётчик вне диапазона отвергается' => static function (): void {
        foreach ([0, -1, 420] as $bad) {
            try {
                AcsCompressor::countPrefix($bad);
                throw new RuntimeException("ожидалось исключение для {$bad}");
            } catch (InvalidArgumentException) {
                // ожидаемо
            }
        }
    },

    'пустая строка сворачивается в запятую' => static function (): void {
        assertSame(',', AcsCompressor::compressRow('0000000000'));
    },

    'чёрная строка сворачивается в восклицательный знак' => static function (): void {
        assertSame('!', AcsCompressor::compressRow('FFFFFFFFFF'));
    },

    'хвост строки сворачивается, начало кодируется' => static function (): void {
        // Байт 0x80 = "80", дальше нули до конца строки.
        assertSame('8,', AcsCompressor::compressRow('80' . str_repeat('0', 18)));
    },

    'короткие прогоны остаются литералами' => static function (): void {
        // Прогоны по два символа: токен «счётчик + символ» не короче, оставляем как есть.
        assertSame('AABBCC', AcsCompressor::compressRow('AABBCC'));
    },

    'хвост из нулей сворачивается даже после литералов' => static function (): void {
        // Последний прогон — нули до конца строки, поэтому вместо них ','.
        assertSame('AABBF,', AcsCompressor::compressRow('AABBF0'));
    },

    'повтор строки сворачивается в двоеточие' => static function (): void {
        $bitmap = Bitmap::create(8, 3, chr(0xFF) . chr(0xFF) . chr(0xFF));
        assertSame('!::', AcsCompressor::compress($bitmap->data, 1, 3));
    },

    'ACS без потерь на всех фикстурах' => static function (): void {
        foreach (glob(__DIR__ . '/fixtures/*.pbm') ?: [] as $file) {
            $bitmap = Bitmap::fromPbm((string) file_get_contents($file));
            $name = basename($file);

            $packed = AcsCompressor::compress($bitmap->data, $bitmap->bytesPerRow, $bitmap->height);
            $restored = AcsCompressor::decompress($packed, $bitmap->bytesPerRow, $bitmap->height);

            assertSame($bitmap->data, $restored, "{$name}: данные после сжатия и распаковки");
        }
    },

    'ACS без потерь на длинных прогонах свыше 419' => static function (): void {
        // 1000 нулевых байт в строке -> 2000 одинаковых hex-символов: проверяем разбиение токенов.
        $bitmap = Bitmap::create(8000, 2, str_repeat("\x00", 1000) . str_repeat("\xAA", 1000));
        $packed = AcsCompressor::compress($bitmap->data, 1000, 2);

        assertSame($bitmap->data, AcsCompressor::decompress($packed, 1000, 2));
    },

    'ACS без потерь на прогоне длиной ровно 420 и 421' => static function (): void {
        foreach ([419, 420, 421, 838, 839] as $runLength) {
            $hex = str_repeat('A', $runLength) . 'B';
            $bytes = intdiv(strlen($hex), 2);
            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
                $bytes++;
            }

            $data = (string) hex2bin($hex);
            $packed = AcsCompressor::compress($data, $bytes, 1);

            assertSame($data, AcsCompressor::decompress($packed, $bytes, 1), "прогон длиной {$runLength}");
        }
    },

    'ACS реально сжимает пустую этикетку' => static function (): void {
        $bitmap = Bitmap::create(812, 1218);   // 100x150 мм при 203 dpi, всё белое
        $packed = AcsCompressor::compress($bitmap->data, $bitmap->bytesPerRow, $bitmap->height);

        assertSame(',' . str_repeat(':', 1217), $packed, 'пустая этикетка — запятая и повторы строк');
        assertTrue(strlen($packed) < 1500, 'вместо 247 000 hex-символов — около 1200 байт');
    },

    'заголовок ^GFA считает байты правильно' => static function (): void {
        $bitmap = Bitmap::create(8, 2, chr(0xFF) . chr(0xFF));
        $field = ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_ACS);

        assertSame('^GFA,2,2,1,!:', $field);
    },

    'ширина не кратная 8 округляется вверх до байта' => static function (): void {
        // 13 точек -> 2 байта в строке -> 5 бит заполнителя
        $bitmap = Bitmap::create(13, 4);
        $field = ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_HEX);

        assertContains('^GFA,8,8,2,', $field, 'всего 8 байт, по 2 на строку');
    },

    'hex-кодирование в верхнем регистре' => static function (): void {
        $bitmap = Bitmap::create(8, 1, chr(0xAB));
        assertSame('^GFA,1,1,1,AB', ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_HEX));
    },

    '^GFA расшифровывается обратно для всех способов сжатия' => static function (): void {
        foreach (glob(__DIR__ . '/fixtures/*.pbm') ?: [] as $file) {
            $bitmap = Bitmap::fromPbm((string) file_get_contents($file));
            $name = basename($file);

            foreach ([PrinterProfile::COMPRESSION_HEX, PrinterProfile::COMPRESSION_ACS, PrinterProfile::COMPRESSION_Z64] as $mode) {
                $field = ZplEncoder::graphicField($bitmap, $mode);
                $decoded = ZplEncoder::decodeGraphicField($field, $bitmap->width);

                assertSame($bitmap->width, $decoded->width, "{$name}/{$mode}: ширина");
                assertSame($bitmap->height, $decoded->height, "{$name}/{$mode}: высота");
                assertSame($bitmap->data, $decoded->data, "{$name}/{$mode}: пиксели");
            }
        }
    },

    'CRC16 сходится с контрольным вектором XMODEM' => static function (): void {
        assertSame(0x31C3, Z64Encoder::crc16('123456789'), 'эталонное значение CRC-16/XMODEM');
    },

    'испорченная контрольная сумма Z64 отлавливается' => static function (): void {
        $field = Z64Encoder::encode(str_repeat("\xAA", 64));
        $broken = substr($field, 0, -4) . 'dead';

        try {
            Z64Encoder::decode($broken);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('Контрольная сумма', $e->getMessage());
        }
    },

    'Z64 выигрывает у ACS на плотном растре' => static function (): void {
        $bitmap = Bitmap::fromPbm((string) file_get_contents(__DIR__ . '/fixtures/checker_64x64.pbm'));

        $acs = strlen(ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_ACS));
        $z64 = strlen(ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_Z64));

        assertTrue($z64 < $acs, "на шахматке Z64 ({$z64}) должен быть короче ACS ({$acs})");
    },

    'ACS выигрывает у hex на реальной этикетке' => static function (): void {
        $bitmap = Bitmap::fromPbm((string) file_get_contents(__DIR__ . '/fixtures/barcode_400x120.pbm'));

        $hex = strlen(ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_HEX));
        $acs = strlen(ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_ACS));

        assertTrue($acs < $hex / 2, "ACS ({$acs}) должен быть заметно короче hex ({$hex})");
    },

    'задание ZPL самодостаточно' => static function (): void {
        $profile = PrinterProfile::fromArray('test', [
            'dpi' => 203,
            'width_mm' => 100,
            'height_mm' => 150,
            'darkness' => 10,
            'print_rate' => 4,
            'media_tracking' => 'gap',
            'quantity' => 2,
        ]);

        $bitmap = Bitmap::create($profile->widthDots(), $profile->heightDots());
        $zpl = (new ZplLabelBuilder())->build($bitmap, $profile);

        assertTrue(str_starts_with($zpl, '^XA'), 'начинается с ^XA');
        assertTrue(str_ends_with($zpl, "^XZ\n"), 'заканчивается ^XZ');
        assertContains('^LH0,0', $zpl, 'точка отсчёта сброшена');
        assertContains('^MNY', $zpl, 'отслеживание по просвету');
        assertContains('^MD10', $zpl, 'температура печати');
        assertContains('^PR4', $zpl, 'скорость печати');
        assertContains('^PW799', $zpl, '100 мм при 203 dpi = 799 точек');
        assertContains('^LL1199', $zpl, '150 мм при 203 dpi = 1199 точек');
        assertContains('^FO0,0^GFA,', $zpl, 'графика в начале координат');
        assertContains('^FS', $zpl, 'поле закрыто');
        assertContains('^PQ2', $zpl, 'две копии');
    },

    'непрерывная лента и чёрная метка' => static function (): void {
        $bitmap = Bitmap::create(64, 8);
        $builder = new ZplLabelBuilder();

        $continuous = PrinterProfile::fromArray('c', ['media_tracking' => 'continuous']);
        $mark = PrinterProfile::fromArray('m', ['media_tracking' => 'mark']);
        $none = PrinterProfile::fromArray('n', ['media_tracking' => null]);

        assertContains('^MNN', $builder->build($bitmap, $continuous));
        assertContains('^MNM', $builder->build($bitmap, $mark));
        assertTrue(!str_contains($builder->build($bitmap, $none), '^MN'), 'без настройки команда не выводится');
    },

    'размер этикетки в точках' => static function (): void {
        $p = PrinterProfile::fromArray('x', ['dpi' => 203, 'width_mm' => 100, 'height_mm' => 150]);
        assertSame(799, $p->widthDots(), '100 мм при 203 dpi');
        assertSame(1199, $p->heightDots(), '150 мм при 203 dpi');

        $p300 = PrinterProfile::fromArray('y', ['dpi' => 300, 'width_mm' => 100, 'height_mm' => 150]);
        assertSame(1181, $p300->widthDots(), '100 мм при 300 dpi');
        assertSame(1772, $p300->heightDots(), '150 мм при 300 dpi');
    },

    'отпечаток профиля меняется только от значимых параметров' => static function (): void {
        $base = ['dpi' => 203, 'width_mm' => 100, 'height_mm' => 150];

        $a = PrinterProfile::fromArray('a', $base);
        $sameParams = PrinterProfile::fromArray('b', $base + ['title' => 'другое имя', 'quantity' => 5]);
        $otherDpi = PrinterProfile::fromArray('c', ['dpi' => 300] + $base);

        assertSame($a->fingerprint(), $sameParams->fingerprint(), 'имя и тираж на растр не влияют');
        assertTrue($a->fingerprint() !== $otherDpi->fingerprint(), 'разрешение влияет');
    },
];
