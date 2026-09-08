<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

/**
 * Монохромный растр, 1 бит на пиксель.
 *
 * Формат хранения выбран так, чтобы совпадать сразу с двумя вещами:
 *   * выводом Ghostscript -sDEVICE=pbmraw (формат PBM P4);
 *   * телом графического поля ZPL ^GF.
 * В обоих бит со значением 1 означает ЧЁРНУЮ точку, старший бит байта — самый левый
 * пиксель, каждая строка дополняется нулями до целого числа байт.
 * Поэтому данные PBM уходят в ^GF без единого преобразования — только hex-кодирование.
 */
final class Bitmap
{
    /** Таблица разворота бит в байте — для зеркального отражения по горизонтали. */
    private static ?array $reverseTable = null;

    /**
     * @param string $data $bytesPerRow * $height байт упакованных пикселей
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $bytesPerRow,
        public readonly string $data,
    ) {
    }

    public static function create(int $width, int $height, ?string $data = null): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException("Некорректный размер растра: {$width}x{$height}");
        }

        $bytesPerRow = intdiv($width + 7, 8);
        $expected = $bytesPerRow * $height;
        $data ??= str_repeat("\x00", $expected);

        if (strlen($data) !== $expected) {
            throw new \InvalidArgumentException(
                "Размер данных растра {$width}x{$height}: ожидалось {$expected} байт, получено " . strlen($data),
            );
        }

        return new self($width, $height, $bytesPerRow, $data);
    }

    /**
     * Разбирает бинарный PBM (P4) — то, что отдаёт `gs -sDEVICE=pbmraw`.
     *
     * В спецификации PBM бит 1 = чёрный, что совпадает с ZPL, поэтому инверсия не нужна.
     */
    public static function fromPbm(string $pbm): self
    {
        [$values, $offset] = self::parseNetpbmHeader($pbm, 'P4', 2);
        [$width, $height] = $values;

        $bytesPerRow = intdiv($width + 7, 8);
        $need = $bytesPerRow * $height;
        $body = substr($pbm, $offset, $need);

        if (strlen($body) !== $need) {
            throw new \RuntimeException(
                "Обрезанный PBM: для {$width}x{$height} нужно {$need} байт, доступно " . strlen($body),
            );
        }

        return new self($width, $height, $bytesPerRow, $body);
    }

    /**
     * Разбирает бинарный PGM (P5, 8 бит серого) и бинаризует по порогу.
     *
     * Рендерим в серый и режем порогом сами, а не просим у Ghostscript готовый 1 бит:
     * так гарантированно не включается полутоновое растрирование, которое размывает
     * штрихкоды в решето и убивает их читаемость сканером.
     *
     * @param int $threshold 1..254; пиксели ЯРЧЕ порога считаются белыми
     */
    public static function fromPgm(string $pgm, int $threshold = 128): self
    {
        [$values, $offset] = self::parseNetpbmHeader($pgm, 'P5', 3);
        [$width, $height, $maxVal] = $values;

        if ($maxVal < 1 || $maxVal > 255) {
            throw new \RuntimeException("Поддерживается только 8-битный PGM, maxval={$maxVal}");
        }

        $need = $width * $height;
        if (strlen($pgm) - $offset < $need) {
            throw new \RuntimeException(
                "Обрезанный PGM: для {$width}x{$height} нужно {$need} байт, доступно " . (strlen($pgm) - $offset),
            );
        }

        // Порог задан в шкале 0..255; пересчитываем под фактический maxval файла.
        $cut = (int) round($threshold * $maxVal / 255);

        $bytesPerRow = intdiv($width + 7, 8);
        $out = str_repeat("\x00", $bytesPerRow * $height);
        $src = $offset;

        for ($y = 0; $y < $height; $y++) {
            $rowBase = $y * $bytesPerRow;
            for ($x = 0; $x < $width; $x++) {
                if (ord($pgm[$src + $x]) <= $cut) {          // тёмный пиксель -> печатаем точку
                    $i = $rowBase + ($x >> 3);
                    $out[$i] = chr(ord($out[$i]) | (0x80 >> ($x & 7)));
                }
            }
            $src += $width;
        }

        return new self($width, $height, $bytesPerRow, $out);
    }

    /** Сериализация обратно в PBM — для отладки и тестов. */
    public function toPbm(): string
    {
        return "P4\n{$this->width} {$this->height}\n" . $this->data;
    }

    public function pixel(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return false;
        }

        $byte = ord($this->data[$y * $this->bytesPerRow + ($x >> 3)]);

        return ($byte & (0x80 >> ($x & 7))) !== 0;
    }

    /** Одна строка растра как бинарная строка длиной bytesPerRow. */
    public function row(int $y): string
    {
        return substr($this->data, $y * $this->bytesPerRow, $this->bytesPerRow);
    }

    public function isBlank(): bool
    {
        return trim($this->data, "\x00") === '';
    }

    /** Негатив. Биты-заполнители справа от width обнуляются, чтобы не появилась чёрная кайма. */
    public function invert(): self
    {
        $inverted = ~$this->data;

        return self::create($this->width, $this->height, self::maskPadding($inverted, $this->width, $this->height, $this->bytesPerRow));
    }

    /** Поворот по часовой стрелке на 0/90/180/270 градусов. */
    public function rotate(int $degrees): self
    {
        return match ((($degrees % 360) + 360) % 360) {
            0 => $this,
            90 => $this->transpose()->flipHorizontal(),
            180 => $this->flipHorizontal()->flipVertical(),
            270 => $this->transpose()->flipVertical(),
            default => throw new \InvalidArgumentException("Поворот поддерживается только на 0, 90, 180 или 270 градусов, задано {$degrees}"),
        };
    }

    /** Зеркало по вертикали — просто обратный порядок строк. */
    public function flipVertical(): self
    {
        $out = '';
        for ($y = $this->height - 1; $y >= 0; $y--) {
            $out .= $this->row($y);
        }

        return new self($this->width, $this->height, $this->bytesPerRow, $out);
    }

    /** Зеркало по горизонтали: разворот бит в строке с последующим сдвигом на биты-заполнители. */
    public function flipHorizontal(): self
    {
        $table = self::reverseTable();
        $pad = $this->bytesPerRow * 8 - $this->width;

        $out = '';
        for ($y = 0; $y < $this->height; $y++) {
            $row = $this->row($y);
            $reversed = '';
            for ($i = $this->bytesPerRow - 1; $i >= 0; $i--) {
                $reversed .= $table[ord($row[$i])];
            }
            // После разворота значащие биты уехали вправо на величину заполнителя — возвращаем влево.
            $out .= $pad > 0 ? self::shiftRowLeft($reversed, $pad) : $reversed;
        }

        return new self($this->width, $this->height, $this->bytesPerRow, $out);
    }

    /**
     * Транспонирование (отражение относительно главной диагонали).
     * Работает блоками 8x8 бит по алгоритму из Hacker's Delight: примерно в 20 раз
     * быстрее, чем попиксельный цикл, что для этикетки 812x1218 решает.
     */
    public function transpose(): self
    {
        // Дополняем до кратности 8 — «хвосты» станут белыми строками/столбцами
        // справа и снизу, и после транспонирования обрежутся оттуда же.
        $padH = intdiv($this->height + 7, 8) * 8;
        $src = $this->height === $padH ? $this : $this->padBottom($padH);

        $srcBytesPerRow = $src->bytesPerRow;   // = ширина, дополненная до кратности 8, делённая на 8
        $blockCols = $srcBytesPerRow;
        $blockRows = intdiv($padH, 8);

        $newWidth = $padH;                     // ширина результата = высота источника
        $newHeight = $srcBytesPerRow * 8;      // высота результата = дополненная ширина источника
        $newBytesPerRow = intdiv($newWidth, 8);
        $out = str_repeat("\x00", $newBytesPerRow * $newHeight);

        for ($by = 0; $by < $blockRows; $by++) {
            $rowBase = $by * 8 * $srcBytesPerRow;
            for ($bx = 0; $bx < $blockCols; $bx++) {
                $o = $rowBase + $bx;

                $x = (ord($src->data[$o]) << 24)
                    | (ord($src->data[$o + $srcBytesPerRow]) << 16)
                    | (ord($src->data[$o + 2 * $srcBytesPerRow]) << 8)
                    | ord($src->data[$o + 3 * $srcBytesPerRow]);
                $y = (ord($src->data[$o + 4 * $srcBytesPerRow]) << 24)
                    | (ord($src->data[$o + 5 * $srcBytesPerRow]) << 16)
                    | (ord($src->data[$o + 6 * $srcBytesPerRow]) << 8)
                    | ord($src->data[$o + 7 * $srcBytesPerRow]);

                $t = ($x ^ ($x >> 7)) & 0x00AA00AA;
                $x = $x ^ $t ^ (($t << 7) & 0xFFFFFFFF);
                $t = ($y ^ ($y >> 7)) & 0x00AA00AA;
                $y = $y ^ $t ^ (($t << 7) & 0xFFFFFFFF);

                $t = ($x ^ ($x >> 14)) & 0x0000CCCC;
                $x = $x ^ $t ^ (($t << 14) & 0xFFFFFFFF);
                $t = ($y ^ ($y >> 14)) & 0x0000CCCC;
                $y = $y ^ $t ^ (($t << 14) & 0xFFFFFFFF);

                $t = ($x & 0xF0F0F0F0) | (($y >> 4) & 0x0F0F0F0F);
                $y = (($x << 4) & 0xF0F0F0F0) | ($y & 0x0F0F0F0F);
                $x = $t;

                // Блок (bx, by) источника становится блоком (by, bx) результата.
                $d = $bx * 8 * $newBytesPerRow + $by;
                $out[$d] = chr(($x >> 24) & 0xFF);
                $out[$d + $newBytesPerRow] = chr(($x >> 16) & 0xFF);
                $out[$d + 2 * $newBytesPerRow] = chr(($x >> 8) & 0xFF);
                $out[$d + 3 * $newBytesPerRow] = chr($x & 0xFF);
                $out[$d + 4 * $newBytesPerRow] = chr(($y >> 24) & 0xFF);
                $out[$d + 5 * $newBytesPerRow] = chr(($y >> 16) & 0xFF);
                $out[$d + 6 * $newBytesPerRow] = chr(($y >> 8) & 0xFF);
                $out[$d + 7 * $newBytesPerRow] = chr($y & 0xFF);
            }
        }

        $padded = new self($newWidth, $newHeight, $newBytesPerRow, $out);

        // Обрезаем добавленные белые поля: результат должен быть height x width.
        return $padded->crop(0, 0, $this->height, $this->width);
    }

    /** Вырезает прямоугольник. Координаты за пределами растра дополняются белым. */
    public function crop(int $left, int $top, int $width, int $height): self
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException("Некорректный размер обрезки: {$width}x{$height}");
        }

        if ($left === 0 && $top === 0 && $width === $this->width && $height === $this->height) {
            return $this;
        }

        $newBytesPerRow = intdiv($width + 7, 8);

        // Быстрый путь: смещение кратно байту — копируем строки блоками, без побитовой возни.
        // Обрабатывает и отрицательное смещение (растр «утоплен» внутрь большего холста).
        if ($left % 8 === 0) {
            $srcByte = intdiv($left, 8);
            $padLeft = max(0, -$srcByte);
            $copyFrom = max(0, $srcByte);
            $copyLen = max(0, min($newBytesPerRow - $padLeft, $this->bytesPerRow - $copyFrom));
            $padRight = $newBytesPerRow - $padLeft - $copyLen;

            $blankRow = str_repeat("\x00", $newBytesPerRow);
            $leftFill = $padLeft > 0 ? str_repeat("\x00", $padLeft) : '';
            $rightFill = $padRight > 0 ? str_repeat("\x00", $padRight) : '';

            $out = '';
            for ($y = 0; $y < $height; $y++) {
                $sy = $top + $y;
                if ($sy < 0 || $sy >= $this->height || $copyLen === 0) {
                    $out .= $blankRow;
                    continue;
                }
                $out .= $leftFill
                    . substr($this->data, $sy * $this->bytesPerRow + $copyFrom, $copyLen)
                    . $rightFill;
            }

            return self::create($width, $height, self::maskPadding($out, $width, $height, $newBytesPerRow));
        }

        $out = str_repeat("\x00", $newBytesPerRow * $height);
        for ($y = 0; $y < $height; $y++) {
            $rowBase = $y * $newBytesPerRow;
            for ($x = 0; $x < $width; $x++) {
                if ($this->pixel($left + $x, $top + $y)) {
                    $i = $rowBase + ($x >> 3);
                    $out[$i] = chr(ord($out[$i]) | (0x80 >> ($x & 7)));
                }
            }
        }

        return new self($width, $height, $newBytesPerRow, $out);
    }

    /**
     * Размещает растр на белом холсте заданного размера.
     * Нужно, чтобы ширина точно совпала с ^PW принтера: иначе печать «съезжает».
     */
    public function placeOnCanvas(int $canvasWidth, int $canvasHeight, int $offsetX = 0, int $offsetY = 0): self
    {
        return $this->crop(-$offsetX, -$offsetY, $canvasWidth, $canvasHeight);
    }

    /**
     * Габариты чёрного содержимого: [left, top, width, height] или null, если растр пуст.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    public function contentBox(): ?array
    {
        $top = null;
        $bottom = 0;
        for ($y = 0; $y < $this->height; $y++) {
            if (trim($this->row($y), "\x00") !== '') {
                $top ??= $y;
                $bottom = $y;
            }
        }

        if ($top === null) {
            return null;
        }

        $left = $this->width;
        $right = -1;
        for ($y = $top; $y <= $bottom; $y++) {
            $row = $this->row($y);
            for ($b = 0; $b < $this->bytesPerRow; $b++) {
                $byte = ord($row[$b]);
                if ($byte === 0) {
                    continue;
                }
                for ($bit = 0; $bit < 8; $bit++) {
                    if (($byte & (0x80 >> $bit)) !== 0) {
                        $x = $b * 8 + $bit;
                        if ($x < $left) {
                            $left = $x;
                        }
                        if ($x > $right) {
                            $right = $x;
                        }
                    }
                }
            }
        }

        return [$left, $top, $right - $left + 1, $bottom - $top + 1];
    }

    /** Доля чёрных пикселей — грубый индикатор «этикетка пустая / инвертированная». */
    public function inkCoverage(): float
    {
        $bits = 0;
        $len = strlen($this->data);
        for ($i = 0; $i < $len; $i++) {
            // popcount без gmp: считаем через строку двоичного представления.
            $bits += substr_count(decbin(ord($this->data[$i])), '1');
        }

        return $this->width * $this->height > 0 ? $bits / ($this->width * $this->height) : 0.0;
    }

    private function padBottom(int $newHeight): self
    {
        $extra = ($newHeight - $this->height) * $this->bytesPerRow;

        return new self($this->width, $newHeight, $this->bytesPerRow, $this->data . str_repeat("\x00", $extra));
    }

    /**
     * Разбор заголовка Netpbm: магия, затем $count целых, разделённых пробелами,
     * с поддержкой комментариев '#'. Возвращает [значения, смещение данных].
     *
     * @return array{0:list<int>,1:int}
     */
    private static function parseNetpbmHeader(string $raw, string $magic, int $count): array
    {
        if (!str_starts_with($raw, $magic)) {
            throw new \RuntimeException(
                "Ожидался формат {$magic}, получено '" . substr($raw, 0, 2) . "'. "
                . 'Проверьте -sDEVICE у Ghostscript.',
            );
        }

        $pos = strlen($magic);
        $len = strlen($raw);
        $values = [];

        while (count($values) < $count) {
            // Пропускаем пробелы и комментарии.
            while ($pos < $len) {
                $ch = $raw[$pos];
                if ($ch === '#') {
                    while ($pos < $len && $raw[$pos] !== "\n" && $raw[$pos] !== "\r") {
                        $pos++;
                    }
                    continue;
                }
                if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === "\v" || $ch === "\f") {
                    $pos++;
                    continue;
                }
                break;
            }

            $start = $pos;
            while ($pos < $len && $raw[$pos] >= '0' && $raw[$pos] <= '9') {
                $pos++;
            }
            if ($pos === $start) {
                throw new \RuntimeException("Повреждённый заголовок {$magic} на позиции {$pos}");
            }
            $values[] = (int) substr($raw, $start, $pos - $start);
        }

        // Ровно один разделитель после последнего числа, дальше идут данные.
        if ($pos < $len) {
            $pos++;
        }

        return [$values, $pos];
    }

    /** Обнуляет биты правее width в каждой строке. */
    private static function maskPadding(string $data, int $width, int $height, int $bytesPerRow): string
    {
        $pad = $bytesPerRow * 8 - $width;
        if ($pad === 0) {
            return $data;
        }

        $mask = chr((0xFF << $pad) & 0xFF);
        for ($y = 0; $y < $height; $y++) {
            $i = $y * $bytesPerRow + $bytesPerRow - 1;
            $data[$i] = chr(ord($data[$i]) & ord($mask));
        }

        return $data;
    }

    /** Сдвигает строку байт влево на $bits (0..7). */
    private static function shiftRowLeft(string $row, int $bits): string
    {
        if ($bits <= 0) {
            return $row;
        }

        $len = strlen($row);
        $out = str_repeat("\x00", $len);
        for ($i = 0; $i < $len; $i++) {
            $cur = ord($row[$i]) << $bits;
            $next = $i + 1 < $len ? ord($row[$i + 1]) >> (8 - $bits) : 0;
            $out[$i] = chr(($cur | $next) & 0xFF);
        }

        return $out;
    }

    /** @return list<string> */
    private static function reverseTable(): array
    {
        if (self::$reverseTable !== null) {
            return self::$reverseTable;
        }

        $table = [];
        for ($i = 0; $i < 256; $i++) {
            $r = 0;
            for ($b = 0; $b < 8; $b++) {
                if (($i & (1 << $b)) !== 0) {
                    $r |= 1 << (7 - $b);
                }
            }
            $table[$i] = chr($r);
        }

        return self::$reverseTable = $table;
    }
}
