<?php
declare(strict_types=1);

namespace LabelPrint\Support;

/**
 * Разбор аргументов командной строки.
 *
 * Встроенный getopt() здесь не годится: он прекращает разбор на первом позиционном
 * аргументе, поэтому «render.php label.pdf --stdout» молча теряет --stdout.
 * Порядок аргументов должен быть свободным — это первое, что делает пользователь.
 */
final class Args
{
    /**
     * @param array<string,string|bool> $options
     * @param list<string>              $positional
     */
    private function __construct(
        private readonly array $options,
        private readonly array $positional,
    ) {
    }

    /**
     * @param list<string> $argv   обычно $argv целиком, включая имя скрипта
     * @param list<string> $flags  опции без значения: --stdout
     * @param list<string> $valued опции со значением: --profile=код или --profile код
     */
    public static function parse(array $argv, array $flags = [], array $valued = []): self
    {
        $options = [];
        $positional = [];
        $arguments = array_slice($argv, 1);
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $arg = $arguments[$i];

            // Всё после «--» считается позиционным: так можно передать имя файла,
            // начинающееся с дефиса.
            if ($arg === '--') {
                $positional = array_merge($positional, array_slice($arguments, $i + 1));
                break;
            }

            if (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
                continue;
            }

            $body = substr($arg, 2);
            $value = null;

            if (str_contains($body, '=')) {
                [$body, $value] = explode('=', $body, 2);
            }

            if (in_array($body, $valued, true)) {
                if ($value === null) {
                    // Форма «--profile код»: значение стоит следующим аргументом.
                    if ($i + 1 >= $count || str_starts_with($arguments[$i + 1], '--')) {
                        throw new \RuntimeException("Опция --{$body} требует значения");
                    }
                    $value = $arguments[++$i];
                }
                $options[$body] = $value;
                continue;
            }

            if (in_array($body, $flags, true)) {
                if ($value !== null) {
                    throw new \RuntimeException("Опция --{$body} не принимает значения");
                }
                $options[$body] = true;
                continue;
            }

            throw new \RuntimeException(
                "Неизвестная опция --{$body}. Доступны: "
                . implode(', ', array_map(static fn(string $o): string => '--' . $o, [...$flags, ...$valued])),
            );
        }

        return new self($options, $positional);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function value(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @return list<string> */
    public function positional(): array
    {
        return $this->positional;
    }

    public function first(): ?string
    {
        return $this->positional[0] ?? null;
    }
}
