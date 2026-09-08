<?php
declare(strict_types=1);

namespace LabelPrint\Support;

/**
 * Минимальный структурный логгер. По умолчанию пишет в STDERR, что при запуске
 * под systemd автоматически попадает в journald (journalctl -u labelprint-worker).
 */
final class Log
{
    public const DEBUG = 10;
    public const INFO = 20;
    public const WARNING = 30;
    public const ERROR = 40;

    private const LEVEL_NAMES = [
        self::DEBUG => 'debug',
        self::INFO => 'info',
        self::WARNING => 'warning',
        self::ERROR => 'error',
    ];

    /** @var resource */
    private $stream;

    private function __construct(
        private readonly int $minLevel,
        private readonly string $channel,
        private readonly bool $json,
        $stream,
    ) {
        $this->stream = $stream;
    }

    /**
     * @param string      $level   debug|info|warning|error
     * @param string|null $file    путь к файлу лога; null — STDERR (journald)
     */
    public static function create(string $level = 'info', ?string $file = null, string $channel = 'labelprint', bool $json = false): self
    {
        $min = match (strtolower($level)) {
            'debug' => self::DEBUG,
            'warning', 'warn' => self::WARNING,
            'error' => self::ERROR,
            default => self::INFO,
        };

        if ($file !== null) {
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Не удалось создать каталог для логов: {$dir}");
            }
            $stream = @fopen($file, 'ab');
            if ($stream === false) {
                throw new \RuntimeException("Не удалось открыть файл лога: {$file}");
            }
        } else {
            $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
        }

        return new self($min, $channel, $json, $stream);
    }

    /** Логгер-заглушка для тестов. */
    public static function null(): self
    {
        return new self(PHP_INT_MAX, 'null', false, fopen('php://memory', 'wb'));
    }

    public function withChannel(string $channel): self
    {
        return new self($this->minLevel, $channel, $this->json, $this->stream);
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write(self::DEBUG, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write(self::INFO, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write(self::WARNING, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write(self::ERROR, $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(int $level, string $message, array $context): void
    {
        if ($level < $this->minLevel) {
            return;
        }

        $name = self::LEVEL_NAMES[$level] ?? 'info';

        // JSON_INVALID_UTF8_SUBSTITUTE обязателен: в контекст попадают имена файлов,
        // а они в Linux — произвольные байты. PDF с именем в CP1251 иначе заставил бы
        // json_encode вернуть false, и строка лога ушла бы пустой ровно тогда,
        // когда она нужнее всего.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

        if ($this->json) {
            $line = json_encode(
                ['ts' => date('c'), 'level' => $name, 'channel' => $this->channel, 'msg' => $message] + $context,
                $flags,
            );
        } else {
            $line = sprintf('[%s] %-7s %s: %s', date('Y-m-d H:i:s'), $name, $this->channel, $message);
            if ($context !== []) {
                $line .= ' ' . json_encode($context, $flags);
            }
        }

        if ($line === false) {
            $line = sprintf('[%s] %-7s %s: %s (контекст не сериализуется)',
                date('Y-m-d H:i:s'), $name, $this->channel, $message);
        }

        fwrite($this->stream, $line . "\n");
    }
}
