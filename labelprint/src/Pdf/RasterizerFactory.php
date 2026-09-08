<?php
declare(strict_types=1);

namespace LabelPrint\Pdf;

use LabelPrint\Model\PrinterProfile;
use LabelPrint\Support\Config;
use LabelPrint\Support\Log;

/**
 * Выбор движка растеризации.
 *
 * Движок задаётся в профиле принтера, а не глобально. Это не прихоть: разные движки
 * дают чуть разный растр (Ghostscript округляет размер вниз, MuPDF вверх, отсюда
 * расхождение в одну точку), поэтому движок входит в отпечаток профиля и его смена
 * автоматически помечает готовый ZPL устаревшим. Глобальная настройка молча оставила
 * бы в базе смесь из двух рендеров.
 */
final class RasterizerFactory
{
    public const GHOSTSCRIPT = 'ghostscript';
    public const MUPDF = 'mupdf';

    /** @var array<string,RasterizerInterface> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
    ) {
    }

    public function for(PrinterProfile $profile): RasterizerInterface
    {
        return $this->make($profile->engine ?? $this->config->string('render.engine', self::GHOSTSCRIPT));
    }

    public function make(string $engine): RasterizerInterface
    {
        $engine = strtolower($engine);

        if ($engine === 'auto') {
            // MuPDF примерно втрое быстрее, но ставится отдельным пакетом.
            $engine = $this->make(self::MUPDF)->isAvailable() ? self::MUPDF : self::GHOSTSCRIPT;
        }

        return $this->cache[$engine] ??= match ($engine) {
            self::MUPDF, 'mutool' => new MupdfRasterizer(
                $this->config->string('mutool', '/usr/bin/mutool'),
                $this->log,
                $this->config->int('render.timeout', 30),
                $this->config->int('render.max_dots', 40_000_000),
            ),
            self::GHOSTSCRIPT, 'gs' => new GhostscriptRasterizer(
                binary: $this->config->string('ghostscript', '/usr/bin/gs'),
                log: $this->log,
                timeoutSeconds: $this->config->int('render.timeout', 30),
                grayscaleThreshold: $this->config->bool('render.grayscale_threshold', true),
                antialias: $this->config->int('render.antialias', 4),
                maxDots: $this->config->int('render.max_dots', 40_000_000),
            ),
            default => throw new \InvalidArgumentException(
                "Неизвестный движок растеризации '{$engine}'. Доступны: ghostscript, mupdf, auto.",
            ),
        };
    }

    /** @return array<string,RasterizerInterface> все движки — для диагностики */
    public function all(): array
    {
        return [
            self::GHOSTSCRIPT => $this->make(self::GHOSTSCRIPT),
            self::MUPDF => $this->make(self::MUPDF),
        ];
    }
}
