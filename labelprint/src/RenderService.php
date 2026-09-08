<?php
declare(strict_types=1);

namespace LabelPrint;

use LabelPrint\Model\Job;
use LabelPrint\Model\PrinterProfile;
use LabelPrint\Pdf\Bitmap;
use LabelPrint\Pdf\PdfInfo;
use LabelPrint\Pdf\RasterizerFactory;
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
        private readonly RasterizerFactory $rasterizers,
        private readonly ZplLabelBuilder $builder,
        private readonly LabelRepository $labels,
        private readonly PdfFileRepository $files,
        private readonly Log $log,
        private readonly ?string $pdfinfoBinary = null,
    ) {
    }

    /**
     * @param  callable(int):void|null $heartbeat вызывается после каждой записанной страницы;
     *                                            воркер продлевает в нём аренду задания
     * @return array{pages:int,bytes:int,cached:bool,ms:int}
     */
    public function renderJob(Job $job, PrinterProfile $profile, ?callable $heartbeat = null): array
    {
        $absolute = $this->resolve($job->path);
        $started = hrtime(true);

        // Кэш контент-адресуемый: тот же PDF под тем же профилем уже посчитан.
        //
        // Засчитывается ТОЛЬКО полный комплект страниц. Проверять «страниц больше нуля»
        // нельзя: если воркер умер на четвёртой странице десятистраничного файла,
        // в базе останутся три этикетки, следующая попытка сочла бы их готовым
        // результатом и пометила задание выполненным. Потребитель напечатал бы
        // три этикетки из десяти, и никто бы об этом не узнал.
        // page_count проставляется только после успешной записи ВСЕХ страниц.
        $cachedPages = $this->labels->pageCount($job->sha256, $profile);
        if ($cachedPages > 0 && $job->pdfPageCount !== null && $cachedPages === $job->pdfPageCount) {
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

        if ($cachedPages > 0) {
            $this->log->warning('в кэше неполный комплект страниц, файл рендерится заново', [
                'path' => $job->path,
                'profile' => $profile->code,
                'в_кэше' => $cachedPages,
                'ожидалось' => $job->pdfPageCount,
            ]);
        }

        return $this->render($absolute, $job->path, $job->pdfFileId, $job->sha256, $profile, $started, $heartbeat);
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

        $cachedPages = $this->labels->pageCount($sha256, $profile);
        $knownPages = $this->files->findById($file['id'])['page_count'] ?? null;

        if (!$force && $cachedPages > 0 && $knownPages !== null && $cachedPages === (int) $knownPages) {
            return [
                'pages' => $cachedPages,
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
        $info = $this->readInfo($absolutePdfPath);
        $rotation = self::rotationFor($info, $profile);
        $dpi = $this->resolveDpi($info, $profile, $rotation);

        $pages = [];
        foreach ($this->rasterizers->for($profile)->rasterize($absolutePdfPath, $dpi, $profile->threshold) as $raster) {
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
        ?callable $heartbeat = null,
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

            // Многостраничный PDF может рендериться дольше аренды. Без продления
            // задание заберёт другой воркер, и одна и та же пачка уедет в базу дважды.
            if ($heartbeat !== null) {
                $heartbeat($pageNo);
            }
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

    /**
     * Разрешение, с которым вызывать Ghostscript.
     *
     * В режиме fit страница вписывается в этикетку изменением разрешения, а не
     * ключом -dPDFFitPage: последний молча доворачивает страницу на 90 градусов,
     * если так она «лучше вписывается», и вместе с нашим поворотом даёт разворот
     * на 180. Считая масштаб сами, мы полностью контролируем геометрию.
     */
    private function resolveDpi(?PdfInfo $info, PrinterProfile $profile, int $rotation): float
    {
        if ($profile->fit !== PrinterProfile::FIT_FIT) {
            return (float) $profile->dpi;
        }

        // Холст ДО поворота: при повороте на 90/270 стороны меняются местами.
        [$canvasWidth, $canvasHeight] = in_array($rotation, [90, 270], true)
            ? [$profile->heightDots(), $profile->widthDots()]
            : [$profile->widthDots(), $profile->heightDots()];

        if ($info === null || $info->widthPt === null || $info->heightPt === null
            || $info->widthPt <= 0 || $info->heightPt <= 0) {
            // Размер страницы не удалось прочитать: рендерим на разрешении принтера,
            // а разницу добираем полями или обрезкой в prepare().
            $this->log->debug('размер страницы неизвестен, вписывание пропущено');

            return (float) $profile->dpi;
        }

        // Разрешение, при котором страница займёт холст целиком, без искажения пропорций.
        return min(
            $canvasWidth * 72 / $info->widthPt,
            $canvasHeight * 72 / $info->heightPt,
        );
    }

    /** Поворот, инверсия и приведение растра к точному размеру этикетки. */
    private function prepare(Bitmap $raster, PrinterProfile $profile, int $rotation): Bitmap
    {
        $bitmap = $rotation !== 0 ? $raster->rotate($rotation) : $raster;

        if ($profile->invert) {
            $bitmap = $bitmap->invert();
        }

        if ($profile->fit !== PrinterProfile::FIT_FIT) {
            return $bitmap;
        }

        $targetWidth = $profile->widthDots();
        $targetHeight = $profile->heightDots();

        if ($bitmap->width === $targetWidth && $bitmap->height === $targetHeight) {
            return $bitmap;
        }

        // Центрируем остаток. По горизонтали смещение округляется вниз до кратного 8:
        // так работает быстрый путь обрезки, копирующий строки целыми байтами.
        // Цена — сдвиг не больше 7 точек, это 0,9 мм при 203 dpi.
        $offsetX = intdiv(intdiv($targetWidth - $bitmap->width, 2), 8) * 8;
        $offsetY = intdiv($targetHeight - $bitmap->height, 2);

        return $bitmap->placeOnCanvas($targetWidth, $targetHeight, $offsetX, $offsetY);
    }

    /**
     * Насколько повернуть растр. Помимо явной настройки профиля учитывается
     * несовпадение ориентации страницы и этикетки — этикетки часто приходят «лёжа».
     *
     * Метод статический и без побочных эффектов, чтобы решение о повороте можно
     * было проверить тестами, не запуская Ghostscript.
     */
    public static function rotationFor(?PdfInfo $info, PrinterProfile $profile): int
    {
        $rotation = $profile->rotate;

        if (!$profile->autoRotate || $profile->fit !== PrinterProfile::FIT_FIT || $info === null) {
            return $rotation;
        }

        if ($info->widthPt === null || $info->heightPt === null) {
            return $rotation;
        }

        // Квадратные страницы поворачивать не нужно.
        if (abs($info->widthPt - $info->heightPt) < 1.0) {
            return $rotation;
        }

        if ($info->isLandscape() === $profile->isLandscape()) {
            return $rotation;
        }

        // Направление доворота выбирается по причине несовпадения.
        //
        // Если страница альбомная ИЗ-ЗА /Rotate, значит содержимое было свёрстано
        // портретным, а producer попросил показывать его повёрнутым. Тогда правильно
        // ОТМЕНИТЬ этот поворот, а не добавить ещё 90 градусов: иначе этикетка
        // приезжает вверх ногами. Проверено на PDF с /Rotate 90.
        //
        // Если же страница альбомная сама по себе (такой MediaBox), направление
        // выбрать не из чего — берём поворот по часовой. Когда конкретный поставщик
        // этикеток кладёт их наоборот, это лечится параметром rotate: 180 в профиле.
        $step = in_array($info->rotate, [90, 270], true)
            ? (360 - $info->rotate) % 360
            : 90;

        return ($rotation + $step) % 360;
    }

    /** Метаданные страницы; при неудаче возвращает null — рендеринг это переживёт. */
    private function readInfo(string $absolutePdfPath): ?PdfInfo
    {
        try {
            return PdfInfo::read($absolutePdfPath, $this->pdfinfoBinary);
        } catch (\Throwable $e) {
            $this->log->debug('не удалось прочитать метаданные PDF', ['error' => $e->getMessage()]);

            return null;
        }
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
