<?php
declare(strict_types=1);

namespace LabelPrint;

use LabelPrint\Model\Job;
use LabelPrint\Model\PrinterProfile;
use LabelPrint\Pdf\Bitmap;
use LabelPrint\Pdf\PdfInfo;
use LabelPrint\Pdf\Rasterizer;
use LabelPrint\Render\ZplLabelBuilder;
use LabelPrint\Storage\LabelRepository;
use LabelPrint\Storage\PdfFileRepository;
use LabelPrint\Support\Log;

/**
 * Рендеринг одного задания: PDF -> растр -> ZPL -> MySQL.
 *
 * Всё, что делает воркер полезного, происходит здесь. Класс намеренно не знает
 * ни про очередь, ни про сигналы, ни про systemd — его можно вызвать из CLI
 * для одного файла и точно так же из воркера.
 */
final class RenderService
{
    public function __construct(
        private readonly string $pdfDir,
        private readonly Rasterizer $rasterizer,
        private readonly ZplLabelBuilder $builder,
        private readonly LabelRepository $labels,
        private readonly PdfFileRepository $files,
        private readonly Log $log,
        private readonly ?string $pdfinfoBinary = null,
    ) {
    }

    /**
     * @return array{pages:int,bytes:int,cached:bool,ms:int}
     */
    public function renderJob(Job $job, PrinterProfile $profile): array
    {
        $absolute = $this->resolve($job->path);
        $started = hrtime(true);

        // Кэш контент-адресуемый: тот же PDF под тем же профилем уже посчитан.
        $cachedPages = $this->labels->pageCount($job->sha256, $profile);
        if ($cachedPages > 0) {
            $this->log->debug('этикетки уже в кэше, рендеринг пропущен', [
                'path' => $job->path,
                'profile' => $profile->code,
                'pages' => $cachedPages,
            ]);

            return [
                'pages' => $cachedPages,
                'bytes' => 0,
                'cached' => true,
                'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ];
        }

        return $this->render($absolute, $job->path, $job->pdfFileId, $job->sha256, $profile, $started);
    }

    /**
     * Рендерит конкретный файл, минуя очередь — используется bin/render.php.
     *
     * @return array{pages:int,bytes:int,cached:bool,ms:int}
     */
    public function renderFile(string $relativePath, PrinterProfile $profile, bool $force = false): array
    {
        $absolute = $this->resolve($relativePath);
        $sha256 = hash_file('sha256', $absolute);
        if ($sha256 === false) {
            throw new \RuntimeException("Не удалось посчитать хэш файла: {$absolute}");
        }

        $stat = stat($absolute);
        $file = $this->files->upsert($relativePath, $sha256, (int) $stat['size'], (int) $stat['mtime']);

        $started = hrtime(true);

        if (!$force && $this->labels->pageCount($sha256, $profile) > 0) {
            return [
                'pages' => $this->labels->pageCount($sha256, $profile),
                'bytes' => 0,
                'cached' => true,
                'ms' => 0,
            ];
        }

        return $this->render($absolute, $relativePath, $file['id'], $sha256, $profile, $started);
    }

    /**
     * Растеризует файл и приводит страницы к готовому для ZPL виду: поворот,
     * инверсия, точный размер этикетки.
     *
     * Вынесено в публичный метод, чтобы предпросмотр в bin/render.php проходил
     * ровно тот же путь, что и воркер. Иначе предпросмотр показывает не то,
     * что окажется в базе, и отладка профиля превращается в гадание.
     *
     * @return list<Bitmap>
     */
    public function renderPages(string $absolutePdfPath, PrinterProfile $profile): array
    {
        $rotation = $this->resolveRotation($absolutePdfPath, $profile);
        // При повороте на 90/270 холст надо рендерить «лёжа», иначе после поворота
        // растр не совпадёт с размером этикетки.
        $swapGeometry = in_array($rotation, [90, 270], true);

        $pages = [];
        foreach ($this->rasterizer->rasterize($absolutePdfPath, $profile, $swapGeometry) as $raster) {
            $pages[] = $this->prepare($raster, $profile, $rotation);
        }

        return $pages;
    }

    /** Абсолютный путь к файлу внутри каталога с PDF. */
    public function absolutePath(string $relativePath): string
    {
        return $this->resolve($relativePath);
    }

    /**
     * @return array{pages:int,bytes:int,cached:bool,ms:int}
     */
    private function render(
        string $absolute,
        string $relativePath,
        int $pdfFileId,
        string $sha256,
        PrinterProfile $profile,
        int $started,
    ): array {
        $pages = $this->renderPages($absolute, $profile);

        $totalBytes = 0;
        $pageNo = 0;

        foreach ($pages as $bitmap) {
            $pageNo++;
            $zpl = $this->builder->build($bitmap, $profile);
            $coverage = $bitmap->inkCoverage();

            $this->warnIfSuspicious($relativePath, $pageNo, $coverage);

            $this->labels->store(
                pdfFileId: $pdfFileId,
                pdfSha256: $sha256,
                profile: $profile,
                pageNo: $pageNo,
                zpl: $zpl,
                widthDots: $bitmap->width,
                heightDots: $bitmap->height,
                inkCoverage: $coverage,
                renderMs: (int) ((hrtime(true) - $started) / 1_000_000),
            );

            $totalBytes += strlen($zpl);
        }

        // Если PDF стал короче, чем при прошлом рендере, лишние страницы надо убрать,
        // иначе на печать уедет хвост от старой версии файла.
        $this->labels->deletePagesAbove($sha256, $profile, $pageNo);
        $this->files->setPageCount($pdfFileId, $pageNo);

        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->log->info('этикетки отрендерены', [
            'path' => $relativePath,
            'profile' => $profile->code,
            'pages' => $pageNo,
            'zpl_bytes' => $totalBytes,
            'ms' => $ms,
        ]);

        return ['pages' => $pageNo, 'bytes' => $totalBytes, 'cached' => false, 'ms' => $ms];
    }

    /** Поворот, инверсия и приведение растра к точному размеру этикетки. */
    private function prepare(Bitmap $raster, PrinterProfile $profile, int $rotation): Bitmap
    {
        $bitmap = $rotation !== 0 ? $raster->rotate($rotation) : $raster;

        if ($profile->invert) {
            $bitmap = $bitmap->invert();
        }

        if ($profile->fit === PrinterProfile::FIT_FIT) {
            $targetWidth = $profile->widthDots();
            $targetHeight = $profile->heightDots();

            // После поворота размеры обязаны совпасть с этикеткой; если из-за
            // округления разошлись на пару точек — дополняем белым, а не масштабируем.
            if ($bitmap->width !== $targetWidth || $bitmap->height !== $targetHeight) {
                $bitmap = $bitmap->placeOnCanvas($targetWidth, $targetHeight);
            }
        }

        return $bitmap;
    }

    /**
     * Насколько повернуть растр. Помимо явной настройки профиля учитывается
     * несовпадение ориентации страницы и этикетки — этикетки часто приходят «лёжа».
     */
    private function resolveRotation(string $absolute, PrinterProfile $profile): int
    {
        $rotation = $profile->rotate;

        if (!$profile->autoRotate || $profile->fit !== PrinterProfile::FIT_FIT) {
            return $rotation;
        }

        try {
            $info = PdfInfo::read($absolute, $this->pdfinfoBinary);
        } catch (\Throwable $e) {
            $this->log->debug('не удалось определить ориентацию страницы', ['error' => $e->getMessage()]);

            return $rotation;
        }

        if ($info->widthPt === null || $info->heightPt === null) {
            return $rotation;
        }

        // Квадратные страницы поворачивать не нужно.
        if (abs($info->widthPt - $info->heightPt) < 1.0) {
            return $rotation;
        }

        if ($info->isLandscape() !== $profile->isLandscape()) {
            $rotation = ($rotation + 90) % 360;
        }

        return $rotation;
    }

    private function warnIfSuspicious(string $path, int $pageNo, float $coverage): void
    {
        if ($coverage > 0.9) {
            $this->log->warning('почти вся этикетка чёрная — вероятна инверсия или неверный порог', [
                'path' => $path,
                'page' => $pageNo,
                'ink' => round($coverage, 3),
            ]);
        } elseif ($coverage < 0.0005) {
            $this->log->warning('этикетка практически пустая', [
                'path' => $path,
                'page' => $pageNo,
                'ink' => round($coverage, 5),
            ]);
        }
    }

    /** Защита от выхода за пределы каталога: путь из базы не должен уводить наружу. */
    private function resolve(string $relativePath): string
    {
        $base = realpath($this->pdfDir);
        if ($base === false) {
            throw new \RuntimeException("Каталог с PDF не найден: {$this->pdfDir}");
        }

        $candidate = realpath($base . '/' . $relativePath);
        if ($candidate === false) {
            throw new \RuntimeException("PDF не найден: {$relativePath}");
        }

        if (!str_starts_with($candidate, $base . '/')) {
            throw new \RuntimeException("Путь выходит за пределы каталога с PDF: {$relativePath}");
        }

        return $candidate;
    }
}
