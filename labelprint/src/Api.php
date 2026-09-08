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

        if ($rows === [] && $renderIfMissing) {
            // Синхронный рендеринг: нужен, когда файл только что появился,
            // а печатать надо сию секунду, не дожидаясь воркера.
            $this->app->renderer()->renderFile($path, $printerProfile);
            $rows = $this->rows($path, $printerProfile);
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
            ),
            $rows,
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

    /** @return list<array<string,mixed>> */
    private function rows(string $path, PrinterProfile $profile): array
    {
        return $this->app->db()->fetchAll(
            'SELECT l.*
               FROM zpl_labels l
               JOIN pdf_files f ON f.id = l.pdf_file_id
              WHERE f.path = ?
                AND l.pdf_sha256 = f.sha256
                AND l.profile_fingerprint = ?
              ORDER BY l.page_no',
            [$path, $profile->fingerprint()],
        );
    }

    private function profile(?string $code): PrinterProfile
    {
        return $this->app->profiles()->get($code ?? $this->app->config->string('default_profile'));
    }
}
