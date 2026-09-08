<?php
declare(strict_types=1);

namespace LabelPrint\Db;

use LabelPrint\Support\Config;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Обёртка над PDO для долгоживущего воркера.
 *
 * Главная проблема демона: соединение простаивает дольше wait_timeout, и MySQL его рвёт —
 * следующий запрос падает с «MySQL server has gone away» (2006) или «Lost connection» (2013).
 * Здесь такие ошибки распознаются и соединение переустанавливается ровно один раз,
 * но только если мы не внутри транзакции (иначе молчаливый ретрай потерял бы её часть).
 */
final class Db
{
    private const GONE_AWAY_CODES = [2006, 2013, 4031];

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        /** @var array<int,mixed> */
        private readonly array $options = [],
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->string('db.dsn'),
            $config->string('db.user'),
            $config->string('db.password', ''),
            $config->array('db.options'),
        );
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        /** @var PDO */
        return $this->pdo;
    }

    /**
     * Выполняет запрос, при обрыве соединения переподключается и повторяет один раз.
     *
     * @param array<string|int,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (PDOException $e) {
            if (!$this->isConnectionLost($e) || $this->inTransaction()) {
                throw $e;
            }

            $this->pdo = null;
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        }
    }

    /**
     * @param  array<string|int,mixed>       $params
     * @return array<string,mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param  array<string|int,mixed>  $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    public function inTransaction(): bool
    {
        return $this->pdo !== null && $this->pdo->inTransaction();
    }

    /**
     * Транзакция с автоматическим откатом при исключении.
     *
     * @template T
     * @param  callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $fn($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Пингует соединение; false — значит связь потеряна и восстановить не удалось. */
    public function ping(): bool
    {
        try {
            $this->run('SELECT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /** Версия сервера — нужна, чтобы выбрать между SKIP LOCKED (8.0+) и запасным вариантом. */
    public function serverVersion(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function supportsSkipLocked(): bool
    {
        $version = $this->serverVersion();

        // MariaDB часто отдаёт версию с легаси-префиксом: «5.5.5-10.6.12-MariaDB».
        // Его надо срезать, иначе версия читается как 5.5 и SKIP LOCKED считается
        // недоступным, хотя он есть. В MariaDB SKIP LOCKED появился в 10.6.
        if (stripos($version, 'mariadb') !== false) {
            $version = preg_replace('/^5\.5\.5-/', '', $version) ?? $version;

            return preg_match('/(\d+)\.(\d+)/', $version, $m) === 1
                && ((int) $m[1] > 10 || ((int) $m[1] === 10 && (int) $m[2] >= 6));
        }

        return version_compare($version, '8.0.1', '>=');
    }

    private function connect(): void
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Настоящие подготовленные выражения: нужны, чтобы большие BLOB уходили
            // отдельным пакетом, а не подставлялись в текст запроса.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ] + $this->options;

        $this->pdo = new PDO($this->dsn, $this->user, $this->password, $options);
    }

    private function isConnectionLost(PDOException $e): bool
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if (in_array($driverCode, self::GONE_AWAY_CODES, true)) {
            return true;
        }

        return str_contains($e->getMessage(), 'server has gone away')
            || str_contains($e->getMessage(), 'Lost connection');
    }
}
