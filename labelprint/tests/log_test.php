<?php
declare(strict_types=1);

use LabelPrint\Support\Log;

/** Пишет лог во временный файл и возвращает его содержимое. */
function captureLog(callable $fn, string $level = 'debug', bool $json = false): string
{
    $file = sys_get_temp_dir() . '/labelprint-tests/log-' . $level . ($json ? '-json' : '') . '.log';
    @mkdir(dirname($file), 0777, true);
    @unlink($file);

    $log = Log::create($level, $file, 'test', $json);
    $fn($log);

    return (string) file_get_contents($file);
}

return [
    'имя файла в CP1251 не обнуляет строку лога' => static function (): void {
        // Имена файлов в Linux — произвольные байты. Без JSON_INVALID_UTF8_SUBSTITUTE
        // json_encode вернул бы false, и запись ушла бы пустой ровно там,
        // где она нужна для разбора проблемы.
        $cp1251Name = "\xDD\xF2\xE8\xEA\xE5\xF2\xEA\xE0-\xC2\xC1.pdf";

        $text = captureLog(static fn(Log $log) => $log->info('файл поставлен в очередь', ['path' => $cp1251Name]));

        assertContains('файл поставлен в очередь', $text);
        assertTrue(str_contains($text, 'path'), 'контекст должен присутствовать: ' . $text);
        assertTrue(!str_contains($text, '(контекст не сериализуется)'), 'подстановка должна была сработать');
    },

    'то же самое в режиме JSON' => static function (): void {
        $cp1251Name = "\xDD\xF2\xE8\xEA\xE5\xF2\xEA\xE0.pdf";

        $text = captureLog(
            static fn(Log $log) => $log->warning('битое имя', ['path' => $cp1251Name]),
            'debug',
            true,
        );

        $decoded = json_decode(trim($text), true);
        assertTrue(is_array($decoded), 'строка должна быть валидным JSON: ' . $text);
        assertSame('битое имя', $decoded['msg']);
        assertSame('warning', $decoded['level']);
    },

    'уровень ниже порога не пишется' => static function (): void {
        $text = captureLog(static function (Log $log): void {
            $log->debug('это не должно попасть в лог');
            $log->error('а это должно');
        }, 'error');

        assertTrue(!str_contains($text, 'не должно попасть'), 'debug отфильтрован');
        assertContains('а это должно', $text);
    },

    'канал переключается без потери настроек' => static function (): void {
        $text = captureLog(static function (Log $log): void {
            $log->withChannel('render')->info('сообщение канала');
        });

        assertContains('render', $text);
    },
];
