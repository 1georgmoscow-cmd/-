<?php
declare(strict_types=1);

namespace LabelPrint\Support;

/**
 * Конфигурация сервиса. Читает config/config.php (массив) и даёт типизированный доступ
 * с точечными ключами: $config->string('db.dsn'), $config->int('worker.max_jobs').
 * Любое значение можно переопределить переменной окружения LABELPRINT_DB_DSN и т.п.
 */
final class Config
{
    /** @param array<string,mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= LABELPRINT_ROOT . '/config/config.php';

        if (!is_file($path)) {
            $example = dirname($path) . '/' . basename($path, '.php') . '.example.php';
            throw new \RuntimeException(
                "Конфиг не найден: {$path}\nСкопируйте пример: cp {$example} {$path}",
            );
        }

        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException("Конфиг {$path} должен возвращать массив");
        }

        return new self(self::applyEnvOverrides($values));
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);
        if (!is_string($value)) {
            throw new \RuntimeException("Параметр конфига '{$key}' должен быть строкой");
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default);
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw new \RuntimeException("Параметр конфига '{$key}' должен быть числом");
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /** @return array<string,mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Переопределение через окружение: ключ 'db.dsn' -> LABELPRINT_DB_DSN.
     * Удобно для systemd (EnvironmentFile) и для контейнеров, чтобы не править файл.
     *
     * @param  array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function applyEnvOverrides(array $values, string $prefix = ''): array
    {
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix . '_' . $key;

            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $values[$key] = self::applyEnvOverrides($value, $path);
                continue;
            }

            $envName = 'LABELPRINT_' . strtoupper(str_replace('.', '_', $path));
            $env = getenv($envName);
            if ($env !== false) {
                $values[$key] = $env;
            }
        }

        return $values;
    }
}
