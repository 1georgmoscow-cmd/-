<?php
declare(strict_types=1);

use LabelPrint\Model\PrinterProfile;
use LabelPrint\Pdf\PdfInfo;
use LabelPrint\RenderService;

/**
 * Выбор поворота — самое частое место ошибок: этикетка приезжает боком
 * или вверх ногами, а тест этого не видит, потому что растр «непустой».
 * Здесь решение проверяется без Ghostscript.
 */

/** Собирает PDF в памяти с заданным MediaBox и /Rotate. */
function pdfWithBox(float $widthPt, float $heightPt, int $rotate = 0): string
{
    $dir = sys_get_temp_dir() . '/labelprint-tests';
    @mkdir($dir, 0777, true);
    $path = sprintf('%s/box-%g-%g-%d.pdf', $dir, $widthPt, $heightPt, $rotate);

    $page = sprintf(
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s]%s /Contents 4 0 R >>',
        $widthPt,
        $heightPt,
        $rotate !== 0 ? " /Rotate {$rotate}" : '',
    );
    $content = "0 0 10 10 re f\n";

    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        $page,
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

    file_put_contents($path, $pdf);

    return $path;
}

$portraitLabel = PrinterProfile::fromArray('p', ['width_mm' => 100, 'height_mm' => 150]);
$landscapeLabel = PrinterProfile::fromArray('l', ['width_mm' => 150, 'height_mm' => 100]);

return [
    'совпадающая ориентация не поворачивается' => static function () use ($portraitLabel): void {
        $info = PdfInfo::read(pdfWithBox(283.46, 425.2));
        assertSame(0, RenderService::rotationFor($info, $portraitLabel));
    },

    'альбомная страница на портретной этикетке доворачивается' => static function () use ($portraitLabel): void {
        $info = PdfInfo::read(pdfWithBox(425.2, 283.46));
        assertSame(90, RenderService::rotationFor($info, $portraitLabel), 'по часовой стрелке');
    },

    'портретная страница на альбомной этикетке доворачивается' => static function () use ($landscapeLabel): void {
        $info = PdfInfo::read(pdfWithBox(283.46, 425.2));
        assertSame(90, RenderService::rotationFor($info, $landscapeLabel));
    },

    'страница альбомная из-за /Rotate 90 — поворот отменяется, а не удваивается' => static function () use ($portraitLabel): void {
        // MediaBox портретный, но /Rotate 90 делает страницу альбомной при рендеринге.
        // Содержимое свёрстано портретным, поэтому правильный ход — отменить /Rotate
        // поворотом на 270, а не добавить ещё 90 (иначе этикетка вверх ногами).
        $info = PdfInfo::read(pdfWithBox(283.46, 425.2, 90));

        assertTrue($info->isLandscape(), 'с учётом /Rotate страница альбомная');
        assertSame(270, RenderService::rotationFor($info, $portraitLabel));
    },

    '/Rotate 270 отменяется поворотом на 90' => static function () use ($portraitLabel): void {
        $info = PdfInfo::read(pdfWithBox(283.46, 425.2, 270));
        assertSame(90, RenderService::rotationFor($info, $portraitLabel));
    },

    '/Rotate 180 ориентацию не меняет' => static function () use ($portraitLabel): void {
        $info = PdfInfo::read(pdfWithBox(283.46, 425.2, 180));
        assertTrue(!$info->isLandscape(), '180 градусов ориентацию не переворачивает');
        assertSame(0, RenderService::rotationFor($info, $portraitLabel));
    },

    'явный поворот складывается с автоматическим' => static function (): void {
        $profile = PrinterProfile::fromArray('r', [
            'width_mm' => 100, 'height_mm' => 150, 'rotate' => 180,
        ]);
        $info = PdfInfo::read(pdfWithBox(425.2, 283.46));

        assertSame(270, RenderService::rotationFor($info, $profile), '180 + 90');
    },

    'авто-поворот можно выключить' => static function (): void {
        $profile = PrinterProfile::fromArray('n', [
            'width_mm' => 100, 'height_mm' => 150, 'auto_rotate' => false, 'rotate' => 90,
        ]);
        $info = PdfInfo::read(pdfWithBox(425.2, 283.46));

        assertSame(90, RenderService::rotationFor($info, $profile), 'берётся только значение из профиля');
    },

    'квадратная страница не поворачивается' => static function () use ($portraitLabel): void {
        $info = PdfInfo::read(pdfWithBox(300.0, 300.0));
        assertSame(0, RenderService::rotationFor($info, $portraitLabel));
    },

    'в режиме native авто-поворот не применяется' => static function (): void {
        $profile = PrinterProfile::fromArray('nat', ['fit' => 'native']);
        $info = PdfInfo::read(pdfWithBox(425.2, 283.46));

        assertSame(0, RenderService::rotationFor($info, $profile));
    },

    'без метаданных берётся значение из профиля' => static function (): void {
        $profile = PrinterProfile::fromArray('x', ['width_mm' => 100, 'height_mm' => 150, 'rotate' => 180]);
        assertSame(180, RenderService::rotationFor(null, $profile));
    },
];
