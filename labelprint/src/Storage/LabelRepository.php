<?php
declare(strict_types=1);

namespace LabelPrint\Storage;

use LabelPrint\Db\Db;
use LabelPrint\Model\PrinterProfile;

/**
 * Хранилище готовых ZPL.
 *
 * Кэш контент-адресуемый: ключ — SHA-256 содержимого PDF плюс отпечаток профиля
 * плюс номер страницы. Поэтому повторно загруженный под другим именем тот же файл
 * не рендерится заново, а изменение параметров профиля автоматически даёт новую запись.
 */
final class LabelRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Сохраняет отрендеренную страницу. Повторный рендер той же страницы перезаписывает запись.
     *
     * @return int id строки в zpl_labels
     */
    public function store(
        int $pdfFileId,
        string $pdfSha256,
        PrinterProfile $profile,
        int $pageNo,
        string $zpl,
        int $widthDots,
        int $heightDots,
        ?float $inkCoverage = null,
        ?int $renderMs = null,
    ): int {
        // Лучше понятная ошибка здесь, чем «MySQL server has gone away» на записи.
        $this->db->assertFits(strlen($zpl), "ZPL страницы {$pageNo} файла {$pdfSha256}");

        $this->db->run(
            'INSERT INTO zpl_labels
                (pdf_file_id, pdf_sha256, profile_code, profile_fingerprint, page_no,
                 dpi, width_dots, height_dots, compression, zpl, zpl_bytes, zpl_sha256,
                 ink_coverage, render_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                pdf_file_id = VALUES(pdf_file_id),
                profile_code = VALUES(profile_code),
                dpi = VALUES(dpi),
                width_dots = VALUES(width_dots),
                height_dots = VALUES(height_dots),
                compression = VALUES(compression),
                zpl = VALUES(zpl),
                zpl_bytes = VALUES(zpl_bytes),
                zpl_sha256 = VALUES(zpl_sha256),
                ink_coverage = VALUES(ink_coverage),
                render_ms = VALUES(render_ms),
                id = LAST_INSERT_ID(zpl_labels.id)',
            [
                $pdfFileId,
                $pdfSha256,
                $profile->code,
                $profile->fingerprint(),
                $pageNo,
                $profile->dpi,
                $widthDots,
                $heightDots,
                $profile->compression,
                $zpl,
                strlen($zpl),
                hash('sha256', $zpl),
                $inkCoverage,
                $renderMs,
            ],
            idempotent: true,
        );

        return $this->db->lastInsertId();
    }

    /**
     * Готовый ZPL из кэша.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $pdfSha256, PrinterProfile $profile, int $pageNo = 1): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM zpl_labels
              WHERE pdf_sha256 = ? AND profile_fingerprint = ? AND page_no = ?',
            [$pdfSha256, $profile->fingerprint(), $pageNo],
        );
    }

    /**
     * Все страницы файла под профиль, по порядку — то, что нужно отправить на принтер.
     *
     * @return list<array<string,mixed>>
     */
    public function findPages(string $pdfSha256, PrinterProfile $profile): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM zpl_labels
              WHERE pdf_sha256 = ? AND profile_fingerprint = ?
              ORDER BY page_no ASC',
            [$pdfSha256, $profile->fingerprint()],
        );
    }

    /** Сколько страниц уже отрендерено для файла под профиль. */
    public function pageCount(string $pdfSha256, PrinterProfile $profile): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM zpl_labels WHERE pdf_sha256 = ? AND profile_fingerprint = ?',
            [$pdfSha256, $profile->fingerprint()],
        );

        return (int) ($row['n'] ?? 0);
    }

    /** Удаляет лишние страницы, если PDF стал короче, чем был при прошлом рендере. */
    public function deletePagesAbove(string $pdfSha256, PrinterProfile $profile, int $lastPage): int
    {
        return $this->db->run(
            'DELETE FROM zpl_labels
              WHERE pdf_sha256 = ? AND profile_fingerprint = ? AND page_no > ?',
            [$pdfSha256, $profile->fingerprint(), $lastPage],
        )->rowCount();
    }

    /**
     * Удаляет этикетки, чей хэш содержимого не соответствует ни одному текущему файлу.
     *
     * Такие записи появляются, когда PDF перезаписывают по тому же пути: новая версия
     * рендерится заново, а этикетки прошлой версии остаются. Кэш контент-адресуемый,
     * поэтому удалять их сразу нельзя — тот же файл может вернуться под другим именем,
     * и тогда рендеринг не понадобится. Но со временем они накапливаются, и эта
     * уборка безопасно снимает те, на которые уже никто не ссылается.
     *
     * @return int сколько записей удалено
     */
    public function pruneOrphans(): int
    {
        return $this->db->run(
            'DELETE l FROM zpl_labels l
              WHERE NOT EXISTS (SELECT 1 FROM pdf_files f WHERE f.sha256 = l.pdf_sha256)',
            [],
            idempotent: true,
        )->rowCount();
    }

    /** @return array{labels:int,bytes:int,avg_bytes:int,avg_render_ms:int} */
    public function stats(): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS labels,
                    COALESCE(SUM(zpl_bytes), 0) AS bytes,
                    COALESCE(AVG(zpl_bytes), 0) AS avg_bytes,
                    COALESCE(AVG(render_ms), 0) AS avg_render_ms
               FROM zpl_labels',
        ) ?? [];

        return [
            'labels' => (int) ($row['labels'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'avg_bytes' => (int) round((float) ($row['avg_bytes'] ?? 0)),
            'avg_render_ms' => (int) round((float) ($row['avg_render_ms'] ?? 0)),
        ];
    }
}
