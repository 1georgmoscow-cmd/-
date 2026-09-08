<?php
declare(strict_types=1);

namespace LabelPrint\Barcode;

use LabelPrint\Pdf\Bitmap;
use LabelPrint\Support\Config;
use LabelPrint\Support\Log;

/**
 * Распознавание кодов на готовом растре этикетки.
 *
 * Важно, ЧТО именно распознаётся: не исходный PDF, а ровно тот однобитный растр,
 * который через мгновение уедет на принтер. Это не мелочь, а суть проверки.
 * Если код не читается из этого растра, его не прочтёт и сканер на складе —
 * значит, брак виден на этапе рендеринга, а не после того, как этикетку наклеили
 * на коробку.
 *
 * Сбой распознавания НЕ роняет задание: этикетка без прочитанного кода всё равно
 * печатается, просто в лог уходит предупреждение. Терять печать из-за
 * необязательной проверки было бы хуже, чем печатать без неё.
 */
final class CodeReader
{
    /** @var list<CodeReaderInterface> */
    private array $readers;

    /** @param list<CodeReaderInterface> $readers */
    public function __construct(array $readers, private readonly Log $log)
    {
        $this->readers = $readers;
    }

    public static function fromConfig(Config $config, Log $log): self
    {
        $readers = [];

        if ($config->bool('barcode.zbar', true)) {
            $readers[] = new ZbarReader(
                $config->string('zbarimg', '/usr/bin/zbarimg'),
                $config->int('barcode.timeout', 10),
            );
        }

        // DataMatrix стоит впятеро дороже остальных символик, поэтому включается осознанно.
        if ($config->bool('barcode.dmtx', false)) {
            $readers[] = new DmtxReader(
                $config->string('dmtxread', '/usr/bin/dmtxread'),
                $config->int('barcode.timeout', 10),
                $config->int('barcode.dmtx_max_symbols', 1),
                $config->int('barcode.dmtx_budget_ms', 1500),
            );
        }

        return new self($readers, $log);
    }

    public function isEnabled(): bool
    {
        return $this->readers !== [];
    }

    /** @return list<CodeReaderInterface> */
    public function readers(): array
    {
        return $this->readers;
    }

    /**
     * @param  string $label что рендерим — только для сообщений в лог
     * @return list<DecodedCode>
     */
    public function read(Bitmap $bitmap, string $label = ''): array
    {
        $found = [];
        $seen = [];

        foreach ($this->readers as $reader) {
            if (!$reader->isAvailable()) {
                continue;
            }

            try {
                $codes = $reader->read($bitmap);
            } catch (\Throwable $e) {
                $this->log->warning('распознаватель кодов не отработал', [
                    'reader' => $reader->name(),
                    'label' => $label,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($codes as $code) {
                // Один и тот же код могут вернуть два распознавателя.
                $key = $code->symbology . "\0" . $code->value;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $found[] = $code;
            }
        }

        return $found;
    }
}
