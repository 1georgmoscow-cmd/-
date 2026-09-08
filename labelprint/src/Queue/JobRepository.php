<?php
declare(strict_types=1);

namespace LabelPrint\Queue;

use LabelPrint\Db\Db;
use LabelPrint\Model\Job;

/**
 * Очередь заданий на рендеринг в MySQL.
 *
 * Захват задания обязан быть атомарным: несколько воркеров работают одновременно,
 * и один PDF не должен уехать в рендер дважды. На MySQL 8 используется
 * SELECT ... FOR UPDATE SKIP LOCKED — воркеры не выстраиваются в очередь на одну
 * и ту же строку, а разбирают разные. На 5.7 применяется UPDATE ... LIMIT 1:
 * сам UPDATE атомарен, а уникальный claim_token позволяет затем безошибочно
 * перечитать именно свою строку.
 */
final class JobRepository
{
    public const STATE_PENDING = 'pending';
    public const STATE_RUNNING = 'running';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';

    private const SELECT_JOB = <<<'SQL'
        SELECT j.id, j.pdf_file_id, j.profile_code, j.profile_fingerprint,
               j.attempts, j.max_attempts, j.claim_token,
               f.path, f.sha256, f.size_bytes
          FROM render_jobs j
          JOIN pdf_files f ON f.id = j.pdf_file_id
        SQL;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Ставит задание в очередь. Повторный вызов для той же пары (файл, профиль) не плодит
     * дубликаты, но возвращает задание в очередь, если изменился отпечаток профиля
     * (значит, параметры рендеринга другие и старый ZPL уже не годится) или если
     * прошлая попытка завершилась ошибкой.
     *
     * @return int id задания
     */
    public function enqueue(
        int $pdfFileId,
        string $profileCode,
        string $profileFingerprint,
        int $priority = 0,
        int $maxAttempts = 3,
    ): int {
        $this->db->run(
            <<<'SQL'
            INSERT INTO render_jobs
                (pdf_file_id, profile_code, profile_fingerprint, state, priority, max_attempts, available_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                state = IF(
                    render_jobs.profile_fingerprint <> VALUES(profile_fingerprint)
                        OR render_jobs.state = 'failed',
                    'pending',
                    render_jobs.state
                ),
                attempts = IF(
                    render_jobs.profile_fingerprint <> VALUES(profile_fingerprint)
                        OR render_jobs.state = 'failed',
                    0,
                    render_jobs.attempts
                ),
                available_at = IF(
                    render_jobs.profile_fingerprint <> VALUES(profile_fingerprint)
                        OR render_jobs.state = 'failed',
                    NOW(),
                    render_jobs.available_at
                ),
                profile_fingerprint = VALUES(profile_fingerprint),
                priority = GREATEST(render_jobs.priority, VALUES(priority)),
                max_attempts = VALUES(max_attempts),
                id = LAST_INSERT_ID(render_jobs.id)
            SQL,
            [$pdfFileId, $profileCode, $profileFingerprint, self::STATE_PENDING, $priority, $maxAttempts],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Атомарно захватывает одно задание. Возвращает null, если брать нечего.
     *
     * @param string $owner        идентификатор воркера (хост:pid)
     * @param int    $leaseSeconds на сколько секунд берётся аренда
     */
    public function claim(string $owner, int $leaseSeconds): ?Job
    {
        $token = bin2hex(random_bytes(16));
        $lease = max(1, $leaseSeconds);

        if ($this->db->supportsSkipLocked()) {
            return $this->claimWithSkipLocked($owner, $token, $lease);
        }

        return $this->claimWithUpdateLimit($owner, $token, $lease);
    }

    private function claimWithSkipLocked(string $owner, string $token, int $lease): ?Job
    {
        return $this->db->transaction(function (Db $db) use ($owner, $token, $lease): ?Job {
            $row = $db->fetchOne(
                <<<'SQL'
                SELECT id
                  FROM render_jobs
                 WHERE state = ?
                   AND available_at <= NOW()
                 ORDER BY priority DESC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED
                SQL,
                [self::STATE_PENDING],
            );

            if ($row === null) {
                return null;
            }

            $id = (int) $row['id'];
            $db->run(
                sprintf(
                    'UPDATE render_jobs
                        SET state = ?, owner = ?, claim_token = ?, attempts = attempts + 1,
                            lease_expires_at = DATE_ADD(NOW(), INTERVAL %d SECOND)
                      WHERE id = ?',
                    $lease,
                ),
                [self::STATE_RUNNING, $owner, $token, $id],
            );

            $job = $db->fetchOne(self::SELECT_JOB . ' WHERE j.id = ?', [$id]);

            return $job === null ? null : Job::fromRow($job);
        });
    }

    private function claimWithUpdateLimit(string $owner, string $token, int $lease): ?Job
    {
        // Сам UPDATE атомарен: строку получит ровно один воркер, остальные увидят rowCount() = 0.
        $stmt = $this->db->run(
            sprintf(
                'UPDATE render_jobs
                    SET state = ?, owner = ?, claim_token = ?, attempts = attempts + 1,
                        lease_expires_at = DATE_ADD(NOW(), INTERVAL %d SECOND)
                  WHERE state = ? AND available_at <= NOW()
                  ORDER BY priority DESC, id ASC
                  LIMIT 1',
                $lease,
            ),
            [self::STATE_RUNNING, $owner, $token, self::STATE_PENDING],
        );

        if ($stmt->rowCount() === 0) {
            return null;
        }

        // Токен уникален, поэтому читаем именно ту строку, которую только что захватили.
        $row = $this->db->fetchOne(self::SELECT_JOB . ' WHERE j.claim_token = ?', [$token]);

        return $row === null ? null : Job::fromRow($row);
    }

    /** Продлевает аренду долгого задания, чтобы его не перехватил другой воркер. */
    public function renewLease(Job $job, int $leaseSeconds): bool
    {
        $stmt = $this->db->run(
            sprintf(
                'UPDATE render_jobs
                    SET lease_expires_at = DATE_ADD(NOW(), INTERVAL %d SECOND)
                  WHERE id = ? AND claim_token = ? AND state = ?',
                max(1, $leaseSeconds),
            ),
            [$job->id, $job->claimToken, self::STATE_RUNNING],
        );

        return $stmt->rowCount() > 0;
    }

    public function complete(Job $job, int $durationMs): void
    {
        $this->db->run(
            'UPDATE render_jobs
                SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                    error_message = NULL, duration_ms = ?
              WHERE id = ? AND claim_token = ?',
            [self::STATE_DONE, $durationMs, $job->id, $job->claimToken],
        );
    }

    /**
     * Помечает задание неуспешным. Пока попытки не исчерпаны — возвращает в очередь
     * с экспоненциальной задержкой (base, base*base, ...), иначе переводит в failed.
     *
     * @return bool true, если задание будет повторено
     */
    public function fail(Job $job, string $error, int $backoffBaseSeconds = 5): bool
    {
        $retry = !$job->isLastAttempt();
        // Задержка растёт как base^attempts, но не больше часа.
        $delay = $retry ? (int) min(3600, $backoffBaseSeconds ** max(1, $job->attempts)) : 0;
        $error = mb_substr($error, 0, 4000);

        if ($retry) {
            $this->db->run(
                sprintf(
                    'UPDATE render_jobs
                        SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                            error_message = ?, available_at = DATE_ADD(NOW(), INTERVAL %d SECOND)
                      WHERE id = ? AND claim_token = ?',
                    $delay,
                ),
                [self::STATE_PENDING, $error, $job->id, $job->claimToken],
            );
        } else {
            $this->db->run(
                'UPDATE render_jobs
                    SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                        error_message = ?
                  WHERE id = ? AND claim_token = ?',
                [self::STATE_FAILED, $error, $job->id, $job->claimToken],
            );
        }

        return $retry;
    }

    /**
     * Возвращает в очередь задания умерших воркеров (аренда истекла).
     *
     * @return int сколько заданий освобождено
     */
    public function releaseExpired(): int
    {
        $stmt = $this->db->run(
            'UPDATE render_jobs
                SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                    error_message = COALESCE(error_message, ?)
              WHERE state = ? AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()',
            [self::STATE_PENDING, 'аренда истекла, задание возвращено в очередь', self::STATE_RUNNING],
        );

        return $stmt->rowCount();
    }

    /** Снимает задания, «зависшие» за конкретным воркером (аккуратная остановка/падение). */
    public function releaseOwner(string $owner): int
    {
        $stmt = $this->db->run(
            'UPDATE render_jobs
                SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL
              WHERE state = ? AND owner = ?',
            [self::STATE_PENDING, self::STATE_RUNNING, $owner],
        );

        return $stmt->rowCount();
    }

    /** Повторно ставит в очередь задания, упавшие насовсем. */
    public function retryFailed(?string $profileCode = null): int
    {
        $sql = 'UPDATE render_jobs
                   SET state = ?, attempts = 0, available_at = NOW(), error_message = NULL
                 WHERE state = ?';
        $params = [self::STATE_PENDING, self::STATE_FAILED];

        if ($profileCode !== null) {
            $sql .= ' AND profile_code = ?';
            $params[] = $profileCode;
        }

        return $this->db->run($sql, $params)->rowCount();
    }

    /** @return array<string,int> состояние => количество */
    public function counts(): array
    {
        $out = [self::STATE_PENDING => 0, self::STATE_RUNNING => 0, self::STATE_DONE => 0, self::STATE_FAILED => 0];
        foreach ($this->db->fetchAll('SELECT state, COUNT(*) AS n FROM render_jobs GROUP BY state') as $row) {
            $out[(string) $row['state']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function recentFailures(int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT j.id, f.path, j.profile_code, j.attempts, j.error_message, j.updated_at
               FROM render_jobs j
               JOIN pdf_files f ON f.id = j.pdf_file_id
              WHERE j.state = ?
              ORDER BY j.updated_at DESC
              LIMIT ' . max(1, $limit),
            [self::STATE_FAILED],
        );
    }
}
