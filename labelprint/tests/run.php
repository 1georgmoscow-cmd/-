<?php
declare(strict_types=1);

/**
 * Минимальный тест-раннер без composer/phpunit: php tests/run.php
 * Каждый файл tests/*_test.php возвращает массив ['имя теста' => callable].
 */

require_once __DIR__ . '/../src/bootstrap.php';

// Фикстуры генерируются, а не хранятся в git — создаём их при первом запуске.
if (!is_dir(__DIR__ . '/fixtures') || (glob(__DIR__ . '/fixtures/*.pbm') ?: []) === []) {
    require __DIR__ . '/make_fixtures.php';
}

$passed = 0;
$failed = 0;
$failures = [];

/** Хелперы, доступные внутри тестов. */
function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\n  ожидалось: %s\n  получено:  %s",
            $msg !== '' ? $msg : 'assertSame провален',
            is_string($expected) ? shortHex($expected) : var_export($expected, true),
            is_string($actual) ? shortHex($actual) : var_export($actual, true),
        ));
    }
}

function assertTrue(bool $cond, string $msg = 'assertTrue провален'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assertContains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(($msg !== '' ? $msg : 'assertContains провален')
            . "\n  искали: " . shortHex($needle)
            . "\n  в:      " . shortHex($haystack));
    }
}

function shortHex(string $s, int $max = 160): string
{
    $printable = preg_match('//u', $s) === 1 && !preg_match('/[\x00-\x08\x0e-\x1f]/', $s);
    $out = $printable ? $s : bin2hex($s);
    return strlen($out) > $max ? substr($out, 0, $max) . '…(' . strlen($s) . ' байт)' : $out;
}

$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);

foreach ($files as $file) {
    $tests = require $file;
    $suite = basename($file, '_test.php');
    foreach ($tests as $name => $fn) {
        try {
            $fn();
            $passed++;
            printf("  \033[32m✓\033[0m %s :: %s\n", $suite, $name);
        } catch (Throwable $e) {
            $failed++;
            $failures[] = "{$suite} :: {$name}\n" . $e->getMessage();
            printf("  \033[31m✗\033[0m %s :: %s\n", $suite, $name);
        }
    }
}

echo "\n";
foreach ($failures as $f) {
    echo "\033[31m" . $f . "\033[0m\n\n";
}
printf("Итого: %d пройдено, %d провалено\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
