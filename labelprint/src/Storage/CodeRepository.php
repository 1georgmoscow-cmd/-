<?php
declare(strict_types=1);

namespace LabelPrint\Storage;

use LabelPrint\Barcode\DecodedCode;
use LabelPrint\Db\Db;

/**
 * Коды, распознанные на этикетках.
 *
 * Ради этой таблицы всё и затевалось: оператор наклеивает этикетку и сканирует её
 * ручным сканером, а система сверяет считанное с тем, что должно быть напечатано.
 */
final class CodeRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Перезаписывает набор кодов для этикетки.
     *
     * Именно перезаписывает: при повторном рендеринге старые коды должны исчезнуть,
     * иначе сверка примет значение, которого на новой этикетке уже нет.
     *
     * @param  list<DecodedCode> $codes
     * @return int сколько кодов записано
     */
    public function replaceForLabel(
        int $zplLabelId,
        string $pdfSha256,
        string $profileFingerprint,
        int $pageNo,
        array $codes,
    ): int {
        return $this->db->transaction(function (Db $db) use ($zplLabelId, $pdfSha256, $profileFingerprint, $pageNo, $codes): int {
            $db->run('DELETE FROM label_codes WHERE zpl_label_id = ?', [$zplLabelId]);

            $written = 0;
            foreach ($codes as $code) {
                $normalized = $code->normalized();
                $box = $code->box ?? [null, null, null, null];

                $db->run(
                    'INSERT INTO label_codes
                        (zpl_label_id, pdf_sha256, profile_fingerprint, page_no, symbology, reader,
                         value, value_sha1, value_normalized, normalized_sha1, quality,
                         box_x, box_y, box_w, box_h)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        symbology = VALUES(symbology), reader = VALUES(reader),
                        value_normalized = VALUES(value_normalized),
                        normalized_sha1 = VALUES(normalized_sha1), quality = VALUES(quality),
                        box_x = VALUES(box_x), box_y = VALUES(box_y),
                        box_w = VALUES(box_w), box_h = VALUES(box_h)',
                    [
                        $zplLabelId,
                        $pdfSha256,
                        $profileFingerprint,
                        $pageNo,
                        $code->symbology,
                        $code->reader,
                        $code->value,
                        sha1($code->value),
                        $normalized,
                        sha1($normalized),
                        $code->quality,
                        $box[0],
                        $box[1],
                        $box[2],
                        $box[3],
                    ],
                );
                $written++;
            }

            return $written;
        });
    }

    /**
     * Коды конкретной этикетки.
     *
     * @return list<array<string,mixed>>
     */
    public function forLabel(int $zplLabelId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM label_codes WHERE zpl_label_id = ? ORDER BY id',
            [$zplLabelId],
        );
    }

    /**
     * Сверка: содержит ли этикетка то, что считал сканер.
     *
     * Сравнение идёт и по исходным байтам, и по нормализованному виду. Ручные
     * сканеры по-разному обходятся с управляющими байтами (в «Честном знаке» это
     * разделитель GS): одни отдают их как есть, другие выбрасывают. Сравнение
     * только по одному варианту давало бы ложные несовпадения.
     */
    public function matches(int $zplLabelId, string $scanned): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM label_codes
              WHERE zpl_label_id = ? AND (value_sha1 = ? OR normalized_sha1 = ?)
              LIMIT 1',
            [$zplLabelId, sha1($scanned), sha1(DecodedCode::normalize($scanned))],
        );

        return $row !== null;
    }

    /**
     * Поиск этикетки по считанному коду — обратное направление сверки.
     *
     * @return list<array<string,mixed>>
     */
    public function findByValue(string $scanned, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT c.zpl_label_id, c.symbology, c.page_no, f.path, l.profile_code
               FROM label_codes c
               JOIN zpl_labels l ON l.id = c.zpl_label_id
               JOIN pdf_files f ON f.id = l.pdf_file_id
              WHERE c.value_sha1 = ? OR c.normalized_sha1 = ?
              ORDER BY c.id DESC
              LIMIT ' . max(1, $limit),
            [sha1($scanned), sha1(DecodedCode::normalize($scanned))],
        );
    }

    /** @return array{codes:int,labels_with_codes:int,by_symbology:array<string,int>} */
    public function stats(): array
    {
        $bySymbology = [];
        foreach ($this->db->fetchAll('SELECT symbology, COUNT(*) AS n FROM label_codes GROUP BY symbology') as $row) {
            $bySymbology[(string) $row['symbology']] = (int) $row['n'];
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS codes, COUNT(DISTINCT zpl_label_id) AS labels FROM label_codes',
        ) ?? [];

        return [
            'codes' => (int) ($row['codes'] ?? 0),
            'labels_with_codes' => (int) ($row['labels'] ?? 0),
            'by_symbology' => $bySymbology,
        ];
    }
}
