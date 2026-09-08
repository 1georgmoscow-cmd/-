<?php
declare(strict_types=1);

/**
 * Скопируйте в config/printers.php.
 * Профиль описывает этикетку и принтер; его код попадает в таблицу готовых ZPL,
 * поэтому один PDF можно держать отрендеренным сразу под несколько принтеров.
 */

return [
    // Классика маркетплейсов: 100x150 мм на 203 dpi.
    'zebra_203_100x150' => [
        'title' => 'Zebra 203 dpi, 100×150 мм',
        'dpi' => 203,
        'width_mm' => 100,
        'height_mm' => 150,
        'fit' => 'fit',              // вписывать страницу PDF в размер этикетки
        'rotate' => 0,
        'threshold' => 128,
        'invert' => false,
        'compression' => 'acs',      // acs | z64 | hex
        'darkness' => null,          // ^MD: null — не менять настройку принтера
        'print_rate' => null,        // ^PR
        'media_tracking' => 'gap',   // gap | mark | continuous | null
        'print_mode' => null,        // tear | peel | cutter | rewind | applicator | null
        'engine' => null,            // ghostscript | mupdf | auto | null (взять из config.php)
        'printhead_dots' => 832,     // ширина головки 4 дюйма при 203 dpi
        'auto_rotate' => true,       // довернуть на 90, если страница лежит боком
        'quantity' => 1,
    ],

    // Этикетка OZON: 58x40 мм, альбомная (страница PDF 164.25 x 113.25 pt).
    //
    // Содержимое свёрстано повёрнутым — текст отправления читается вдоль длинной
    // стороны. Так его и печатает OZON, поэтому auto_rotate здесь ОБЯЗАН молчать:
    // ориентация страницы и этикетки совпадают, доворачивать нечего.
    //
    // Машиночитаемый код на этикетке один — QR. DataMatrix («Честный знак») на
    // ней не бывает, поэтому barcode.dmtx в config.php можно оставить выключенным:
    // это экономит около 200 мс на каждую этикетку.
    'ozon_203_58x40' => [
        'title' => 'OZON, 203 dpi, 58×40 мм',
        'dpi' => 203,
        'width_mm' => 58,
        'height_mm' => 40,
        'fit' => 'fit',
        'auto_rotate' => true,       // сработает только при несовпадении ориентаций
        'compression' => 'acs',
        'printhead_dots' => 464,     // головка 58 мм при 203 dpi
        'media_tracking' => 'gap',
    ],

    // То же самое на принтере 300 dpi.
    'ozon_300_58x40' => [
        'title' => 'OZON, 300 dpi, 58×40 мм',
        'dpi' => 300,
        'width_mm' => 58,
        'height_mm' => 40,
        'fit' => 'fit',
        'compression' => 'acs',
        'printhead_dots' => 684,
    ],

    // Мелкая этикетка 58x40 мм.
    'zebra_203_58x40' => [
        'title' => 'Zebra 203 dpi, 58×40 мм',
        'dpi' => 203,
        'width_mm' => 58,
        'height_mm' => 40,
        'fit' => 'fit',
        'compression' => 'acs',
    ],

    // 300 dpi: мельче шрифт и плотнее штрихкод, растр вчетверо тяжелее — есть смысл в z64.
    'zebra_300_100x150' => [
        'title' => 'Zebra 300 dpi, 100×150 мм',
        'dpi' => 300,
        'width_mm' => 100,
        'height_mm' => 150,
        'fit' => 'fit',
        'compression' => 'z64',
    ],

    // Печать «как есть»: размер берём из PDF, ничего не вписываем.
    'zebra_203_native' => [
        'title' => 'Zebra 203 dpi, размер из PDF',
        'dpi' => 203,
        'fit' => 'native',
        'compression' => 'acs',
    ],
];
