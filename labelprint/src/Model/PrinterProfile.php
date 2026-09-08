<?php
declare(strict_types=1);

namespace LabelPrint\Model;

/**
 * Профиль принтера/этикетки: всё, что влияет на результат рендеринга.
 *
 * Профиль — часть ключа кэша. Один и тот же PDF, отрендеренный под 203 и 300 dpi,
 * даёт две разные записи в таблице готовых ZPL.
 */
final class PrinterProfile
{
    public const FIT_NATIVE = 'native';
    public const FIT_FIT = 'fit';

    public const COMPRESSION_HEX = 'hex';
    public const COMPRESSION_ACS = 'acs';
    public const COMPRESSION_Z64 = 'z64';

    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly int $dpi = 203,
        /** Ширина этикетки в миллиметрах. */
        public readonly float $widthMm = 100.0,
        /** Высота этикетки в миллиметрах. */
        public readonly float $heightMm = 150.0,
        /** native — доверять размеру страницы PDF; fit — вписывать в размер этикетки. */
        public readonly string $fit = self::FIT_FIT,
        /** Поворот растра, градусы по часовой: 0, 90, 180 или 270. */
        public readonly int $rotate = 0,
        /**
         * Доворачивать на 90 градусов, если ориентация страницы PDF не совпадает
         * с ориентацией этикетки. Типовой случай: этикетка приходит «лёжа».
         */
        public readonly bool $autoRotate = true,
        /** Порог бинаризации 1..254 при рендеринге через полутон. */
        public readonly int $threshold = 128,
        /** Инвертировать изображение (белое на чёрном). */
        public readonly bool $invert = false,
        public readonly string $compression = self::COMPRESSION_ACS,
        /** ^MD, -30..30; null — не трогать настройку принтера. */
        public readonly ?int $darkness = null,
        /** ^PR, скорость печати в дюймах/сек; null — не трогать. */
        public readonly ?int $printRate = null,
        /** Отслеживание носителя: gap | mark | continuous | null (не трогать). */
        public readonly ?string $mediaTracking = 'gap',
        /** Количество копий в задании (^PQ). */
        public readonly int $quantity = 1,
    ) {
        if ($this->dpi <= 0) {
            throw new \InvalidArgumentException("Профиль {$code}: dpi должен быть положительным");
        }
        if (!in_array($this->fit, [self::FIT_NATIVE, self::FIT_FIT], true)) {
            throw new \InvalidArgumentException("Профиль {$code}: fit должен быть native или fit");
        }
        if (!in_array($this->rotate, [0, 90, 180, 270], true)) {
            throw new \InvalidArgumentException("Профиль {$code}: rotate должен быть 0, 90, 180 или 270");
        }
        if ($this->threshold < 1 || $this->threshold > 254) {
            throw new \InvalidArgumentException("Профиль {$code}: threshold должен быть в диапазоне 1..254");
        }
        if (!in_array($this->compression, [self::COMPRESSION_HEX, self::COMPRESSION_ACS, self::COMPRESSION_Z64], true)) {
            throw new \InvalidArgumentException("Профиль {$code}: неизвестный способ сжатия '{$this->compression}'");
        }
        if ($this->darkness !== null && ($this->darkness < -30 || $this->darkness > 30)) {
            throw new \InvalidArgumentException("Профиль {$code}: darkness (^MD) должен быть в диапазоне -30..30");
        }
        if ($this->mediaTracking !== null && !in_array($this->mediaTracking, ['gap', 'mark', 'continuous'], true)) {
            throw new \InvalidArgumentException("Профиль {$code}: mediaTracking должен быть gap, mark, continuous или null");
        }
        if ($this->quantity < 1) {
            throw new \InvalidArgumentException("Профиль {$code}: quantity должен быть не меньше 1");
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(string $code, array $data): self
    {
        return new self(
            code: $code,
            title: (string) ($data['title'] ?? $code),
            dpi: (int) ($data['dpi'] ?? 203),
            widthMm: (float) ($data['width_mm'] ?? 100.0),
            heightMm: (float) ($data['height_mm'] ?? 150.0),
            fit: (string) ($data['fit'] ?? self::FIT_FIT),
            rotate: (int) ($data['rotate'] ?? 0),
            autoRotate: (bool) ($data['auto_rotate'] ?? true),
            threshold: (int) ($data['threshold'] ?? 128),
            invert: (bool) ($data['invert'] ?? false),
            compression: (string) ($data['compression'] ?? self::COMPRESSION_ACS),
            darkness: isset($data['darkness']) ? (int) $data['darkness'] : null,
            printRate: isset($data['print_rate']) ? (int) $data['print_rate'] : null,
            mediaTracking: array_key_exists('media_tracking', $data)
                ? ($data['media_tracking'] === null ? null : (string) $data['media_tracking'])
                : 'gap',
            quantity: (int) ($data['quantity'] ?? 1),
        );
    }

    /** Этикетка шире, чем выше. */
    public function isLandscape(): bool
    {
        return $this->widthMm > $this->heightMm;
    }

    /** Ширина этикетки в точках принтера. */
    public function widthDots(): int
    {
        return (int) round($this->widthMm / 25.4 * $this->dpi);
    }

    /** Высота этикетки в точках принтера. */
    public function heightDots(): int
    {
        return (int) round($this->heightMm / 25.4 * $this->dpi);
    }

    /**
     * Отпечаток профиля для ключа кэша: меняется только при изменении параметров,
     * реально влияющих на итоговый ZPL (title и quantity — не влияют на растр).
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', json_encode([
            $this->dpi,
            $this->widthMm,
            $this->heightMm,
            $this->fit,
            $this->rotate,
            $this->autoRotate,
            $this->threshold,
            $this->invert,
            $this->compression,
            $this->darkness,
            $this->printRate,
            $this->mediaTracking,
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }
}
