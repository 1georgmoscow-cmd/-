<?php
declare(strict_types=1);

namespace LabelPrint;

/** Готовая к печати этикетка со всем, что нужно прикладному коду. */
final class Label
{
    /**
     * Распознанный код этикетки — то самое, что вернёт сканер при проверке.
     *
     * У этикетки OZON он ровно один (QR). Если кодов почему-то несколько,
     * здесь лежит первый, а полный список — в свойстве codes.
     */
    public readonly ?string $barcode;

    /** @param list<string> $codes значения всех распознанных кодов */
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
        /** Номер отправления, если он известен. */
        public readonly ?string $postingId = null,
    ) {
        $this->barcode = $codes[0] ?? null;
    }

    public function hasCodes(): bool
    {
        return $this->codes !== [];
    }

    /** Представление для JSON-ответа или лога. */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'posting_id' => $this->postingId,
            'page_no' => $this->pageNo,
            'barcode' => $this->barcode,
            'codes' => $this->codes,
            'zpl' => $this->zpl,
            'zpl_bytes' => strlen($this->zpl),
            'dpi' => $this->dpi,
            'width_dots' => $this->widthDots,
            'height_dots' => $this->heightDots,
            'profile' => $this->profileCode,
            'path' => $this->path,
        ];
    }
}
