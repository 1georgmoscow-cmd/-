<?php
declare(strict_types=1);

namespace LabelPrint\Barcode;

use LabelPrint\Pdf\Bitmap;

/** Распознаватель кодов на растре этикетки. */
interface CodeReaderInterface
{
    /** @return list<DecodedCode> */
    public function read(Bitmap $bitmap): array;

    public function name(): string;

    public function isAvailable(): bool;

    /** Какие символики умеет читать — для диагностики. */
    public function symbologies(): string;
}
