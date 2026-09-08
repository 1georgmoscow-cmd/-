<?php
declare(strict_types=1);

namespace LabelPrint\Barcode;

use LabelPrint\Pdf\Bitmap;
use LabelPrint\Support\Process;

/**
 * Распознавание DataMatrix через dmtxread (пакет dmtx-utils).
 *
 * Нужен ровно для одного: «Честный знак» кодируется именно в DataMatrix, а zbar
 * эту символику не поддерживает вовсе.
 *
 * По умолчанию ВЫКЛЮЧЕН. На этикетке 800x1199 dmtxread работает около 230 мс
 * против 40 мс у zbar — он сканирует изображение сеткой линий, а не ищет
 * характерные маркеры. Если на этикетках DataMatrix нет, платить за это незачем.
 *
 * Важно про «тихую зону»: libdmtx требует белого поля вокруг символа. Символ,
 * прижатый к краю этикетки или к соседнему элементу, не распознаётся — это
 * особенность декодера, а не ошибка рендеринга.
 */
final class DmtxReader implements CodeReaderInterface
{
    public function __construct(
        private readonly string $binary = '/usr/bin/dmtxread',
        private readonly int $timeoutSeconds = 10,
        /** Сколько символов искать. Каждый следующий стоит ещё один проход. */
        private readonly int $maxSymbols = 1,
        /** Предел работы декодера на изображение, миллисекунды. */
        private readonly int $budgetMs = 1500,
    ) {
    }

    public function name(): string
    {
        return 'dmtx';
    }

    public function symbologies(): string
    {
        return 'DataMatrix (в том числе «Честный знак»)';
    }

    public function isAvailable(): bool
    {
        return is_executable($this->binary);
    }

    /** @return list<DecodedCode> */
    public function read(Bitmap $bitmap): array
    {
        $result = Process::run(
            [
                $this->binary,
                '-N' . max(1, $this->maxSymbols),
                '-m' . max(100, $this->budgetMs),
                '-n',   // разделять найденные значения переводом строки
                '-',
            ],
            $this->timeoutSeconds,
            stdin: $bitmap->toPbm(),
        );

        // Код 1 означает «символов не найдено» — штатный ответ, а не сбой:
        // DataMatrix есть далеко не на каждой этикетке.
        if ($result['code'] !== 0 && $result['code'] !== 1) {
            throw new \RuntimeException(
                "dmtxread завершился с кодом {$result['code']}: " . Process::tail($result['stderr']),
            );
        }

        $codes = [];
        foreach (explode("\n", $result['stdout']) as $line) {
            if ($line === '') {
                continue;
            }

            $codes[] = new DecodedCode(
                symbology: 'DataMatrix',
                value: $line,
                reader: $this->name(),
            );
        }

        return $codes;
    }
}
