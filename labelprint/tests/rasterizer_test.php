<?php
declare(strict_types=1);

use LabelPrint\Model\PrinterProfile;
use LabelPrint\Pdf\Rasterizer;
use LabelPrint\Support\Log;

/**
 * Ghostscript в тестовом окружении не нужен: вместо него подставляется скрипт,
 * который печатает такой же поток Netpbm. Это проверяет ровно то, что ломается
 * в реальности — разбор склеенных страниц, одновременное чтение stdout и stderr,
 * обработка ненулевого кода возврата и таймаута.
 */

function fakeGs(string $name, string $body): string
{
    $dir = sys_get_temp_dir() . '/labelprint-tests';
    @mkdir($dir, 0777, true);
    $path = $dir . '/' . $name;
    file_put_contents($path, "#!/usr/bin/env php\n<?php\n" . $body);
    chmod($path, 0755);

    return $path;
}

/** Собирает P5 (8 бит серого) заданного размера с вертикальной полосой чёрного слева. */
function pgmBytes(int $w, int $h, int $blackCols): string
{
    $rows = '';
    for ($y = 0; $y < $h; $y++) {
        $rows .= str_repeat(chr(0), $blackCols) . str_repeat(chr(255), $w - $blackCols);
    }

    return "P5\n{$w} {$h}\n255\n" . $rows;
}

/** Тестовый PDF: содержимое неважно, растеризатор его только проверяет на читаемость. */
function dummyPdf(): string
{
    $dir = sys_get_temp_dir() . '/labelprint-tests';
    @mkdir($dir, 0777, true);
    $path = $dir . '/dummy.pdf';
    file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<<>>\n%%EOF\n");

    return $path;
}

$profile = PrinterProfile::fromArray('test', [
    'dpi' => 203,
    'width_mm' => 50,
    'height_mm' => 25,
    'threshold' => 128,
    'auto_rotate' => false,
]);

return [
    'одна страница разбирается' => static function () use ($profile): void {
        $data = base64_encode(pgmBytes(32, 8, 16));
        $gs = fakeGs('gs-one.php', 'fwrite(STDOUT, base64_decode("' . $data . '"));');

        $pages = (new Rasterizer($gs, Log::null()))->rasterize(dummyPdf(), $profile);

        assertSame(1, count($pages), 'одна страница');
        assertSame(32, $pages[0]->width);
        assertSame(8, $pages[0]->height);
        assertTrue($pages[0]->pixel(0, 0), 'левая половина чёрная');
        assertTrue(!$pages[0]->pixel(31, 0), 'правая половина белая');
    },

    'несколько страниц в одном потоке' => static function () use ($profile): void {
        $data = base64_encode(pgmBytes(16, 4, 8) . pgmBytes(16, 4, 0) . pgmBytes(16, 4, 16));
        $gs = fakeGs('gs-three.php', 'fwrite(STDOUT, base64_decode("' . $data . '"));');

        $pages = (new Rasterizer($gs, Log::null()))->rasterize(dummyPdf(), $profile);

        assertSame(3, count($pages), 'три страницы');
        assertTrue($pages[0]->pixel(0, 0) && !$pages[0]->pixel(15, 0), 'первая — половина чёрная');
        assertTrue($pages[1]->isBlank(), 'вторая — пустая');
        assertSame(1.0, $pages[2]->inkCoverage(), 'третья — полностью чёрная');
    },

    'большой вывод не приводит к взаимоблокировке каналов' => static function () use ($profile): void {
        // 400 КБ в stdout и 200 КБ в stderr: оба канала переполняют буфер в 64 КБ.
        // Если читать их по очереди, а не одновременно, процесс встанет навсегда.
        $gs = fakeGs('gs-big.php', <<<'PHP'
        $w = 800; $h = 500;
        fwrite(STDOUT, "P5\n{$w} {$h}\n255\n");
        for ($y = 0; $y < $h; $y++) {
            fwrite(STDOUT, str_repeat(chr($y % 256), $w));
            if ($y % 3 === 0) {
                fwrite(STDERR, str_repeat("шум в stderr ", 30) . "\n");
            }
        }
        PHP);

        $pages = (new Rasterizer($gs, Log::null(), 20))->rasterize(dummyPdf(), $profile);

        assertSame(1, count($pages));
        assertSame(800, $pages[0]->width);
        assertSame(500, $pages[0]->height);
    },

    'ненулевой код возврата попадает в сообщение об ошибке' => static function () use ($profile): void {
        $gs = fakeGs('gs-fail.php', 'fwrite(STDERR, "Error: /undefinedfilename in (label.pdf)"); exit(1);');

        try {
            (new Rasterizer($gs, Log::null()))->rasterize(dummyPdf(), $profile);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('кодом 1', $e->getMessage());
            assertContains('undefinedfilename', $e->getMessage(), 'stderr Ghostscript виден в ошибке');
        }
    },

    'пустой вывод даёт понятную ошибку' => static function () use ($profile): void {
        $gs = fakeGs('gs-empty.php', 'fwrite(STDERR, "нечего рендерить");');

        try {
            (new Rasterizer($gs, Log::null()))->rasterize(dummyPdf(), $profile);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('не выдал ни одной страницы', $e->getMessage());
        }
    },

    'мусор вместо растра распознаётся' => static function () use ($profile): void {
        // Классическая ловушка: gs без -q пишет баннер в stdout и портит поток.
        $gs = fakeGs('gs-junk.php', 'fwrite(STDOUT, "GPL Ghostscript 10.02.1 (2023-11-01)\n");');

        try {
            (new Rasterizer($gs, Log::null()))->rasterize(dummyPdf(), $profile);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('Ожидался P5', $e->getMessage());
        }
    },

    'зависший процесс снимается по таймауту' => static function () use ($profile): void {
        $gs = fakeGs('gs-hang.php', 'sleep(30);');

        $started = microtime(true);
        try {
            (new Rasterizer($gs, Log::null(), 1))->rasterize(dummyPdf(), $profile);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('не уложился', $e->getMessage());
        }

        assertTrue(microtime(true) - $started < 5, 'таймаут сработал быстро, а не через 30 секунд');
    },

    'режим 1 бита читает PBM напрямую' => static function () use ($profile): void {
        $pbm = "P4\n16 2\n" . chr(0b10101010) . chr(0b00000000) . chr(0b11111111) . chr(0b11111111);
        $gs = fakeGs('gs-pbm.php', 'fwrite(STDOUT, base64_decode("' . base64_encode($pbm) . '"));');

        $pages = (new Rasterizer($gs, Log::null(), 30, false))->rasterize(dummyPdf(), $profile);

        assertSame(1, count($pages));
        assertTrue($pages[0]->pixel(0, 0), 'первый бит чёрный');
        assertTrue(!$pages[0]->pixel(1, 0), 'второй бит белый');
        assertTrue($pages[0]->pixel(0, 1) && $pages[0]->pixel(15, 1), 'вторая строка целиком чёрная');
    },

    'отсутствующий PDF не доходит до Ghostscript' => static function () use ($profile): void {
        $gs = fakeGs('gs-never.php', 'fwrite(STDOUT, "не должно быть вызвано");');

        try {
            (new Rasterizer($gs, Log::null()))->rasterize('/nope/missing.pdf', $profile);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('недоступен для чтения', $e->getMessage());
        }
    },
];
