#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Сверка напечатанной этикетки со сканером.
 *
 * Рабочий процесс на складе: оператор сканирует товар, печатает этикетку,
 * наклеивает её и сканирует уже наклеенную. Эта команда отвечает на вопрос
 * «то ли наклеили»:
 *
 *   php bin/verify.php --label=42 "WB-1234567890-BOX3"   # ожидали эту этикетку?
 *   php bin/verify.php "4607428561111"                    # какой этикетке принадлежит код
 *   php bin/verify.php --file=wb-12345.pdf "4607428561111"
 *
 * Код возврата: 0 — совпало, 1 — не совпало. Удобно вызывать из скрипта.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/verify.php запускается только из командной строки\n");
}

require __DIR__ . '/../src/bootstrap.php';

use LabelPrint\App;
use LabelPrint\Barcode\DecodedCode;
use LabelPrint\Support\Args;

try {
    $options = Args::parse($argv, ['json', 'help'], ['label', 'file', 'profile', 'page', 'config']);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($options->has('help') || $options->first() === null) {
    echo <<<TXT
    Сверка напечатанной этикетки с тем, что считал сканер.

      php bin/verify.php [опции] "СЧИТАННОЕ_ЗНАЧЕНИЕ"

      --label=ID       сверить с конкретной этикеткой (id из zpl_labels)
      --file=ПУТЬ      сверить с этикеткой этого файла
      --page=N         номер страницы при --file (по умолчанию 1)
      --profile=КОД    профиль при --file (по умолчанию из конфига)
      --json           вывод в JSON — для вызова из другой программы
      --config=ПУТЬ    альтернативный config.php

    Без --label и --file команда ищет, какой этикетке принадлежит код.
    Код возврата: 0 — совпало, 1 — не совпало.

    TXT;
    exit($options->has('help') ? 0 : 2);
}

$app = App::boot($options->value('config'));
$scanned = (string) $options->first();
$codes = $app->codeStore();
$db = $app->db();

$labelId = null;

if ($options->value('label') !== null) {
    $labelId = (int) $options->value('label');
} elseif ($options->value('file') !== null) {
    $profileCode = $options->value('profile') ?? $app->config->string('default_profile');
    $profile = $app->profiles()->get($profileCode);
    $pageNo = (int) ($options->value('page') ?? '1');

    $row = $db->fetchOne(
        'SELECT l.id FROM zpl_labels l
           JOIN pdf_files f ON f.id = l.pdf_file_id
          WHERE f.path = ? AND l.pdf_sha256 = f.sha256
            AND l.profile_fingerprint = ? AND l.page_no = ?',
        [$options->value('file'), $profile->fingerprint(), $pageNo],
    );

    if ($row === null) {
        report(false, 'этикетка для такого файла, профиля и страницы не найдена', [], $options->has('json'));
    }

    $labelId = (int) $row['id'];
}

if ($labelId !== null) {
    $expected = $codes->forLabel($labelId);

    if ($expected === []) {
        report(false, "на этикетке #{$labelId} не распознано ни одного кода — сверять не с чем", [], $options->has('json'));
    }

    $ok = $codes->matches($labelId, $scanned);
    report(
        $ok,
        $ok ? "совпало с этикеткой #{$labelId}" : "НЕ совпало с этикеткой #{$labelId}",
        array_map(
            static fn(array $c): array => [
                'symbology' => $c['symbology'],
                'value' => (string) $c['value'],
            ],
            $expected,
        ),
        $options->has('json'),
    );
}

// Обратное направление: по считанному коду найти этикетку.
$found = $codes->findByValue($scanned);
report(
    $found !== [],
    $found !== [] ? 'код найден' : 'код не найден ни на одной этикетке',
    $found,
    $options->has('json'),
);

/**
 * @param list<array<string,mixed>> $details
 */
function report(bool $ok, string $message, array $details, bool $json): never
{
    if ($json) {
        echo json_encode(
            ['ok' => $ok, 'message' => $message, 'details' => $details],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE,
        ), "\n";
    } else {
        printf("%s %s\n", $ok ? "\033[32m[ OK ]\033[0m" : "\033[31m[ НЕТ ]\033[0m", $message);
        foreach ($details as $d) {
            $line = [];
            foreach ($d as $k => $v) {
                $line[] = $k . '=' . preg_replace('/[\x00-\x1F\x7F]/', '·', (string) $v);
            }
            echo '       ' . implode('  ', $line) . "\n";
        }
    }

    exit($ok ? 0 : 1);
}
