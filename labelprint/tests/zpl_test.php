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
        // Байт 0x80 = "80", дальше нули до конца строки: хвост из 19 нулей — нечётный,
        // поэтому один ноль выводится литералом, и только остаток сворачивается.
        assertSame('80,', AcsCompressor::compressRow('80' . str_repeat('0', 18)));
    },

    'перед запятой остаётся чётное число полубайт' => static function (): void {
        // Чётный хвост сворачивается целиком.
        assertSame('AABB,', AcsCompressor::compressRow('AABB' . str_repeat('0', 12)));
        // Нечётный — один символ литералом, дальше запятая.
        assertSame('AAB0,', AcsCompressor::compressRow('AAB' . str_repeat('0', 13)));
        // То же правило для чёрного хвоста.
        assertSame('AABB!', AcsCompressor::compressRow('AABB' . str_repeat('F', 12)));
        assertSame('AABF!', AcsCompressor::compressRow('AAB' . str_repeat('F', 13)));
    },

    'короткие прогоны остаются литералами' => static function (): void {
        // Прогоны по два символа: токен «счётчик + символ» не короче, оставляем как есть.
        assertSame('AABBCC', AcsCompressor::compressRow('AABBCC'));
    },

    'одиночный ноль в конце не сворачивается' => static function (): void {
        // Сворачивать один символ бессмысленно и запрещено правилом чётности.
        assertSame('AABBF0', AcsCompressor::compressRow('AABBF0'));
    },

    'данные ACS никогда не начинаются с двоеточия' => static function (): void {
        // Ведущее ':' — признак конверта :Z64:, принтер разберёт такие данные неверно.
        foreach ([[8, 4], [16, 3], [800, 8]] as [$w, $h]) {
            $bpr = intdiv($w + 7, 8);
            foreach (["\x00", "\xFF", "\xA5"] as $fill) {
                $packed = AcsCompressor::compress(str_repeat($fill, $bpr * $h), $bpr, $h);
                assertTrue(!str_starts_with($packed, ':'), "растр {$w}x{$h}, заполнение " . bin2hex($fill));
            }
        }
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

    'самопроверка ловит испорченное поле' => static function (): void {
        $bitmap = Bitmap::fromPbm((string) file_get_contents(__DIR__ . '/fixtures/frame_203x203.pbm'));
        $field = ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_ACS);

        // Корректное поле проходит проверку молча.
        ZplEncoder::verify($field, $bitmap);

        // Сдвинутый счётчик повторов сдвигает всю строку — классическая ошибка кодировщика.
        $broken = preg_replace('/,/', 'G0,', $field, 1);
        try {
            ZplEncoder::verify((string) $broken, $bitmap);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('Самопроверка', $e->getMessage());
        }
    },

    'самопроверка ловит неверное число строк' => static function (): void {
        $bitmap = Bitmap::create(16, 4, str_repeat("\xA5", 8));
        $field = ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_HEX);

        // Заявляем в заголовке больше байт, чем на самом деле.
        $broken = preg_replace('/^\^GFA,8,8,2,/', '^GFA,10,10,2,', $field);
        try {
            ZplEncoder::verify((string) $broken, $bitmap);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('Самопроверка', $e->getMessage());
        }
    },

    'сборщик прогоняет самопроверку' => static function (): void {
        $profile = PrinterProfile::fromArray('v', ['dpi' => 203, 'width_mm' => 58, 'height_mm' => 40]);
        $bitmap = Bitmap::fromPbm((string) file_get_contents(__DIR__ . '/fixtures/barcode_400x120.pbm'));

        // Не должно бросать: собранное поле обязано распаковываться обратно.
        $zpl = (new ZplLabelBuilder(true))->build($bitmap, $profile);
        assertContains('^GFA,', $zpl);
    },

    'документированный предел параметров ^GF' => static function (): void {
        // Мануал даёт для b, c и d диапазон 1..99999. Этикетка 58x40 мм укладывается,
        // а 100x150 мм — нет. Прошивки большие значения принимают, но знать об этом надо.
        $small = PrinterProfile::fromArray('s', ['dpi' => 203, 'width_mm' => 58, 'height_mm' => 40]);
        $large = PrinterProfile::fromArray('l', ['dpi' => 203, 'width_mm' => 100, 'height_mm' => 150]);

        $bytesPerRow = static fn(PrinterProfile $p): int => intdiv($p->widthDots() + 7, 8);

        assertTrue(
            !ZplEncoder::exceedsDocumentedLimit($bytesPerRow($small), $small->heightDots()),
            '58x40 мм при 203 dpi укладывается в 99999',
        );
        assertTrue(
            ZplEncoder::exceedsDocumentedLimit($bytesPerRow($large), $large->heightDots()),
            '100x150 мм при 203 dpi выходит за 99999',
        );
    },

    'параметры ^GF выводятся полностью, даже когда велики' => static function (): void {
        // Пропущенный параметр не заменяется нулём — команда молча игнорируется целиком.
        $bitmap = Bitmap::create(800, 1199);
        $field = ZplEncoder::graphicField($bitmap, PrinterProfile::COMPRESSION_ACS);

        assertTrue(
            preg_match('/^\^GFA,(\d+),(\d+),(\d+),/', $field, $m) === 1,
            'все четыре параметра на месте',
        );
        assertSame('119900', $m[1], 'b');
        assertSame('119900', $m[2], 'c = b');
        assertSame('100', $m[3], 'd = байт в строке');
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
        assertContains('^JMA', $zpl, 'полное разрешение печати');
        assertContains('^LH0,0', $zpl, 'точка отсчёта сброшена');
        assertContains('^LT0', $zpl, 'вертикальный сдвиг сброшен');
        assertContains('^LS0', $zpl, 'горизонтальный сдвиг сброшен');
        assertContains('^PON', $zpl, 'ориентация не перевёрнута');
        assertContains('^MNY', $zpl, 'отслеживание по просвету');
        assertContains('^MD10', $zpl, 'температура печати');
        assertContains('^PR4', $zpl, 'скорость печати');
        assertContains('^PW800', $zpl, '100 мм при 203 dpi = 799 точек, округлено до 800');
        assertContains('^LL1199', $zpl, '150 мм при 203 dpi = 1199 точек');
        assertContains('^FO0,0^GFA,', $zpl, 'графика в начале координат');
        assertContains('^FS', $zpl, 'поле закрыто');
        assertContains('^PQ2,0,0,N', $zpl, 'две копии');
        assertContains('^MUd', $zpl, 'координаты в точках');
        assertContains('^PMN', $zpl, 'зеркалирование выключено');
        assertContains('^LRN', $zpl, 'негатив выключен');
        assertContains('^MCY', $zpl, 'буфер очищается между этикетками');
    },

    'режим после печати' => static function (): void {
        $bitmap = Bitmap::create(64, 8);
        $builder = new ZplLabelBuilder();

        assertContains('^MMT', $builder->build($bitmap, PrinterProfile::fromArray('t', ['print_mode' => 'tear'])));
        assertContains('^MMC', $builder->build($bitmap, PrinterProfile::fromArray('c', ['print_mode' => 'cutter'])));
        assertContains('^MMP', $builder->build($bitmap, PrinterProfile::fromArray('p', ['print_mode' => 'peel'])));

        // По умолчанию команду не выводим: навязанный ^MMT сломал бы принтер с ножом.
        assertTrue(!str_contains($builder->build($bitmap, PrinterProfile::fromArray('d', [])), '^MM'));
    },

    'неизвестный движок растеризации отвергается' => static function (): void {
        try {
            PrinterProfile::fromArray('bad', ['engine' => 'imagemagick']);
            throw new RuntimeException('ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            assertContains('engine', $e->getMessage());
        }
    },

    'движок входит в отпечаток профиля' => static function (): void {
        // Движки дают чуть разный растр, поэтому смена движка обязана
        // помечать готовый ZPL устаревшим.
        $base = ['dpi' => 203, 'width_mm' => 100, 'height_mm' => 150];
        $gs = PrinterProfile::fromArray('a', $base + ['engine' => 'ghostscript']);
        $mu = PrinterProfile::fromArray('a', $base + ['engine' => 'mupdf']);

        assertTrue($gs->fingerprint() !== $mu->fingerprint());
    },

    'неизвестный режим печати отвергается' => static function (): void {
        try {
            PrinterProfile::fromArray('bad', ['print_mode' => 'guillotine']);
            throw new RuntimeException('ожидалось исключение');
        } catch (InvalidArgumentException $e) {
            assertContains('print_mode', $e->getMessage());
        }
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
        // Ширина округляется вверх до кратной 8, высота — нет: биты-заполнители
        // бывают только в конце строки.
        $p = PrinterProfile::fromArray('x', ['dpi' => 203, 'width_mm' => 100, 'height_mm' => 150]);
        assertSame(800, $p->widthDots(), '100 мм при 203 dpi — 799, округлено до 800');
        assertSame(1199, $p->heightDots(), '150 мм при 203 dpi');

        $exact = PrinterProfile::fromArray('x2', [
            'dpi' => 203, 'width_mm' => 100, 'height_mm' => 150, 'align_width_to_byte' => false,
        ]);
        assertSame(799, $exact->widthDots(), 'без округления — точное значение');

        $p300 = PrinterProfile::fromArray('y', ['dpi' => 300, 'width_mm' => 100, 'height_mm' => 150]);
        assertSame(1184, $p300->widthDots(), '100 мм при 300 dpi — 1181, округлено до 1184');
        assertSame(1772, $p300->heightDots(), '150 мм при 300 dpi');
    },

    'растр шире головки отвергается до записи в базу' => static function (): void {
        // 58-мм принтер: головка 464 точки, а профиль задан на 100 мм.
        $profile = PrinterProfile::fromArray('narrow', [
            'dpi' => 203, 'width_mm' => 100, 'height_mm' => 40, 'printhead_dots' => 464,
        ]);

        try {
            (new ZplLabelBuilder())->build(Bitmap::create($profile->widthDots(), 100), $profile);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('шире печатающей головки', $e->getMessage());
            assertContains('464', $e->getMessage());
        }
    },

    'растр по ширине головки проходит' => static function (): void {
        $profile = PrinterProfile::fromArray('ok58', [
            'dpi' => 203, 'width_mm' => 58, 'height_mm' => 40, 'printhead_dots' => 464,
        ]);

        $zpl = (new ZplLabelBuilder())->build(Bitmap::create($profile->widthDots(), 320), $profile);
        assertContains('^PW464', $zpl, '58 мм при 203 dpi — 463, округлено до 464');
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
