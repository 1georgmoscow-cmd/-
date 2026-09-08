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

$options = getopt('', ['profile:', 'stdout', 'preview:', 'force', 'config:', 'help']);
$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn(string $arg): bool => !str_starts_with($arg, '--'),
));

if (isset($options['help']) || $positional === []) {
    echo <<<TXT
    Рендеринг одного PDF в ZPL.

      php bin/render.php ФАЙЛ.pdf [опции]

      --profile=КОД     профиль принтера (по умолчанию default_profile из конфига)
      --stdout          вывести ZPL в stdout, не записывая в базу
      --preview=ФАЙЛ    сохранить растр в PBM, чтобы посмотреть глазами
      --force           перерендерить, даже если результат уже в кэше
      --config=ПУТЬ     альтернативный config.php

    TXT;
    exit(isset($options['help']) ? 0 : 1);
}

$app = App::boot(isset($options['config']) ? (string) $options['config'] : null);
$relative = $positional[0];
$profile = $app->profiles()->get(
    isset($options['profile']) ? (string) $options['profile'] : $app->config->string('default_profile'),
);

$pdfDir = rtrim($app->config->string('pdf_dir'), '/');
$absolute = realpath($pdfDir . '/' . $relative);

if ($absolute === false || !str_starts_with($absolute, $pdfDir . '/')) {
    fwrite(STDERR, "Файл не найден внутри {$pdfDir}: {$relative}\n");
    exit(1);
}

try {
    // Режим stdout/preview базу не трогает: удобно проверять профиль до развёртывания.
    if (isset($options['stdout']) || isset($options['preview'])) {
        $pages = $app->rasterizer()->rasterize($absolute, $profile, in_array($profile->rotate, [90, 270], true));
        $builder = new ZplLabelBuilder();

        foreach ($pages as $i => $raster) {
            $bitmap = $profile->rotate !== 0 ? $raster->rotate($profile->rotate) : $raster;
            if ($profile->invert) {
                $bitmap = $bitmap->invert();
            }

            if (isset($options['preview'])) {
                $name = count($pages) > 1
                    ? preg_replace('/(\.\w+)?$/', '-' . ($i + 1) . '$1', (string) $options['preview'], 1)
                    : (string) $options['preview'];
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

            if (isset($options['stdout'])) {
                echo $builder->build($bitmap, $profile);
            }
        }

        exit(0);
    }

    $result = $app->renderer()->renderFile($relative, $profile, isset($options['force']));

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
