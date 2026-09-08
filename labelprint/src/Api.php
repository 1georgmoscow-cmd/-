<?php
declare(strict_types=1);

namespace LabelPrint;

use LabelPrint\Model\PrinterProfile;

/**
 * Точка входа для встраивания в чужой код.
 *
 * Всё остальное в проекте — воркер, очередь, растеризаторы — работает само.
 * Прикладной программе нужно ровно четыре вещи: получить готовый ZPL, при
 * необходимости отрендерить его прямо сейчас, отправить на принтер и потом
 * сверить с показаниями сканера. Этот класс их и даёт.
 *
 *   $api = LabelPrint\Api::boot();
 *   $label = $api->label('ozon.pdf');
 *   $api->send($label->zpl, '192.168.1.50');
 */
final class Api
{
    private function __construct(private readonly App $app)
    {
    }

    public static function boot(?string $configPath = null): self
    {
        return new self(App::boot($configPath));
    }

    /** Доступ к внутренностям, если понадобится что-то нестандартное. */
    public function app(): App
    {
        return $this->app;
    }

    /**
     * Этикетка по номеру отправления OZON.
     *
     *   $label = $api->byPosting('0494051806-0963-1');
     *   $label->zpl;       // текст этикетки на языке ZPL
     *   $label->barcode;   // распознанный QR — для сверки после наклейки
     *
     * Номер связывается с файлом двумя способами: сканер выводит его из имени
     * файла (по шаблону scanner.posting_id_pattern), либо он задаётся явно
     * через savePosting() — так надёжнее, потому что имя файла из API OZON
     * приходит служебным вроде print_to_sticker_11036.pdf.
     */
    public function byPosting(string $postingId, ?string $profile = null, bool $renderIfMissing = true): ?Label
    {
        $file = $this->app->files()->findByPostingId($postingId);
        if ($file === null) {
            return null;
        }

        return $this->label((string) $file['path'], $profile, $renderIfMissing);
    }

    /**
     * Все страницы отправления.
     *
     * @return list<Label>
     */
    public function pagesByPosting(string $postingId, ?string $profile = null, bool $renderIfMissing = true): array
    {
        $file = $this->app->files()->findByPostingId($postingId);

        return $file === null ? [] : $this->labels((string) $file['path'], $profile, $renderIfMissing);
    }

    /**
     * Принимает PDF от OZON и сразу возвращает готовую этикетку.
     *
     * Основной способ для прикладного кода: вы скачали PDF из API OZON по
     * номеру отправления и передаёте байты сюда. Файл ляжет в pdf_dir, номер
     * будет привязан явно, этикетка отрендерится и вернётся вместе с кодом.
     *
     *   $pdf = ozon_api_get_label($postingId);        // ваш код
     *   $label = $api->savePosting($postingId, $pdf);
     *   $label->zpl;
     *   $label->barcode;
     *
     * Повторный вызов с тем же содержимым ничего не пересчитывает: результат
     * берётся из кэша по хэшу файла.
     *
     * @param string $pdf     содержимое PDF (байты) либо путь к готовому файлу
     * @param bool   $replace перезаписать, если файл под этим номером уже есть
     */
    public function savePosting(
        string $postingId,
        string $pdf,
        ?string $profile = null,
        bool $replace = true,
    ): Label {
        $safe = self::safePostingId($postingId);
        $bytes = self::pdfBytes($pdf);

        $dir = rtrim($this->app->config->string('pdf_dir'), '/');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Каталог для PDF недоступен: {$dir}");
        }

        $relative = $safe . '.pdf';
        $absolute = $dir . '/' . $relative;

        if (!$replace && is_file($absolute)) {
            $existing = $this->byPosting($postingId, $profile);
            if ($existing !== null) {
                return $existing;
            }
        }

        // Пишем во временное имя и переименовываем: rename() в пределах одной
        // файловой системы атомарен, поэтому сканер физически не может увидеть
        // недописанный файл и попытаться его отрендерить.
        $temp = $absolute . '.part';
        if (@file_put_contents($temp, $bytes) !== strlen($bytes)) {
            @unlink($temp);
            throw new \RuntimeException("Не удалось записать {$temp}");
        }
        if (!@rename($temp, $absolute)) {
            @unlink($temp);
            throw new \RuntimeException("Не удалось переименовать {$temp} в {$absolute}");
        }

        $stat = stat($absolute);
        $sha256 = hash_file('sha256', $absolute);
        if ($sha256 === false || $stat === false) {
            throw new \RuntimeException("Не удалось прочитать записанный файл {$absolute}");
        }

        $this->app->files()->upsert($relative, $sha256, (int) $stat['size'], (int) $stat['mtime'], $safe);

        $label = $this->label($relative, $profile, renderIfMissing: true);
        if ($label === null) {
            throw new \RuntimeException("Этикетка для отправления {$postingId} не отрендерилась");
        }

        return $label;
    }

    /**
     * Готовая этикетка: первая страница файла.
     *
     * @param string $path            путь относительно pdf_dir, например '2026/09/ozon.pdf'
     * @param bool   $renderIfMissing отрендерить прямо сейчас, если в базе ещё нет
     */
    public function label(string $path, ?string $profile = null, bool $renderIfMissing = false): ?Label
    {
        $labels = $this->labels($path, $profile, $renderIfMissing);

        return $labels[0] ?? null;
    }

    /**
     * Все страницы файла по порядку.
     *
     * @return list<Label>
     */
    public function labels(string $path, ?string $profile = null, bool $renderIfMissing = false): array
    {
        $printerProfile = $this->profile($profile);
        $rows = $this->rows($path, $printerProfile);
        $postingId = null;

        if ($rows === [] && $renderIfMissing) {
            // Синхронный рендеринг: нужен, когда файл только что появился,
            // а печатать надо сию секунду, не дожидаясь воркера.
            $this->app->renderer()->renderFile($path, $printerProfile);
            $rows = $this->rows($path, $printerProfile);
        }

        if ($rows !== []) {
            $file = $this->app->files()->findByPath($path);
            $postingId = ($file['posting_id'] ?? null) === null ? null : (string) $file['posting_id'];
        }

        $codes = $this->app->codeStore();

        return array_map(
            fn(array $row): Label => new Label(
                id: (int) $row['id'],
                path: $path,
                pageNo: (int) $row['page_no'],
                zpl: (string) $row['zpl'],
                dpi: (int) $row['dpi'],
                widthDots: (int) $row['width_dots'],
                heightDots: (int) $row['height_dots'],
                profileCode: (string) $row['profile_code'],
                codes: array_map(
                    static fn(array $c): string => (string) $c['value'],
                    $codes->forLabel((int) $row['id']),
                ),
                postingId: $postingId,
            ),
            $rows,
        );
    }

    /**
     * Приводит номер отправления к безопасному имени файла.
     *
     * Номер приходит из внешней системы и превращается в путь, поэтому
     * проверка строгая: только буквы, цифры, точка, дефис и подчёркивание.
     * Иначе значение вида ../../etc/passwd увело бы запись за пределы каталога.
     */
    public static function safePostingId(string $postingId): string
    {
        $safe = trim($postingId);

        if ($safe === '' || strlen($safe) > 128) {
            throw new \InvalidArgumentException('Номер отправления пуст или длиннее 128 символов');
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $safe) !== 1 || str_contains($safe, '..')) {
            throw new \InvalidArgumentException(
                "Недопустимый номер отправления '{$postingId}': разрешены буквы, цифры, точка, дефис и подчёркивание",
            );
        }

        return $safe;
    }

    /** Принимает либо содержимое PDF, либо путь к нему. */
    private static function pdfBytes(string $pdfOrPath): string
    {
        if (str_starts_with($pdfOrPath, '%PDF-')) {
            return $pdfOrPath;
        }

        if (is_file($pdfOrPath)) {
            $bytes = file_get_contents($pdfOrPath);
            if ($bytes === false) {
                throw new \RuntimeException("Не удалось прочитать {$pdfOrPath}");
            }

            return $bytes;
        }

        throw new \InvalidArgumentException(
            'Ожидалось содержимое PDF (начинается с %PDF-) или путь к существующему файлу',
        );
    }

    /**
     * Склеенный ZPL всех страниц — то, что отправляется на принтер одной посылкой.
     */
    public function zpl(string $path, ?string $profile = null, bool $renderIfMissing = false): string
    {
        return implode('', array_map(
            static fn(Label $l): string => $l->zpl,
            $this->labels($path, $profile, $renderIfMissing),
        ));
    }

    /**
     * Отправляет ZPL на сетевой принтер (порт 9100).
     *
     * @param  string $host      адрес принтера
     * @throws \RuntimeException если принтер недоступен или запись не прошла
     */
    public function send(string $zpl, string $host, int $port = 9100, float $timeout = 3.0): void
    {
        if ($zpl === '') {
            throw new \RuntimeException('Нечего отправлять: ZPL пустой');
        }

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new \RuntimeException("Принтер {$host}:{$port} недоступен: {$error} ({$errno})");
        }

        try {
            stream_set_timeout($socket, (int) ceil($timeout));

            $total = strlen($zpl);
            $sent = 0;
            while ($sent < $total) {
                $written = @fwrite($socket, substr($zpl, $sent));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException(
                        "Принтер {$host} оборвал приём на {$sent} байте из {$total}",
                    );
                }
                $sent += $written;
            }
        } finally {
            fclose($socket);
        }
    }

    /**
     * Сверка наклеенной этикетки с показаниями сканера.
     *
     * Сравнение идёт и по исходным байтам, и по виду без управляющих символов:
     * ручные сканеры по-разному обходятся с разделителем GS.
     */
    public function verify(int $labelId, string $scanned): bool
    {
        return $this->app->codeStore()->matches($labelId, $scanned);
    }

    /**
     * Обратный поиск: какой этикетке принадлежит считанный код.
     *
     * @return list<array<string,mixed>>
     */
    public function findByCode(string $scanned, int $limit = 20): array
    {
        return $this->app->codeStore()->findByValue($scanned, $limit);
    }

    /**
     * Строки готовых этикеток для файла.
     *
     * Поиск идёт по ХЭШУ СОДЕРЖИМОГО, а не по идентификатору файла. Кэш
     * контент-адресуемый: две записи pdf_files с побайтово одинаковым PDF
     * (например, два отправления с одинаковой этикеткой, или один файл,
     * положенный под двумя именами) делят одну строку в zpl_labels. Соединение
     * по pdf_file_id находило бы её только для того файла, который отрендерился
     * последним, а для остальных возвращало пусто — при том что этикетка есть.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(string $path, PrinterProfile $profile): array
    {
        $file = $this->app->files()->findByPath($path);
        if ($file === null) {
            return [];
        }

        return $this->app->db()->fetchAll(
            'SELECT * FROM zpl_labels
              WHERE pdf_sha256 = ? AND profile_fingerprint = ?
              ORDER BY page_no',
            [(string) $file['sha256'], $profile->fingerprint()],
        );
    }

    private function profile(?string $code): PrinterProfile
    {
        return $this->app->profiles()->get($code ?? $this->app->config->string('default_profile'));
    }
}
