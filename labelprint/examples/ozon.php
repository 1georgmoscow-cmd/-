#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Этикетка OZON по номеру отправления — то, ради чего всё делалось.
 *
 * На входе posting_id, на выходе ZPL и распознанный код для последующей сверки.
 *
 *   php examples/ozon.php 0494051806-0963-1
 *   php examples/ozon.php 0494051806-0963-1 /tmp/скачанный.pdf 192.168.1.50
 */

require __DIR__ . '/../src/bootstrap.php';

use LabelPrint\Api;

$postingId = $argv[1] ?? null;
$pdfPath = $argv[2] ?? null;       // если PDF только что скачан из API OZON
$printerHost = $argv[3] ?? null;

if ($postingId === null) {
    fwrite(STDERR, "Использование: php examples/ozon.php POSTING_ID [ФАЙЛ.pdf] [АДРЕС_ПРИНТЕРА]\n");
    exit(2);
}

$api = Api::boot();

// ---------------------------------------------------------------------------
// Получение этикетки: два способа, оба возвращают одно и то же
// ---------------------------------------------------------------------------
if ($pdfPath !== null) {
    // Способ 1. PDF только что скачан из API OZON — отдаём байты вместе с номером.
    // Надёжнее: имя файла из API служебное (print_to_sticker_11036.pdf) и номера
    // отправления в себе не содержит.
    //
    //   $pdf = ozon_api_package_label($postingId);   // ваш код
    //   $label = $api->savePosting($postingId, $pdf);
    $label = $api->savePosting($postingId, $pdfPath);
} else {
    // Способ 2. Файл уже лежит в pdf_dir и назван номером отправления
    // (0494051806-0963-1.pdf) — сканер связал их сам.
    $label = $api->byPosting($postingId);
}

if ($label === null) {
    fwrite(STDERR, "Этикетка для отправления {$postingId} не найдена\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Вот те самые две величины
// ---------------------------------------------------------------------------
$zpl = $label->zpl;            // текст этикетки на языке ZPL
$barcode = $label->barcode;    // распознанный QR — для сверки после наклейки

printf("posting_id : %s\n", $label->postingId ?? $postingId);
printf("barcode    : %s\n", $barcode ?? '(код не распознан!)');
printf("zpl        : %s байт\n", number_format(strlen($zpl)));
printf("этикетка   : %d×%d точек при %d dpi, профиль %s\n",
    $label->widthDots, $label->heightDots, $label->dpi, $label->profileCode);

if ($barcode === null) {
    // Печатать можно, но подтвердить сканером оператор не сможет.
    fwrite(STDERR, "ВНИМАНИЕ: на этикетке не распознан код — сверка со сканером невозможна\n");
}

// ---------------------------------------------------------------------------
// Печать и сверка
// ---------------------------------------------------------------------------
if ($printerHost !== null) {
    $api->send($zpl, $printerHost);
    printf("\nОтправлено на принтер %s\n", $printerHost);

    // Здесь оператор наклеивает этикетку и сканирует её.
    // $scanned = ...ввод со сканера штрихкода...
    $scanned = $barcode;

    printf("Сверка: %s\n", $api->verify($label->id, (string) $scanned) ? 'та этикетка' : 'НЕ та этикетка');
}

// Если нужен JSON для ответа во внешнюю систему:
// echo json_encode($label->toArray(), JSON_UNESCAPED_UNICODE);
