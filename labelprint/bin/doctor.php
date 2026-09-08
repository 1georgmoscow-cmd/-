#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Проверка окружения перед запуском: php bin/doctor.php
 *
 * Ловит ровно те вещи, из-за которых сервис молча не работает после развёртывания:
 * нет Ghostscript, не хватает прав на каталог, мал max_allowed_packet,
 * старая MySQL без SKIP LOCKED.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/doctor.php запускается только из командной строки\n");
}

require __DIR__ . '/../src/bootstrap.php';

use LabelPrint\App;

$ok = 0;
$warn = 0;
$fail = 0;

function check(string $title, callable $probe): void
{
    global $ok, $warn, $fail;

    try {
        [$status, $detail] = $probe();
    } catch (Throwable $e) {
        $status = 'fail';
        $detail = $e->getMessage();
    }

    $mark = match ($status) {
        'ok' => "\033[32m  ok  \033[0m",
        'warn' => "\033[33m warn \033[0m",
        default => "\033[31m FAIL \033[0m",
    };

    printf("[%s] %-38s %s\n", $mark, $title, $detail);

    match ($status) {
        'ok' => $ok++,
        'warn' => $warn++,
        default => $fail++,
    };
}

/** Запускает утилиту и возвращает первую строку её вывода. */
function probeVersion(string $binary, array $args, int $limit = 200): string
{
    $process = @proc_open([$binary, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return '(не запускается)';
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $text = trim($out !== '' ? $out : $err);

    return $limit > 500 ? $text : trim(strtok($text, "\n") ?: '');
}

echo "Проверка окружения labelprint\n\n";

check('PHP версия', static fn(): array => PHP_VERSION_ID >= 80100
    ? ['ok', PHP_VERSION]
    : ['fail', PHP_VERSION . ' — нужен PHP 8.1+']);

foreach (['pdo_mysql', 'zlib', 'pcntl', 'mbstring'] as $extension) {
    check("расширение {$extension}", static fn(): array => extension_loaded($extension)
        ? ['ok', 'установлено']
        : ['fail', 'отсутствует: apt install php-' . str_replace('pdo_', '', $extension)]);
}

try {
    $app = App::boot();
} catch (Throwable $e) {
    echo "\n\033[31mКонфигурация не загружается:\033[0m " . $e->getMessage() . "\n";
    exit(2);
}

check('config/config.php', static fn(): array => ['ok', 'загружен']);

check('config/printers.php', static function () use ($app): array {
    $codes = $app->profiles()->codes();

    return ['ok', count($codes) . ' профилей: ' . implode(', ', $codes)];
});

check('профиль по умолчанию', static function () use ($app): array {
    $code = $app->config->string('default_profile');
    $app->profiles()->get($code);

    return ['ok', $code];
});

check('размер полей ^GF в профилях', static function () use ($app): array {
    $over = [];
    foreach ($app->profiles()->all() as $code => $profile) {
        if ($profile->fit !== 'fit') {
            continue;   // в режиме native размер известен только во время рендеринга
        }
        $bytesPerRow = intdiv($profile->widthDots() + 7, 8);
        if (\LabelPrint\Render\ZplEncoder::exceedsDocumentedLimit($bytesPerRow, $profile->heightDots())) {
            $over[] = sprintf('%s (%s)', $code, number_format($bytesPerRow * $profile->heightDots(), 0, '.', ' '));
        }
    }

    if ($over === []) {
        return ['ok', 'все в пределах 99 999'];
    }

    return ['warn', 'выше документированного предела 99 999: ' . implode(', ', $over)
        . '. Прошивки Zebra такие значения принимают (их выдаёт и ZebraDesigner), '
        . 'но если этикетка печатается обрезанной — причина может быть здесь'];
});

check('каталог с PDF', static function () use ($app): array {
    $dir = $app->config->string('pdf_dir');
    if (!is_dir($dir)) {
        return ['fail', "{$dir} не существует"];
    }
    if (!is_readable($dir)) {
        return ['fail', "{$dir} недоступен для чтения пользователю " . (posix_getpwuid(posix_geteuid())['name'] ?? '?')];
    }

    return ['ok', $dir];
});

check('Ghostscript', static function () use ($app): array {
    $binary = $app->config->string('ghostscript', '/usr/bin/gs');
    if (!is_executable($binary)) {
        return ['fail', "{$binary} не найден: apt install ghostscript"];
    }

    return ['ok', $binary . ' версия ' . probeVersion($binary, ['--version'])];
});

check('mutool (mupdf-tools)', static function () use ($app): array {
    $binary = $app->config->string('mutool', '/usr/bin/mutool');
    if (!is_executable($binary)) {
        return ['warn', 'нет — движок mupdf недоступен (apt install mupdf-tools). '
            . 'Он примерно втрое быстрее Ghostscript'];
    }

    return ['ok', $binary . ' ' . probeVersion($binary, ['-v'])];
});

check('движок растеризации', static function () use ($app): array {
    $engine = $app->config->string('render.engine', 'ghostscript');
    $rasterizer = $app->rasterizers()->make($engine);

    if (!$rasterizer->isAvailable()) {
        return ['fail', "выбран {$engine}, но {$rasterizer->name()} не установлен"];
    }

    return ['ok', "{$engine} -> {$rasterizer->name()}"];
});

check('устройство pgmraw в Ghostscript', static function () use ($app): array {
    $binary = $app->config->string('ghostscript', '/usr/bin/gs');
    if (!is_executable($binary)) {
        return ['warn', 'Ghostscript не установлен, проверка пропущена'];
    }

    $out = probeVersion($binary, ['-h'], 8192);

    return str_contains($out, 'pgmraw')
        ? ['ok', 'доступно']
        : ['warn', 'не найдено в списке устройств — проверьте сборку gs'];
});

check('pdfinfo (poppler-utils)', static fn(): array => $app->pdfinfo() !== null
    ? ['ok', (string) $app->pdfinfo()]
    : ['warn', 'нет — авто-поворот будет работать по встроенному разбору PDF']);

check('zbarimg (zbar-tools)', static function () use ($app): array {
    $binary = $app->config->string('zbarimg', '/usr/bin/zbarimg');
    if (!$app->config->bool('barcode.zbar', true)) {
        return ['ok', 'распознавание отключено в конфиге'];
    }
    if (!is_executable($binary)) {
        return ['warn', "{$binary} не найден: apt install zbar-tools. "
            . 'Без него QR и штрихкоды на этикетках распознаваться не будут'];
    }

    return ['ok', $binary . ' ' . probeVersion($binary, ['--version'])];
});

check('dmtxread (dmtx-utils)', static function () use ($app): array {
    if (!$app->config->bool('barcode.dmtx', false)) {
        return ['ok', 'DataMatrix выключен (около 210 мс на этикетку)'];
    }
    $binary = $app->config->string('dmtxread', '/usr/bin/dmtxread');
    if (!is_executable($binary)) {
        return ['fail', "включён barcode.dmtx, но {$binary} не найден: apt install dmtx-utils"];
    }

    return ['ok', $binary . ' ' . probeVersion($binary, ['--version'])];
});

check('распознавание кодов работает', static function () use ($app): array {
    $reader = $app->codeReader();
    if (!$reader->isEnabled()) {
        return ['ok', 'выключено'];
    }

    // Настоящая проверка: собираем QR прямо здесь и пробуем его прочитать.
    $bitmap = \LabelPrint\Barcode\SelfTest::qrBitmap();
    if ($bitmap === null) {
        return ['warn', 'эталонный QR недоступен, проверка пропущена'];
    }

    $codes = $reader->read($bitmap, 'самопроверка');
    foreach ($codes as $code) {
        if ($code->value === \LabelPrint\Barcode\SelfTest::QR_VALUE) {
            return ['ok', 'эталонный QR распознан (' . $code->symbology . ')'];
        }
    }

    return ['fail', 'эталонный QR не распознан — проверьте установку zbar-tools'];
});

check('подключение к MySQL', static function () use ($app): array {
    $app->db()->pdo();

    return ['ok', $app->db()->serverVersion()];
});

check('захват задач через SKIP LOCKED', static fn(): array => $app->db()->supportsSkipLocked()
    ? ['ok', 'поддерживается']
    : ['warn', 'нет (MySQL < 8.0) — используется запасной вариант UPDATE ... LIMIT 1']);

check('таблицы схемы', static function () use ($app): array {
    $missing = [];
    foreach (['pdf_files', 'render_jobs', 'zpl_labels'] as $table) {
        // SHOW TABLES LIKE ? не работает с подготовленными выражениями,
        // поэтому спрашиваем information_schema.
        $row = $app->db()->fetchOne(
            'SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        );
        if ($row === null) {
            $missing[] = $table;
        }
    }

    return $missing === []
        ? ['ok', 'все на месте']
        : ['fail', 'нет таблиц: ' . implode(', ', $missing) . ' — примените db/schema.mysql.sql'];
});

check('max_allowed_packet', static function () use ($app): array {
    $row = $app->db()->fetchOne("SHOW VARIABLES LIKE 'max_allowed_packet'");
    $bytes = (int) ($row['Value'] ?? 0);
    $mb = round($bytes / 1048576, 1);

    // Сырой hex-поток этикетки 4x6 при 300 dpi — около 1 МБ; 16 МБ дают запас.
    return $bytes >= 16 * 1048576
        ? ['ok', "{$mb} МБ"]
        : ['warn', "{$mb} МБ — поднимите до 64M, крупные этикетки могут не записаться"];
});

printf("\nИтого: %d ок, %d предупреждений, %d ошибок\n", $ok, $warn, $fail);
exit($fail > 0 ? 1 : 0);
