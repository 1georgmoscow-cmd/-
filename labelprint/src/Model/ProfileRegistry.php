<?php
declare(strict_types=1);

namespace LabelPrint\Model;

/** Реестр профилей принтеров из config/printers.php. */
final class ProfileRegistry
{
    /** @var array<string,PrinterProfile> */
    private array $profiles = [];

    /** @param array<string,array<string,mixed>> $definitions */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $code => $data) {
            if (!is_string($code) || !is_array($data)) {
                throw new \InvalidArgumentException('printers.php: ожидается массив вида код => параметры');
            }
            $this->profiles[$code] = PrinterProfile::fromArray($code, $data);
        }

        if ($this->profiles === []) {
            throw new \InvalidArgumentException('printers.php: не задано ни одного профиля');
        }
    }

    public static function load(?string $path = null): self
    {
        $path ??= LABELPRINT_ROOT . '/config/printers.php';

        if (!is_file($path)) {
            $example = dirname($path) . '/' . basename($path, '.php') . '.example.php';
            throw new \RuntimeException(
                "Файл профилей не найден: {$path}\nСкопируйте пример: cp {$example} {$path}",
            );
        }

        $definitions = require $path;
        if (!is_array($definitions)) {
            throw new \RuntimeException("{$path} должен возвращать массив");
        }

        /** @var array<string,array<string,mixed>> $definitions */
        return new self($definitions);
    }

    public function get(string $code): PrinterProfile
    {
        if (!isset($this->profiles[$code])) {
            throw new \RuntimeException(
                "Неизвестный профиль принтера '{$code}'. Доступны: " . implode(', ', $this->codes()),
            );
        }

        return $this->profiles[$code];
    }

    public function has(string $code): bool
    {
        return isset($this->profiles[$code]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->profiles);
    }

    /** @return array<string,PrinterProfile> */
    public function all(): array
    {
        return $this->profiles;
    }
}
