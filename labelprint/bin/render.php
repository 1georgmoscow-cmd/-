#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Рендеринг одного файла вручную — для отладки и проверки профиля.
 *
 *   php bin/render.php label.pdf                       # отрендерить и записать в базу
 *   php bin/render.php label.pdf --profile=zebra_203_58x40
 *   php bin/render.php label.pdf --stdout > label.zpl   # вывести ZPL, не трогая базу
 *   php bin/render.php label.pdf --preview=out.pbm      # сохранить растр для глазами-проверки
 *
 * Путь указывается относительно pdf_dir из конфига.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/render.php запускается только из командной строки\n");
}

require __DIR__ . '/../src/bootstrap.php';

use LabelPrint\App;
use LabelPrint\Render\ZplLabelBuilder;
use LabelPrint\Support\Args;

try {
    $options = Args::parse($argv, ['stdout', 'force', 'codes', 'help'], ['profile', 'preview', 'config']);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($options->has('help') || $options->first() === null) {
    echo <<<TXT
    Рендеринг одного PDF в ZPL.

      php bin/render.php ФАЙЛ.pdf [опции]

      --profile=КОД     профиль принтера (по умолчанию default_profile из конфига)
      --stdout          вывести ZPL в stdout, не записывая в базу
      --preview=ФАЙЛ    сохранить растр в PBM, чтобы посмотреть глазами
      --force           перерендерить, даже если результат уже в кэше
      --codes           распознать коды на растре и показать их
      --config=ПУТЬ     альтернативный config.php

    TXT;
    exit($options->has('help') ? 0 : 1);
}

$app = App::boot($options->value('config'));
$relative = (string) $options->first();
$profile = $app->profiles()->get($options->value('profile') ?? $app->config->string('default_profile'));

$pdfDir = rtrim($app->config->string('pdf_dir'), '/');
$absolute = realpath($pdfDir . '/' . $relative);

if ($absolute === false || !str_starts_with($absolute, $pdfDir . '/')) {
    fwrite(STDERR, "Файл не найден внутри {$pdfDir}: {$relative}\n");
    exit(1);
}

try {
    // Режим stdout/preview базу не трогает: удобно проверять профиль до развёртывания.
    // Путь тот же самый, что у воркера, включая авто-поворот и подгонку под этикетку.
    if ($options->has('stdout') || $options->has('preview') || $options->has('codes')) {
        $pages = $app->renderer()->renderPages($absolute, $profile);
        $builder = new ZplLabelBuilder($app->config->bool('render.verify_roundtrip', true));

        foreach ($pages as $i => $bitmap) {
            if ($options->has('preview')) {
                $target = (string) $options->value('preview');
                $name = count($pages) > 1
                    ? preg_replace('/(\.[^.\/]+)$/', '-' . ($i + 1) . '$1', $target, 1) ?? $target
                    : $target;
                file_put_contents((string) $name, $bitmap->toPbm());
                fwrite(STDERR, sprintf(
                    "страница %d: %dx%d точек, чёрного %.2f%% -> %s\n",
                    $i + 1,
                    $bitmap->width,
                    $bitmap->height,
                    $bitmap->inkCoverage() * 100,
                    $name,
                ));
            }

            if ($options->has('codes')) {
                $reader = $app->codeReader();
                $started = hrtime(true);
                $found = $reader->read($bitmap, $relative);
                fprintf(
                    STDERR,
                    "страница %d: кодов %d за %d мс\n",
                    $i + 1,
                    count($found),
                    (int) ((hrtime(true) - $started) / 1_000_000),
                );
                foreach ($found as $code) {
                    fprintf(
                        STDERR,
                        "  %-12s %-6s качество %-4s %s\n",
                        $code->symbology,
                        $code->reader,
                        $code->quality ?? '-',
                        $code->display(70),
                    );
                }
                if ($found === []) {
                    fwrite(STDERR, "  на этой странице кодов нет — сканер её не подтвердит\n");
                }
            }

            if ($options->has('stdout')) {
                echo $builder->build($bitmap, $profile);
            }
        }

        exit(0);
    }

    $result = $app->renderer()->renderFile($relative, $profile, $options->has('force'));

    printf(
        "%s: %d стр., %s байт ZPL, %d мс%s\n",
        $relative,
        $result['pages'],
        number_format($result['bytes']),
        $result['ms'],
        $result['cached'] ? ' (из кэша)' : '',
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
