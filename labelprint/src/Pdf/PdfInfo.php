<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

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
        /** Ширина первой страницы в пунктах (1/72 дюйма). */
        public readonly ?float $widthPt,
        /** Высота первой страницы в пунктах. */
        public readonly ?float $heightPt,
        public readonly bool $encrypted,
    ) {
    }

    public static function read(string $pdfPath, ?string $pdfinfoBinary = null): self
    {
        if (!is_file($pdfPath)) {
            throw new \RuntimeException("PDF не найден: {$pdfPath}");
        }

        if ($pdfinfoBinary !== null && is_executable($pdfinfoBinary)) {
            $info = self::viaPdfinfo($pdfPath, $pdfinfoBinary);
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

    private static function viaPdfinfo(string $pdfPath, string $binary): ?self
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$binary, $pdfPath], $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            // Зашифрованный PDF pdfinfo отвергает — это полезный диагноз, а не сбой.
            if (stripos($err, 'encrypted') !== false) {
                return new self(0, null, null, true);
            }

            return null;
        }

        $pages = 0;
        $width = null;
        $height = null;

        if (preg_match('/^Pages:\s+(\d+)/mi', $out, $m) === 1) {
            $pages = (int) $m[1];
        }
        if (preg_match('/^Page size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/mi', $out, $m) === 1) {
            $width = (float) $m[1];
            $height = (float) $m[2];
        }

        $encrypted = preg_match('/^Encrypted:\s+yes/mi', $out) === 1;

        return $pages > 0 ? new self($pages, $width, $height, $encrypted) : null;
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

        return new self(max(0, $pages), $width, $height, $encrypted);
    }

    /** Распаковывает все FlateDecode-потоки: без этого объекты страниц не видны. */
    private static function inflateStreams(string $raw): string
    {
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

            $inflated = @gzuncompress($chunk);
            if ($inflated === false) {
                $inflated = @gzinflate($chunk);
            }
            if (is_string($inflated) && $inflated !== '') {
                $out .= "\n" . $inflated;
            }
        }

        return $out;
    }
}
