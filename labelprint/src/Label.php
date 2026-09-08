<?php
declare(strict_types=1);

namespace LabelPrint;

/** Готовая к печати этикетка со всем, что нужно прикладному коду. */
final class Label
{
    /** @param list<string> $codes значения распознанных на этикетке кодов */
    public function __construct(
        /** Идентификатор строки в zpl_labels — с ним делается сверка после наклейки. */
        public readonly int $id,
        public readonly string $path,
        public readonly int $pageNo,
        /** Готовый к отправке поток ^XA…^XZ. */
        public readonly string $zpl,
        public readonly int $dpi,
        public readonly int $widthDots,
        public readonly int $heightDots,
        public readonly string $profileCode,
        public readonly array $codes,
    ) {
    }

    /** Первый распознанный код — то, что вернёт сканер при проверке. */
    public function code(): ?string
    {
        return $this->codes[0] ?? null;
    }

    public function hasCodes(): bool
    {
        return $this->codes !== [];
    }
}
