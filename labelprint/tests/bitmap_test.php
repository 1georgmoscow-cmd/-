<?php
declare(strict_types=1);

use LabelPrint\Pdf\Bitmap;

/**
 * Эталонная (медленная, заведомо очевидная) реализация поворота — с ней сверяем
 * быструю блочную. Поворот по часовой стрелке.
 */
function naiveRotate(Bitmap $src, int $degrees): Bitmap
{
    $deg = (($degrees % 360) + 360) % 360;

    [$w, $h] = match ($deg) {
        90, 270 => [$src->height, $src->width],
        default => [$src->width, $src->height],
    };

    $bytesPerRow = intdiv($w + 7, 8);
    $out = str_repeat("\x00", $bytesPerRow * $h);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $on = match ($deg) {
                0 => $src->pixel($x, $y),
                90 => $src->pixel($y, $src->height - 1 - $x),
                180 => $src->pixel($src->width - 1 - $x, $src->height - 1 - $y),
                270 => $src->pixel($src->width - 1 - $y, $x),
            };
            if ($on) {
                $i = $y * $bytesPerRow + ($x >> 3);
                $out[$i] = chr(ord($out[$i]) | (0x80 >> ($x & 7)));
            }
        }
    }

    return Bitmap::create($w, $h, $out);
}

/** Детерминированный «шумовой» растр — воспроизводимый без rand(). */
function pseudoBitmap(int $w, int $h, int $seed = 12345): Bitmap
{
    $bytesPerRow = intdiv($w + 7, 8);
    $out = str_repeat("\x00", $bytesPerRow * $h);
    $state = $seed;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
            if ((($state >> 16) & 1) === 1) {
                $i = $y * $bytesPerRow + ($x >> 3);
                $out[$i] = chr(ord($out[$i]) | (0x80 >> ($x & 7)));
            }
        }
    }

    return Bitmap::create($w, $h, $out);
}

function bitmapToText(Bitmap $b): string
{
    $s = '';
    for ($y = 0; $y < $b->height; $y++) {
        for ($x = 0; $x < $b->width; $x++) {
            $s .= $b->pixel($x, $y) ? '#' : '.';
        }
        $s .= "\n";
    }

    return $s;
}

return [
    'PBM P4 читается, бит 1 = чёрный' => static function (): void {
        // 8x2: первая строка 10000001, вторая 00000000
        $pbm = "P4\n8 2\n" . chr(0b10000001) . chr(0b00000000);
        $b = Bitmap::fromPbm($pbm);

        assertSame(8, $b->width, 'ширина');
        assertSame(2, $b->height, 'высота');
        assertSame(1, $b->bytesPerRow, 'байт на строку');
        assertTrue($b->pixel(0, 0), 'левый верхний пиксель чёрный');
        assertTrue($b->pixel(7, 0), 'правый верхний пиксель чёрный');
        assertTrue(!$b->pixel(1, 0), 'соседний пиксель белый');
        assertTrue(!$b->pixel(0, 1), 'вторая строка пустая');
    },

    'PBM с комментарием в заголовке' => static function (): void {
        $pbm = "P4\n# создано ghostscript\n4 1\n" . chr(0b11000000);
        $b = Bitmap::fromPbm($pbm);

        assertSame(4, $b->width);
        assertTrue($b->pixel(0, 0) && $b->pixel(1, 0) && !$b->pixel(2, 0));
    },

    'обрезанный PBM даёт понятную ошибку' => static function (): void {
        try {
            Bitmap::fromPbm("P4\n64 64\n" . str_repeat("\x00", 10));
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('Обрезанный PBM', $e->getMessage());
        }
    },

    'PGM P5 бинаризуется по порогу' => static function (): void {
        // 4x1, значения 0, 100, 200, 255 при пороге 128 -> чёрный, чёрный, белый, белый
        $pgm = "P5\n4 1\n255\n" . chr(0) . chr(100) . chr(200) . chr(255);
        $b = Bitmap::fromPgm($pgm, 128);

        assertTrue($b->pixel(0, 0), 'значение 0 -> чёрный');
        assertTrue($b->pixel(1, 0), 'значение 100 -> чёрный');
        assertTrue(!$b->pixel(2, 0), 'значение 200 -> белый');
        assertTrue(!$b->pixel(3, 0), 'значение 255 -> белый');
    },

    'порог PGM управляет результатом' => static function (): void {
        $pgm = "P5\n1 1\n255\n" . chr(200);
        assertTrue(!Bitmap::fromPgm($pgm, 128)->pixel(0, 0), 'при пороге 128 — белый');
        assertTrue(Bitmap::fromPgm($pgm, 220)->pixel(0, 0), 'при пороге 220 — чёрный');
    },

    'инверсия не пачкает биты-заполнители' => static function (): void {
        // ширина 3 -> 5 бит заполнителя в единственном байте
        $b = Bitmap::create(3, 1, chr(0b00000000));
        $inv = $b->invert();

        assertTrue($inv->pixel(0, 0) && $inv->pixel(1, 0) && $inv->pixel(2, 0), 'значащие биты стали чёрными');
        assertSame(chr(0b11100000), $inv->data, 'заполнитель остался нулевым');
    },

    'поворот 180 совпадает с эталоном (ширина кратна 8)' => static function (): void {
        $src = pseudoBitmap(24, 9);
        assertSame(bitmapToText(naiveRotate($src, 180)), bitmapToText($src->rotate(180)));
    },

    'поворот 180 совпадает с эталоном (ширина не кратна 8)' => static function (): void {
        $src = pseudoBitmap(13, 5);
        assertSame(bitmapToText(naiveRotate($src, 180)), bitmapToText($src->rotate(180)));
    },

    'поворот 90 совпадает с эталоном' => static function (): void {
        foreach ([[8, 8], [16, 8], [13, 5], [1, 1], [7, 19], [64, 31]] as [$w, $h]) {
            $src = pseudoBitmap($w, $h, $w * 31 + $h);
            $expected = naiveRotate($src, 90);
            $actual = $src->rotate(90);

            assertSame($expected->width, $actual->width, "ширина после поворота 90 для {$w}x{$h}");
            assertSame($expected->height, $actual->height, "высота после поворота 90 для {$w}x{$h}");
            assertSame(bitmapToText($expected), bitmapToText($actual), "пиксели после поворота 90 для {$w}x{$h}");
        }
    },

    'поворот 270 совпадает с эталоном' => static function (): void {
        foreach ([[8, 8], [13, 5], [17, 33]] as [$w, $h]) {
            $src = pseudoBitmap($w, $h, $w + $h * 7);
            assertSame(
                bitmapToText(naiveRotate($src, 270)),
                bitmapToText($src->rotate(270)),
                "поворот 270 для {$w}x{$h}",
            );
        }
    },

    'четыре поворота на 90 возвращают исходный растр' => static function (): void {
        $src = pseudoBitmap(29, 11);
        $round = $src->rotate(90)->rotate(90)->rotate(90)->rotate(90);

        assertSame($src->width, $round->width);
        assertSame($src->height, $round->height);
        assertSame($src->data, $round->data, 'данные после четырёх поворотов');
    },

    'поворот 90 сохраняет ориентацию: верхний левый угол уходит в верхний правый' => static function (): void {
        $b = Bitmap::create(4, 2, chr(0b10000000) . chr(0b00000000));  // одна точка в (0,0)
        $r = $b->rotate(90);

        assertSame(2, $r->width);
        assertSame(4, $r->height);
        assertTrue($r->pixel(1, 0), 'точка (0,0) перешла в правый верхний угол');
        assertTrue(!$r->pixel(0, 0), 'левый верхний угол пуст');
    },

    'обрезка по границе байта' => static function (): void {
        $src = pseudoBitmap(32, 4);
        $crop = $src->crop(8, 1, 16, 2);

        assertSame(16, $crop->width);
        assertSame(2, $crop->height);
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 16; $x++) {
                assertSame($src->pixel($x + 8, $y + 1), $crop->pixel($x, $y), "пиксель {$x},{$y}");
            }
        }
    },

    'обрезка со сдвигом не по границе байта' => static function (): void {
        $src = pseudoBitmap(32, 4);
        $crop = $src->crop(3, 0, 20, 3);

        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 20; $x++) {
                assertSame($src->pixel($x + 3, $y), $crop->pixel($x, $y), "пиксель {$x},{$y}");
            }
        }
    },

    'обрезка за границами дополняет белым' => static function (): void {
        $src = Bitmap::create(8, 1, chr(0xFF));
        $crop = $src->crop(-4, 0, 16, 1);

        assertTrue(!$crop->pixel(0, 0), 'слева добавлено белое поле');
        assertTrue($crop->pixel(4, 0), 'содержимое сдвинулось вправо');
        assertTrue(!$crop->pixel(12, 0), 'справа добавлено белое поле');
    },

    'холст нужного размера' => static function (): void {
        $src = Bitmap::create(8, 8, str_repeat(chr(0xFF), 8));
        $canvas = $src->placeOnCanvas(24, 16, 8, 4);

        assertSame(24, $canvas->width);
        assertSame(16, $canvas->height);
        assertTrue($canvas->pixel(8, 4), 'содержимое на заданном смещении');
        assertTrue(!$canvas->pixel(0, 0), 'вне содержимого белое');
    },

    'быстрый и медленный пути обрезки дают одно и то же' => static function (): void {
        $src = pseudoBitmap(37, 13, 777);

        // Смещения кратные 8 идут по быстрому пути, остальные — по попиксельному.
        foreach ([-16, -8, 0, 8, 16, 24] as $left) {
            foreach ([-3, 0, 5] as $top) {
                $fast = $src->crop($left, $top, 40, 20);

                // Эталон: тот же прямоугольник, собранный через pixel().
                $ref = Bitmap::create(40, 20);
                $data = $ref->data;
                for ($y = 0; $y < 20; $y++) {
                    for ($x = 0; $x < 40; $x++) {
                        if ($src->pixel($left + $x, $top + $y)) {
                            $i = $y * $ref->bytesPerRow + ($x >> 3);
                            $data[$i] = chr(ord($data[$i]) | (0x80 >> ($x & 7)));
                        }
                    }
                }

                assertSame($data, $fast->data, "обрезка со смещением {$left},{$top}");
            }
        }
    },

    'габариты содержимого' => static function (): void {
        $b = Bitmap::create(16, 16);
        $data = $b->data;
        // Точка в (5,3)
        $data[3 * 2 + 0] = chr(0b00000100);
        // Точка в (12,9)
        $data[9 * 2 + 1] = chr(0b00001000);
        $b = Bitmap::create(16, 16, $data);

        assertSame([5, 3, 8, 7], $b->contentBox());
    },

    'габариты пустого растра — null' => static function (): void {
        assertTrue(Bitmap::create(16, 16)->contentBox() === null);
        assertTrue(Bitmap::create(16, 16)->isBlank());
    },

    'доля чёрного' => static function (): void {
        assertSame(1.0, Bitmap::create(8, 1, chr(0xFF))->inkCoverage());
        assertSame(0.0, Bitmap::create(8, 1, chr(0x00))->inkCoverage());
        assertSame(0.5, Bitmap::create(8, 1, chr(0xF0))->inkCoverage());
    },

    'фикстуры читаются и переживают поворот' => static function (): void {
        foreach (glob(__DIR__ . '/fixtures/*.pbm') ?: [] as $file) {
            $b = Bitmap::fromPbm((string) file_get_contents($file));
            $name = basename($file);
            $r = $b->rotate(90);
            assertSame($b->height, $r->width, "{$name}: ширина после поворота");
            assertSame($b->width, $r->height, "{$name}: высота после поворота");
        }
    },
];
