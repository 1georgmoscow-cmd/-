<?php
declare(strict_types=1);

namespace LabelPrint\Barcode;

/**
 * Код, распознанный на этикетке.
 *
 * Значение хранится в БАЙТАХ, а не в строке текста. Это принципиально: код
 * «Честного знака» содержит разделители GS (0x1D), а QR может нести любые байты
 * вплоть до двоичных. Приведение к UTF-8 здесь испортило бы данные.
 */
final class DecodedCode
{
    public function __construct(
        /** Символика в терминах декодера: QR-Code, CODE-128, EAN-13, DataMatrix. */
        public readonly string $symbology,
        /** Содержимое как есть, побайтово. */
        public readonly string $value,
        /** Кто распознал: zbar или dmtx. */
        public readonly string $reader,
        /**
         * Оценка уверенности от zbar. Чем выше, тем надёжнее прочитан код.
         * Ноль или очень низкое значение на растре, который вот-вот уедет
         * на принтер, — прямой признак того, что и сканер его не возьмёт.
         */
        public readonly ?int $quality = null,
        /** Габариты кода на этикетке в точках: [x, y, ширина, высота]. */
        public readonly ?array $box = null,
    ) {
    }

    /**
     * Значение, приведённое к виду, в котором его обычно выдаёт ручной сканер.
     *
     * Сканеры по-разному поступают с управляющими байтами: одни отдают GS как есть,
     * другие выбрасывают, третьи заменяют на печатный символ. Сравнивать «как есть»
     * поэтому недостаточно — рядом хранится нормализованный вид, и потребитель
     * может сопоставлять по любому из двух.
     */
    public function normalized(): string
    {
        return self::normalize($this->value);
    }

    /** Убирает управляющие байты (включая GS 0x1D) и обрезает пробелы по краям. */
    public static function normalize(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? $value);
    }

    /**
     * Похоже ли значение на код маркировки «Честный знак».
     *
     * Признак, а не доказательство: у такого кода структура GS1 — идентификатор
     * применения 01 (GTIN, 14 цифр), сразу за ним 21 (серийный номер). Проверка
     * нужна для подсказок оператору и для логов, а не для юридически значимой
     * валидации: та делается обращением к системе маркировки.
     */
    public function looksLikeChestnyZnak(): bool
    {
        $normalized = $this->normalized();

        return strlen($normalized) >= 18
            && str_starts_with($normalized, '01')
            && ctype_digit(substr($normalized, 2, 14))
            && substr($normalized, 16, 2) === '21';
    }

    /** Человекочитаемое представление для логов: двоичные байты экранируются. */
    public function display(int $limit = 80): string
    {
        $text = preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn(array $m): string => sprintf('<%02X>', ord($m[0])),
            $this->value,
        ) ?? $this->value;

        return mb_strimwidth($text, 0, $limit, '…');
    }
}
