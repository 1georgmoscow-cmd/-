<?php
declare(strict_types=1);

namespace LabelPrint\Model;

/** Задание на рендеринг, захваченное воркером. */
final class Job
{
    public function __construct(
        public readonly int $id,
        public readonly int $pdfFileId,
        public readonly string $path,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        public readonly string $profileCode,
        public readonly string $profileFingerprint,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly string $claimToken,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            pdfFileId: (int) $row['pdf_file_id'],
            path: (string) $row['path'],
            sha256: (string) $row['sha256'],
            sizeBytes: (int) $row['size_bytes'],
            profileCode: (string) $row['profile_code'],
            profileFingerprint: (string) $row['profile_fingerprint'],
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            claimToken: (string) ($row['claim_token'] ?? ''),
        );
    }

    public function isLastAttempt(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }
}
