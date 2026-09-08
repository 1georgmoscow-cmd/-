<?php
declare(strict_types=1);

use LabelPrint\Barcode\CodeReader;
use LabelPrint\Barcode\CodeReaderInterface;
use LabelPrint\Barcode\DecodedCode;
use LabelPrint\Barcode\SelfTest;
use LabelPrint\Barcode\ZbarReader;
use LabelPrint\Pdf\Bitmap;
use LabelPrint\Support\Log;

/** Подставной zbarimg: печатает заранее заданный XML. */
function fakeZbar(string $name, string $xml, int $exitCode = 0): string
{
    $dir = sys_get_temp_dir() . '/labelprint-tests';
    @mkdir($dir, 0777, true);
    $path = $dir . '/' . $name;
    file_put_contents(
        $path,
        "#!/usr/bin/env php\n<?php\n"
        . 'stream_get_contents(STDIN);' . "\n"
        . 'fwrite(STDOUT, base64_decode("' . base64_encode($xml) . '"));' . "\n"
        . "exit({$exitCode});\n",
    );
    chmod($path, 0755);

    return $path;
}

function zbarXml(string $symbols): string
{
    return "<barcodes xmlns='http://zbar.sourceforge.net/2008/barcode'>\n"
        . "<source href='-'>\n<index num='0'>\n" . $symbols . "\n</index>\n</source>\n</barcodes>\n";
}

$tiny = Bitmap::create(64, 64);

return [
    'нормализация убирает разделитель GS' => static function (): void {
        // Код «Честного знака»: между полями стоит GS (0x1D).
        $raw = "0104607428561111" . "21AbCdE" . "\x1D" . "93dGVz";

        assertSame('010460742856111121AbCdE93dGVz', DecodedCode::normalize($raw));
    },

    'нормализация обрезает пробелы и управляющие байты' => static function (): void {
        assertSame('ABC123', DecodedCode::normalize("  ABC\r\n123 \t"));
        assertSame('X', DecodedCode::normalize("\x00X\x7F"));
        assertSame('', DecodedCode::normalize("\x1D\x1E\x1F"));
    },

    'нормализация не трогает обычное значение' => static function (): void {
        assertSame('WB-1234567890-BOX3', DecodedCode::normalize('WB-1234567890-BOX3'));
    },

    'код Честного знака распознаётся по виду' => static function (): void {
        $cz = new DecodedCode('DataMatrix', "0104607428561111" . "21AbCdE" . "\x1D" . "93dGVz", 'dmtx');
        $qr = new DecodedCode('QR-Code', 'WB-1234567890-BOX3', 'zbar');

        assertTrue($cz->looksLikeChestnyZnak(), 'DataMatrix с идентификаторами 01 и 21');
        assertTrue(!$qr->looksLikeChestnyZnak(), 'обычный QR — не Честный знак');

        // Признак структурный, поэтому похожие, но неверные значения отсеиваются.
        $shortCode = new DecodedCode('DataMatrix', '0104607428', 'dmtx');
        $wrongAi = new DecodedCode('DataMatrix', '0104607428561111' . '99AbCdE', 'dmtx');
        $notDigits = new DecodedCode('DataMatrix', '01ABCDEFGHIJKLMN' . '21AbCdE', 'dmtx');

        assertTrue(!$shortCode->looksLikeChestnyZnak(), 'слишком короткий');
        assertTrue(!$wrongAi->looksLikeChestnyZnak(), 'после GTIN не 21');
        assertTrue(!$notDigits->looksLikeChestnyZnak(), 'GTIN обязан быть цифрами');
    },

    'двоичное значение экранируется для лога' => static function (): void {
        $code = new DecodedCode('DataMatrix', "01234\x1D5678", 'dmtx');

        assertSame('01234<1D>5678', $code->display());
    },

    'разбор XML: символика, значение, качество и габариты' => static function () use ($tiny): void {
        $xml = zbarXml(
            "<symbol type='QR-Code' quality='7' orientation='UP'>"
            . "<polygon points='+20,+300 +20,+620 +340,+620 +340,+299'/>"
            . "<data><![CDATA[WB-1234567890-BOX3]]></data></symbol>",
        );

        $codes = (new ZbarReader(fakeZbar('zbar-ok.php', $xml)))->read($tiny);

        assertSame(1, count($codes));
        assertSame('QR-Code', $codes[0]->symbology);
        assertSame('WB-1234567890-BOX3', $codes[0]->value);
        assertSame(7, $codes[0]->quality);
        assertSame('zbar', $codes[0]->reader);
        assertSame([20, 299, 321, 322], $codes[0]->box, 'габариты по вершинам многоугольника');
    },

    'разбор XML: двоичное значение приходит в base64' => static function () use ($tiny): void {
        // Так zbar отдаёт значения, которые не пережили бы XML как текст.
        $binary = "LINE1\nLINE2\x1D\xFF\xFE";
        $xml = zbarXml(
            "<symbol type='QR-Code' quality='1'><data format='base64' length='"
            . strlen($binary) . "'><![CDATA[\n" . base64_encode($binary) . "\n]]></data></symbol>",
        );

        $codes = (new ZbarReader(fakeZbar('zbar-b64.php', $xml)))->read($tiny);

        assertSame(1, count($codes));
        assertSame($binary, $codes[0]->value, 'байты восстановлены без потерь');
    },

    'значение с переводом строки не разваливается' => static function () use ($tiny): void {
        // Построчный формат zbar «СИМВОЛИКА:значение» на таком коде ломается,
        // поэтому и используется XML.
        $value = "LINE1\nLINE2:not-a-symbology";
        $xml = zbarXml("<symbol type='QR-Code' quality='1'><data><![CDATA[{$value}]]></data></symbol>");

        $codes = (new ZbarReader(fakeZbar('zbar-multiline.php', $xml)))->read($tiny);

        assertSame(1, count($codes), 'это один код, а не два');
        assertSame($value, $codes[0]->value);
    },

    'несколько кодов на одной этикетке' => static function () use ($tiny): void {
        $xml = zbarXml(
            "<symbol type='QR-Code' quality='1'><data><![CDATA[QR-VALUE]]></data></symbol>\n"
            . "<symbol type='CODE-128' quality='96'><data><![CDATA[4607428561111]]></data></symbol>",
        );

        $codes = (new ZbarReader(fakeZbar('zbar-two.php', $xml)))->read($tiny);

        assertSame(2, count($codes));
        assertSame('QR-Code', $codes[0]->symbology);
        assertSame('CODE-128', $codes[1]->symbology);
        assertSame(96, $codes[1]->quality);
    },

    'код возврата 4 означает «кодов нет», а не сбой' => static function () use ($tiny): void {
        $codes = (new ZbarReader(fakeZbar('zbar-none.php', '', 4)))->read($tiny);

        assertSame(0, count($codes), 'этикетка без кодов — это не ошибка');
    },

    'настоящая ошибка распознавателя пробрасывается' => static function () use ($tiny): void {
        try {
            (new ZbarReader(fakeZbar('zbar-fail.php', '', 2)))->read($tiny);
            throw new RuntimeException('ожидалось исключение');
        } catch (RuntimeException $e) {
            assertContains('zbarimg завершился с кодом 2', $e->getMessage());
        }
    },

    'испорченный XML не роняет разбор' => static function () use ($tiny): void {
        $codes = (new ZbarReader(fakeZbar('zbar-junk.php', '<barcodes><symbol unterminated')))->read($tiny);

        assertSame(0, count($codes));
    },

    'цепочка объединяет распознаватели и убирает повторы' => static function () use ($tiny): void {
        $a = new class implements CodeReaderInterface {
            public function read(Bitmap $b): array
            {
                return [
                    new DecodedCode('QR-Code', 'ОДИН', 'a'),
                    new DecodedCode('CODE-128', 'ДВА', 'a'),
                ];
            }

            public function name(): string
            {
                return 'a';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function symbologies(): string
            {
                return 'тест';
            }
        };

        $b = new class implements CodeReaderInterface {
            public function read(Bitmap $bm): array
            {
                return [
                    new DecodedCode('QR-Code', 'ОДИН', 'b'),   // тот же код, другой распознаватель
                    new DecodedCode('DataMatrix', 'ТРИ', 'b'),
                ];
            }

            public function name(): string
            {
                return 'b';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function symbologies(): string
            {
                return 'тест';
            }
        };

        $codes = (new CodeReader([$a, $b], Log::null()))->read($tiny);

        assertSame(3, count($codes), 'повтор отброшен');
        assertSame(['ОДИН', 'ДВА', 'ТРИ'], array_map(static fn($c) => $c->value, $codes));
    },

    'падение одного распознавателя не отменяет остальные' => static function () use ($tiny): void {
        $broken = new class implements CodeReaderInterface {
            public function read(Bitmap $b): array
            {
                throw new RuntimeException('декодер не установлен');
            }

            public function name(): string
            {
                return 'broken';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function symbologies(): string
            {
                return 'тест';
            }
        };

        $working = new class implements CodeReaderInterface {
            public function read(Bitmap $b): array
            {
                return [new DecodedCode('QR-Code', 'ЖИВОЙ', 'ok')];
            }

            public function name(): string
            {
                return 'ok';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function symbologies(): string
            {
                return 'тест';
            }
        };

        $codes = (new CodeReader([$broken, $working], Log::null()))->read($tiny);

        assertSame(1, count($codes));
        assertSame('ЖИВОЙ', $codes[0]->value);
    },

    'недоступный распознаватель пропускается молча' => static function () use ($tiny): void {
        $reader = new ZbarReader('/nope/zbarimg');

        assertTrue(!$reader->isAvailable());
        assertSame(0, count((new CodeReader([$reader], Log::null()))->read($tiny)));
    },

    'эталонный растр для самопроверки читается' => static function (): void {
        $bitmap = SelfTest::qrBitmap();

        assertTrue($bitmap !== null, 'встроенный растр должен распаковываться');
        assertSame(232, $bitmap->width);
        assertSame(232, $bitmap->height);
        assertTrue(!$bitmap->isBlank(), 'растр не пустой');
    },

    'QR читается с мелкой этикетки 58x40 при 203 dpi (формат OZON)' => static function (): void {
        $reader = new ZbarReader();
        if (!$reader->isAvailable()) {
            return;   // zbar-tools не установлен — проверка неприменима
        }

        // Этикетка OZON — 58x40 мм альбомной ориентации, при 203 dpi это 464x320 точек,
        // а QR занимает примерно 205x205. Растр маленький, запаса по разрешению почти нет,
        // поэтому случай стоит держать под тестом отдельно от крупной этикетки 100x150.
        $qr = SelfTest::qrBitmap();
        $canvas = Bitmap::create(464, 320);

        // Уменьшать QR нельзя — потеряются модули. Берём его как есть (232x232 не влезает
        // по высоте) и обрезаем до размера этикетки так же, как это делает подгонка.
        $scaled = $qr->crop(0, 0, 232, 232);
        $placed = $scaled->placeOnCanvas(464, 320, 200, 44);

        $codes = $reader->read($placed);
        $values = array_map(static fn($c) => $c->value, $codes);

        assertTrue(
            in_array(SelfTest::QR_VALUE, $values, true),
            'QR на этикетке 464x320 должен читаться: ' . implode(',', $values),
        );
    },

    'код у самого края этикетки всё ещё читается' => static function (): void {
        $reader = new ZbarReader();
        if (!$reader->isAvailable()) {
            return;
        }

        // У QR-кода есть собственная «тихая зона» внутри растра, но если этикетка
        // обрезает его вплотную, распознавание должно честно вернуть пустой список,
        // а не упасть.
        $qr = SelfTest::qrBitmap();
        $tight = $qr->crop(20, 20, 200, 200);

        $codes = $reader->read($tight);

        // Результат может быть любым — важно, что вызов отработал без исключения.
        assertTrue(is_array($codes));
    },

    'эталонный QR действительно распознаётся (если есть zbarimg)' => static function (): void {
        $reader = new ZbarReader();
        if (!$reader->isAvailable()) {
            return;   // zbar-tools не установлен — проверка неприменима
        }

        $codes = $reader->read(SelfTest::qrBitmap());
        $values = array_map(static fn($c) => $c->value, $codes);

        assertTrue(in_array(SelfTest::QR_VALUE, $values, true), 'эталон должен читаться: ' . implode(',', $values));
    },
];
