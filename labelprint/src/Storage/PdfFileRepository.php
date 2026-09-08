<?php
declare(strict_types=1);

namespace LabelPrint\Storage;

use LabelPrint\Db\Db;

/** Реестр найденных PDF: путь, хэш содержимого, размер, время изменения. */
final class PdfFileRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Регистрирует файл или обновляет запись, если он изменился.
     *
     * @return array{id:int,changed:bool} changed=true, если файл новый или его содержимое поменялось
     */
    public function upsert(string $relativePath, string $sha256, int $sizeBytes, int $mtime): array
    {
        $pathSha1 = sha1($relativePath);
        $existing = $this->db->fetchOne(
            'SELECT id, sha256 FROM pdf_files WHERE path_sha1 = ?',
            [$pathSha1],
        );

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $changed = (string) $existing['sha256'] !== $sha256;

            // Пишем только при реальном изменении: сканер вызывает upsert на каждом обходе,
            // и лишний UPDATE на сотнях файлов — это лишняя нагрузка на базу.
            if ($changed) {
                $this->db->run(
                    'UPDATE pdf_files SET sha256 = ?, size_bytes = ?, mtime = ?, page_count = NULL WHERE id = ?',
                    [$sha256, $sizeBytes, $mtime, $id],
                );
            }

            return ['id' => $id, 'changed' => $changed];
        }

        $this->db->run(
            'INSERT INTO pdf_files (path, path_sha1, sha256, size_bytes, mtime)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                sha256 = VALUES(sha256), size_bytes = VALUES(size_bytes), mtime = VALUES(mtime),
                id = LAST_INSERT_ID(pdf_files.id)',
            [$relativePath, $pathSha1, $sha256, $sizeBytes, $mtime],
            idempotent: true,
        );

        return ['id' => $this->db->lastInsertId(), 'changed' => true];
    }

    public function setPageCount(int $id, int $pageCount): void
    {
        $this->db->run('UPDATE pdf_files SET page_count = ? WHERE id = ?', [$pageCount, $id], idempotent: true);
    }

    /** @return array<string,mixed>|null */
    public function findByPath(string $relativePath): ?array
    {
        return $this->db->fetchOne('SELECT * FROM pdf_files WHERE path_sha1 = ?', [sha1($relativePath)]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM pdf_files WHERE id = ?', [$id]);
    }

    /**
     * Пути, уже известные базе — сканеру достаточно сравнить размер и mtime,
     * чтобы не считать SHA-256 у сотен неизменившихся файлов.
     *
     * @return array<string,array{id:int,size:int,mtime:int}> путь => метаданные
     */
    public function knownFiles(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, path, size_bytes, mtime FROM pdf_files') as $row) {
            $out[(string) $row['path']] = [
                'id' => (int) $row['id'],
                'size' => (int) $row['size_bytes'],
                'mtime' => (int) $row['mtime'],
            ];
        }

        return $out;
    }

    /** Удаляет записи о файлах, которых больше нет на диске (каскадом уходят задания и ZPL). */
    public function forgetMissing(callable $stillExists): int
    {
        $removed = 0;
        foreach ($this->db->fetchAll('SELECT id, path FROM pdf_files') as $row) {
            if (!$stillExists((string) $row['path'])) {
                $this->db->run('DELETE FROM pdf_files WHERE id = ?', [(int) $row['id']]);
                $removed++;
            }
        }

        return $removed;
    }
}
