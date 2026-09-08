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
    /**
     * Тупик: попытки исчерпаны. Отдельно от failed, потому что failed можно
     * массово вернуть в очередь, а dead требует вмешательства человека.
     */
    public const STATE_DEAD = 'dead';

    private const SELECT_JOB = <<<'SQL'
        SELECT j.id, j.pdf_file_id, j.profile_code, j.profile_fingerprint,
               j.attempts, j.max_attempts, j.claim_token,
               f.path, f.sha256, f.size_bytes, f.page_count
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
        /**
         * Содержимое файла изменилось с прошлого раза. Без этого флага PDF,
         * перезаписанный по тому же пути (обычное дело: оператор заметил ошибку
         * в адресе и положил исправленный файл), никогда не рендерится заново:
         * отпечаток профиля тот же, состояние done — и задание остаётся done
         * навсегда, а потребитель печатает устаревшую этикетку.
         */
        bool $contentChanged = false,
    ): int {
        // Условие повтора: либо изменились параметры профиля, либо изменилось
        // содержимое файла, либо прошлая попытка упала. Значение подставляется
        // прямо в текст запроса (это строго 0 или 1), а не через переменную сессии:
        // переменная не пережила бы переподключение между двумя запросами и повтор
        // молча не сработал бы.
        $requeue = sprintf(
            '(render_jobs.profile_fingerprint <> VALUES(profile_fingerprint)'
            . ' OR render_jobs.state = \'failed\' OR %d = 1)',
            $contentChanged ? 1 : 0,
        );

        $this->db->run(
            str_replace('@requeue', $requeue, <<<'SQL'
            INSERT INTO render_jobs
                (pdf_file_id, profile_code, profile_fingerprint, state, priority, max_attempts, available_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                state = IF(@requeue, 'pending', render_jobs.state),
                attempts = IF(@requeue, 0, render_jobs.attempts),
                available_at = IF(@requeue, NOW(), render_jobs.available_at),
                -- Аренду тоже надо снять. Иначе воркер, который прямо сейчас
                -- рендерит эту строку под старым отпечатком, успешно завершит
                -- задание своим claim_token и вернёт его в done, стерев повтор.
                owner = IF(@requeue, NULL, render_jobs.owner),
                claim_token = IF(@requeue, NULL, render_jobs.claim_token),
                lease_expires_at = IF(@requeue, NULL, render_jobs.lease_expires_at),
                profile_fingerprint = VALUES(profile_fingerprint),
                priority = GREATEST(render_jobs.priority, VALUES(priority)),
                max_attempts = VALUES(max_attempts),
                id = LAST_INSERT_ID(render_jobs.id)
            SQL),
            [$pdfFileId, $profileCode, $profileFingerprint, self::STATE_PENDING, $priority, $maxAttempts],
            idempotent: true,
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
                   AND attempts < max_attempts
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
                  WHERE state = ? AND available_at <= NOW() AND attempts < max_attempts
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

    /**
     * Продлевает аренду долгого задания. Возвращает false, только если задание
     * действительно перестало принадлежать этому воркеру.
     *
     * Отдельная проверка нужна из-за семантики rowCount(). PDO отдаёт число
     * ИЗМЕНЁННЫХ строк, а не совпавших с условием. Колонка lease_expires_at имеет
     * тип DATETIME, то есть точность в одну секунду, и NOW() — это время начала
     * запроса. Поэтому продление в ту же секунду, в которую аренда была записана
     * при захвате, вычисляет ровно то же значение, InnoDB считает строку
     * неизменённой и возвращает ноль. Обычная одностраничная этикетка рендерится
     * за 60 мс, то есть в ту же секунду, — и воркер сам себе объявлял бы, что
     * задание украли, бросал исключение и терял работу. Многостраничный файл при
     * этом обрывался на первой странице, а задание помечалось выполненным.
     */
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
            idempotent: true,
        );

        if ($stmt->rowCount() > 0) {
            return true;
        }

        // Ноль изменённых строк означает либо «значение и так было таким»,
        // либо «строка больше не наша». Различаем это явным чтением.
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM render_jobs WHERE id = ? AND claim_token = ? AND state = ?',
            [$job->id, $job->claimToken, self::STATE_RUNNING],
        );

        return $row !== null;
    }

    public function complete(Job $job, int $durationMs): void
    {
        $this->db->run(
            'UPDATE render_jobs
                SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                    error_message = NULL, duration_ms = ?
              WHERE id = ? AND claim_token = ? AND state = ?',
            [self::STATE_DONE, $durationMs, $job->id, $job->claimToken, self::STATE_RUNNING],
            idempotent: true,
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
        $delay = $retry ? self::backoffSeconds($backoffBaseSeconds, $job->attempts) : 0;
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
                idempotent: true,
            );
        } else {
            $this->db->run(
                'UPDATE render_jobs
                    SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                        error_message = ?
                  WHERE id = ? AND claim_token = ? AND state = ?',
                [self::STATE_FAILED, $error, $job->id, $job->claimToken, self::STATE_RUNNING],
                idempotent: true,
            );
        }

        return $retry;
    }

    /**
     * Экспоненциальная задержка перед повтором: base, 2*base, 4*base, ... но не больше часа.
     *
     * Разброс в 20 процентов обязателен. Без него все задания, упавшие во время
     * недоступности базы или принтера, повторяются строго одновременно и кладут
     * систему повторно ровно в тот момент, когда она поднялась.
     */
    public static function backoffSeconds(int $base, int $attempt, ?float $jitter = null): int
    {
        $base = max(1, $base);
        $delay = min(3600, $base * (2 ** max(0, $attempt - 1)));
        $jitter ??= random_int(-20, 20) / 100;

        return max(1, (int) round($delay * (1 + $jitter)));
    }

    /**
     * Разбирает задания умерших воркеров (аренда истекла).
     *
     * Различаются два исхода. Если попытки ещё есть, задание возвращается в очередь.
     * Если исчерпаны — уходит в dead. Без второй ветки получается «отравленное»
     * задание: PDF, на котором воркер не бросает исключение, а ПАДАЕТ (нехватка памяти,
     * SIGKILL от OOM-killer, сегфолт в библиотеке), никогда не доходит до fail(),
     * его аренда истекает, оно снова становится pending и убивает следующий воркер.
     * И так по кругу, вечно.
     *
     * @return array{released:int,dead:int}
     */
    public function releaseExpired(): array
    {
        $expired = 'state = ? AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()';

        // Сначала тупиковые: попытки исчерпаны, воркер до fail() не дожил.
        $dead = $this->db->run(
            "UPDATE render_jobs
                SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                    error_message = CONCAT(?, COALESCE(error_message, '—'))
              WHERE {$expired} AND attempts >= max_attempts",
            [
                self::STATE_DEAD,
                'Попытки исчерпаны, а воркер каждый раз завершался аварийно, не оставив '
                . 'сообщения об ошибке (нехватка памяти, SIGKILL, сбой в библиотеке). '
                . 'Задание снято с очереди. Последнее известное сообщение: ',
                self::STATE_RUNNING,
            ],
            idempotent: true,
        )->rowCount();

        $released = $this->db->run(
            "UPDATE render_jobs
                SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL,
                    error_message = COALESCE(error_message, ?),
                    available_at = DATE_ADD(NOW(), INTERVAL 5 SECOND)
              WHERE {$expired}",
            [self::STATE_PENDING, 'аренда истекла, задание возвращено в очередь', self::STATE_RUNNING],
            idempotent: true,
        )->rowCount();

        return ['released' => $released, 'dead' => $dead];
    }

    /** Снимает задания, «зависшие» за конкретным воркером (аккуратная остановка/падение). */
    public function releaseOwner(string $owner): int
    {
        $stmt = $this->db->run(
            'UPDATE render_jobs
                SET state = ?, owner = NULL, claim_token = NULL, lease_expires_at = NULL
              WHERE state = ? AND owner = ?',
            [self::STATE_PENDING, self::STATE_RUNNING, $owner],
            idempotent: true,
        );

        return $stmt->rowCount();
    }

    /**
     * Повторно ставит в очередь упавшие задания. Тупиковые (dead) включаются
     * только явным флагом: их уронил не сбой, а сам файл.
     */
    public function retryFailed(?string $profileCode = null, bool $includeDead = false): int
    {
        $sql = 'UPDATE render_jobs
                   SET state = ?, attempts = 0, available_at = NOW(), error_message = NULL
                 WHERE state ' . ($includeDead ? 'IN (?, ?)' : '= ?');
        $params = $includeDead
            ? [self::STATE_PENDING, self::STATE_FAILED, self::STATE_DEAD]
            : [self::STATE_PENDING, self::STATE_FAILED];

        if ($profileCode !== null) {
            $sql .= ' AND profile_code = ?';
            $params[] = $profileCode;
        }

        return $this->db->run($sql, $params, idempotent: true)->rowCount();
    }

    /** @return array<string,int> состояние => количество */
    public function counts(): array
    {
        $out = [
            self::STATE_PENDING => 0,
            self::STATE_RUNNING => 0,
            self::STATE_DONE => 0,
            self::STATE_FAILED => 0,
            self::STATE_DEAD => 0,
        ];
        foreach ($this->db->fetchAll('SELECT state, COUNT(*) AS n FROM render_jobs GROUP BY state') as $row) {
            $out[(string) $row['state']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function recentFailures(int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT j.id, f.path, j.profile_code, j.state, j.attempts, j.error_message, j.updated_at
               FROM render_jobs j
               JOIN pdf_files f ON f.id = j.pdf_file_id
              WHERE j.state IN (?, ?)
              ORDER BY j.updated_at DESC
              LIMIT ' . max(1, $limit),
            [self::STATE_FAILED, self::STATE_DEAD],
        );
    }
}
