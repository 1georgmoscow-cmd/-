<?php
declare(strict_types=1);

namespace LabelPrint\Barcode;

use LabelPrint\Pdf\Bitmap;
use LabelPrint\Support\Process;

/**
 * Распознавание кодов через zbarimg (пакет zbar-tools).
 *
 * Читает QR-Code, CODE-128, CODE-39, CODE-93, EAN/UPC, ITF, Codabar, DataBar.
 * DataMatrix — то есть «Честный знак» — zbar НЕ умеет, для него есть DmtxReader.
 *
 * Растр отдаётся прямо в формате PBM: он у нас уже есть после рендеринга,
 * весит в восемь раз меньше полутонового и распознаётся с той же скоростью.
 *
 * Используется вывод --xml, а не построчный. Построчный формат «СИМВОЛИКА:значение»
 * разваливается на коде, внутри которого есть перевод строки, а XML к тому же
 * несёт оценку качества и координаты и умеет отдавать двоичные значения в base64.
 */
final class ZbarReader implements CodeReaderInterface
{
    public function __construct(
        private readonly string $binary = '/usr/bin/zbarimg',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function name(): string
    {
        return 'zbar';
    }

    public function symbologies(): string
    {
        return 'QR-Code, CODE-128, CODE-39, CODE-93, EAN/UPC, ITF, Codabar, DataBar';
    }

    public function isAvailable(): bool
    {
        return is_executable($this->binary);
    }

    /** @return list<DecodedCode> */
    public function read(Bitmap $bitmap): array
    {
        $result = Process::run(
            [$this->binary, '-q', '--xml', '-'],
            $this->timeoutSeconds,
            stdin: $bitmap->toPbm(),
        );

        // Код возврата 4 означает «символов не найдено» — это штатный ответ,
        // а не сбой: на этикетке может не быть ни одного кода.
        if ($result['code'] !== 0 && $result['code'] !== 4) {
            throw new \RuntimeException(
                "zbarimg завершился с кодом {$result['code']}: " . Process::tail($result['stderr']),
            );
        }

        return $result['stdout'] === '' ? [] : $this->parseXml($result['stdout']);
    }

    /** @return list<DecodedCode> */
    private function parseXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return [];
        }

        $codes = [];

        foreach ($doc->xpath('//*[local-name()="symbol"]') ?: [] as $symbol) {
            $data = $symbol->xpath('*[local-name()="data"]');
            if ($data === false || $data === []) {
                continue;
            }

            $value = (string) $data[0];

            // Значения с двоичными байтами zbar отдаёт в base64 — иначе они
            // не пережили бы XML. Признак стоит атрибутом на самом узле data.
            if ((string) ($data[0]->attributes()['format'] ?? '') === 'base64') {
                $decoded = base64_decode(trim($value), true);
                if ($decoded === false) {
                    continue;
                }
                $value = $decoded;
            }

            if ($value === '') {
                continue;
            }

            $quality = isset($symbol->attributes()['quality'])
                ? (int) $symbol->attributes()['quality']
                : null;

            $codes[] = new DecodedCode(
                symbology: (string) ($symbol->attributes()['type'] ?? 'unknown'),
                value: $value,
                reader: $this->name(),
                quality: $quality,
                box: $this->boxFromPolygon($symbol),
            );
        }

        return $codes;
    }

    /**
     * Габариты по вершинам многоугольника: zbar отдаёт точки вида «+20,+300 +20,+620 …».
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function boxFromPolygon(\SimpleXMLElement $symbol): ?array
    {
        $polygon = $symbol->xpath('*[local-name()="polygon"]');
        if ($polygon === false || $polygon === []) {
            return null;
        }

        $points = (string) ($polygon[0]->attributes()['points'] ?? '');
        if (preg_match_all('/([+-]?\d+),([+-]?\d+)/', $points, $m, PREG_SET_ORDER) < 1) {
            return null;
        }

        $xs = array_map(static fn(array $p): int => (int) $p[1], $m);
        $ys = array_map(static fn(array $p): int => (int) $p[2], $m);

        return [min($xs), min($ys), max($xs) - min($xs) + 1, max($ys) - min($ys) + 1];
    }
}
