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
        'auto_rotate' => true,       // довернуть на 90, если страница лежит боком
        'quantity' => 1,
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
