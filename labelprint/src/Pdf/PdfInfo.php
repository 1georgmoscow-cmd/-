<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

use LabelPrint\Support\Process;

/**
 * Лёгкий разбор метаданных PDF: число страниц и размер страницы.
 *
 * Для рендеринга это не критично — Ghostscript сам разберётся с размером,
 * а число страниц мы узнаём по факту из его вывода. Метаданные нужны, чтобы
 * записать их в базу и чтобы сканер мог заранее отсеять битые файлы.
 *
 * Сначала пробуем pdfinfo из poppler-utils (быстро и надёжно), при его отсутствии
 * разбираем файл сами. Свой разбор понимает и сжатые потоки объектов,
 * которыми пользуются современные генераторы PDF.
 */
final class PdfInfo
{
    private function __construct(
        public readonly int $pageCount,
        /** Ширина первой страницы в пунктах (1/72 дюйма), с учётом /Rotate. */
        public readonly ?float $widthPt,
        /** Высота первой страницы в пунктах, с учётом /Rotate. */
        public readonly ?float $heightPt,
        public readonly bool $encrypted,
        /** Значение /Rotate: 0, 90, 180 или 270. */
        public readonly int $rotate = 0,
    ) {
    }

    /**
     * Собирает объект, применяя /Rotate к размерам страницы.
     *
     * Все растеризаторы (gs, mutool, pdftoppm) учитывают /Rotate сами и выдают уже
     * повёрнутый растр, а pdfinfo показывает исходный MediaBox и отдельную строку
     * «Page rot». Если этого не учесть, страница 283x425 с /Rotate 90 считается
     * портретной, хотя рендерится альбомной, — и авто-поворот срабатывает наоборот.
     */
    private static function make(int $pages, ?float $w, ?float $h, bool $encrypted, int $rotate): self
    {
        $rotate = (($rotate % 360) + 360) % 360;

        if (in_array($rotate, [90, 270], true) && $w !== null && $h !== null) {
            [$w, $h] = [$h, $w];
        }

        return new self($pages, $w, $h, $encrypted, $rotate);
    }

    public static function read(string $pdfPath, ?string $pdfinfoBinary = null, int $timeoutSeconds = 10): self
    {
        if (!is_file($pdfPath)) {
            throw new \RuntimeException("PDF не найден: {$pdfPath}");
        }

        if ($pdfinfoBinary !== null && is_executable($pdfinfoBinary)) {
            $info = self::viaPdfinfo($pdfPath, $pdfinfoBinary, $timeoutSeconds);
            if ($info !== null) {
                return $info;
            }
        }

        return self::viaParsing($pdfPath);
    }

    /** Размер страницы в точках принтера при заданном разрешении. */
    public function dotsAt(int $dpi): ?array
    {
        if ($this->widthPt === null || $this->heightPt === null) {
            return null;
        }

        return [
            (int) round($this->widthPt / 72 * $dpi),
            (int) round($this->heightPt / 72 * $dpi),
        ];
    }

    /** Страница шире, чем выше — обычно значит, что этикетку надо повернуть. */
    public function isLandscape(): bool
    {
        return $this->widthPt !== null && $this->heightPt !== null && $this->widthPt > $this->heightPt;
    }

    private static function viaPdfinfo(string $pdfPath, string $binary, int $timeoutSeconds): ?self
    {
        try {
            // Именно через Process: pdfinfo на битом PDF сыплет сотнями килобайт
            // «Syntax Error» в stderr, и наивное чтение сначала stdout, потом stderr
            // намертво заклинивает пару процессов, причём SIGTERM это не разрывает.
            // -box добавляет строки MediaBox/CropBox/TrimBox — они точнее «Page size».
            $result = Process::run([$binary, '-box', $pdfPath], $timeoutSeconds);
        } catch (\Throwable) {
            return null;   // не запустился или завис — разберём файл сами
        }

        $out = $result['stdout'];
        $err = $result['stderr'];

        if ($result['code'] !== 0) {
            // Зашифрованный PDF pdfinfo отвергает — это полезный диагноз, а не сбой.
            if (stripos($err, 'encrypted') !== false) {
                return self::make(0, null, null, true, 0);
            }

            return null;
        }

        $pages = 0;
        $width = null;
        $height = null;

        if (preg_match('/^Pages:\s+(\d+)/mi', $out, $m) === 1) {
            $pages = (int) $m[1];
        }

        // MediaBox из «pdfinfo -box» точнее, чем «Page size»: последний показывает CropBox,
        // а Ghostscript по умолчанию рендерит именно MediaBox.
        if (preg_match('/^MediaBox:\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)/mi', $out, $m) === 1) {
            $width = round(abs((float) $m[3] - (float) $m[1]), 2);
            $height = round(abs((float) $m[4] - (float) $m[2]), 2);
        } elseif (preg_match('/^Page size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/mi', $out, $m) === 1) {
            $width = (float) $m[1];
            $height = (float) $m[2];
        }

        $rotate = preg_match('/^Page rot:\s+(-?\d+)/mi', $out, $m) === 1 ? (int) $m[1] : 0;
        $encrypted = preg_match('/^Encrypted:\s+yes/mi', $out) === 1;

        return $pages > 0 ? self::make($pages, $width, $height, $encrypted, $rotate) : null;
    }

    /** Разбор без внешних утилит: считаем объекты страниц и ищем первый MediaBox. */
    private static function viaParsing(string $pdfPath): self
    {
        $raw = (string) file_get_contents($pdfPath);
        if (!str_starts_with($raw, '%PDF-')) {
            throw new \RuntimeException('Файл не похож на PDF: отсутствует сигнатура %PDF-');
        }

        $encrypted = preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $raw) === 1;

        // Текст всех сжатых потоков — там прячутся объекты страниц современных PDF.
        $searchable = $raw . self::inflateStreams($raw);

        // /Count в корневом узле дерева страниц — самый надёжный источник.
        $pages = 0;
        if (preg_match_all('/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)/s', $searchable, $m) > 0) {
            $pages = max(array_map('intval', $m[1]));
        }

        if ($pages === 0) {
            // Запасной вариант: считаем объекты страниц. \b не даёт спутать /Page и /Pages.
            $pages = preg_match_all('/\/Type\s*\/Page\b(?!s)/', $searchable);
        }

        $width = null;
        $height = null;
        if (preg_match('/\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/', $searchable, $m) === 1) {
            $width = round(abs((float) $m[3] - (float) $m[1]), 2);
            $height = round(abs((float) $m[4] - (float) $m[2]), 2);
        }

        $rotate = preg_match('/\/Rotate\s+(-?\d+)/', $searchable, $m) === 1 ? (int) $m[1] : 0;

        return self::make(max(0, $pages), $width, $height, $encrypted, $rotate);
    }

    /**
     * Распаковывает FlateDecode-потоки: без этого объекты страниц не видны.
     *
     * Ограничения обязательны с обеих сторон. Сжатый поток в полтора мегабайта
     * может развернуться в полтора гигабайта нулей — и это не теоретический
     * случай, а тривиально собираемая «zip-бомба». Без предела на РАСПАКОВАННЫЙ
     * размер процесс падает с фатальной ошибкой нехватки памяти, которую нельзя
     * перехватить, то есть воркер умирает целиком.
     */
    private static function inflateStreams(string $raw): string
    {
        /** Предел на один распакованный поток. */
        $perStream = 8 * 1024 * 1024;
        /** Общий предел: чтобы найти /Type /Pages, /Count и первый /MediaBox, хватает с запасом. */
        $totalBudget = 16 * 1024 * 1024;

        $out = '';
        $offset = 0;
        $budget = 64;   // не разворачиваем весь файл: нам хватит первых потоков

        while ($budget-- > 0 && ($start = strpos($raw, 'stream', $offset)) !== false) {
            $dataStart = $start + 6;
            // После ключевого слова stream идёт CRLF или LF.
            if (substr($raw, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
            } elseif (($raw[$dataStart] ?? '') === "\n") {
                $dataStart += 1;
            }

            $end = strpos($raw, 'endstream', $dataStart);
            if ($end === false) {
                break;
            }

            $chunk = substr($raw, $dataStart, $end - $dataStart);
            $offset = $end + 9;

            if ($chunk === '' || strlen($chunk) > 4_000_000) {
                continue;
            }

            // Второй аргумент — жёсткий предел распакованного размера.
            $inflated = @gzuncompress($chunk, $perStream);
            if ($inflated === false) {
                $inflated = @gzinflate($chunk, $perStream);
            }
            if (is_string($inflated) && $inflated !== '') {
                $out .= "\n" . $inflated;
            }

            if (strlen($out) >= $totalBudget) {
                break;
            }
        }

        return $out;
    }
}
