<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

/** Растеризатор PDF: страницы -> монохромные растры. */
interface RasterizerInterface
{
    /**
     * @param  float $dpi       разрешение; дробные значения используются для вписывания
     * @param  int   $threshold порог бинаризации 1..254
     * @return list<Bitmap>     по одному растру на страницу, в порядке страниц
     */
    public function rasterize(string $pdfPath, float $dpi, int $threshold = 128): array;

    /** Имя движка для логов и диагностики. */
    public function name(): string;

    public function isAvailable(): bool;
}
