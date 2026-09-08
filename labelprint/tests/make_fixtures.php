<?php
declare(strict_types=1);

/**
 * Генератор тестовых фикстур: делает 1-битные PBM (P4) картинки без Ghostscript,
 * чтобы можно было тестировать ZPL/TSPL-кодеки в любой среде.
 *
 * Использование: php tests/make_fixtures.php [outDir]
 */

// При запуске из run.php аргументы принадлежат раннеру — берём путь только у прямого вызова.
$directRun = isset($argv) && realpath($argv[0] ?? '') === realpath(__FILE__);
$outDir = ($directRun ? ($argv[1] ?? null) : null) ?? __DIR__ . '/fixtures';
@mkdir($outDir, 0775, true);

/**
 * Собирает P4 (binary PBM). В PBM бит 1 = ЧЁРНЫЙ пиксель, строки выравниваются до байта.
 *
 * @param callable(int,int):bool $isBlack fn(x, y): bool
 */
function makePbm(int $width, int $height, callable $isBlack): string
{
    $bytesPerRow = intdiv($width + 7, 8);
    $rows = '';
    for ($y = 0; $y < $height; $y++) {
        $row = str_repeat("\x00", $bytesPerRow);
        for ($x = 0; $x < $width; $x++) {
            if ($isBlack($x, $y)) {
                $i = $x >> 3;
                $row[$i] = chr(ord($row[$i]) | (0x80 >> ($x & 7)));
            }
        }
        $rows .= $row;
    }

    return "P4\n{$width} {$height}\n" . $rows;
}

$fixtures = [];

// 1. Полностью белая этикетка — проверка сжатия (должна ужаться почти до нуля).
$fixtures['blank_812x1218.pbm'] = makePbm(812, 1218, static fn(): bool => false);

// 2. Полностью чёрная — проверка обратной полярности и максимального размера.
$fixtures['solid_100x100.pbm'] = makePbm(100, 100, static fn(): bool => true);

// 3. Шахматка 1x1 — худший случай для RLE, ничего не сжимается.
$fixtures['checker_64x64.pbm'] = makePbm(64, 64, static fn(int $x, int $y): bool => (($x + $y) & 1) === 1);

// 4. Рамка + диагональ — визуально проверяемая ориентация (верх/низ, лево/право).
$fixtures['frame_203x203.pbm'] = makePbm(203, 203, static function (int $x, int $y): bool {
    if ($x < 4 || $y < 4 || $x > 198 || $y > 198) {
        return true;             // рамка
    }
    if (abs($x - $y) < 3) {
        return true;             // диагональ из левого верхнего угла
    }
    return $y < 24 && $x < 100;  // «маркер верха» — чёрный блок слева сверху
});

// 5. Имитация штрихкода Code128 — вертикальные штрихи разной ширины.
$fixtures['barcode_400x120.pbm'] = makePbm(400, 120, static function (int $x, int $y): bool {
    if ($y < 10 || $y > 109) {
        return false;
    }
    $pattern = [2, 1, 2, 2, 3, 1, 1, 3, 2, 1, 4, 1, 2, 2, 1, 3];
    $pos = 20;
    $ink = true;
    foreach ($pattern as $w) {
        for ($r = 0; $r < 6; $r++) {                 // повторяем паттерн, чтобы заполнить ширину
            $span = $w * 2;
            if ($x >= $pos && $x < $pos + $span) {
                return $ink;
            }
            $pos += $span;
            $ink = !$ink;
        }
    }
    return false;
});

// 6. Ширина не кратная 8 — ловит ошибки паддинга строк.
$fixtures['odd_width_13x5.pbm'] = makePbm(13, 5, static fn(int $x, int $y): bool => $x === 12 || $y === 0);

foreach ($fixtures as $name => $data) {
    file_put_contents($outDir . '/' . $name, $data);
    printf("%-24s %7d байт\n", $name, strlen($data));
}

echo "\nФикстуры записаны в {$outDir}\n";
