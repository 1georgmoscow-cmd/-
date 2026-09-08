-- Схема labelprint: очередь рендеринга PDF -> ZPL и хранилище готовых ZPL.
--
-- Применить:  mysql -u root -p < db/schema.mysql.sql
--
-- Требования: MySQL 8.0+ (тогда захват задач идёт через SKIP LOCKED) либо
-- MySQL 5.7 / MariaDB 10.3+ (тогда используется запасной вариант с UPDATE ... LIMIT 1).

CREATE DATABASE IF NOT EXISTS `labelprint`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `labelprint`;

-- ---------------------------------------------------------------------------
-- Исходные PDF, найденные в /upload/pdf
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdf_files` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Путь относительно pdf_dir, например '2026/09/wb-12345.pdf'.
    `path`          VARCHAR(1024)   NOT NULL,
    -- SHA-1 от пути: индексировать VARCHAR(1024) в utf8mb4 нельзя (лимит 3072 байта).
    `path_sha1`     CHAR(40)        NOT NULL,
    -- SHA-256 содержимого: именно он делает кэш контент-адресуемым.
    `sha256`        CHAR(64)        NOT NULL,
    `size_bytes`    BIGINT UNSIGNED NOT NULL,
    `mtime`         INT UNSIGNED    NOT NULL,
    `page_count`    SMALLINT UNSIGNED DEFAULT NULL,
    `first_seen_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pdf_path` (`path_sha1`),
    KEY `idx_pdf_sha256` (`sha256`),
    KEY `idx_pdf_seen` (`first_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Очередь заданий на рендеринг. Одно задание = один PDF под один профиль принтера.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `render_jobs` (
    `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pdf_file_id`          BIGINT UNSIGNED NOT NULL,
    `profile_code`         VARCHAR(64)     NOT NULL,
    -- Отпечаток параметров профиля: если профиль изменили, старый ZPL больше не подходит.
    `profile_fingerprint`  CHAR(16)        NOT NULL,
    `state`                ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
    -- Больше значение — раньше берём в работу.
    `priority`             TINYINT         NOT NULL DEFAULT 0,
    `attempts`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 3,
    -- Момент, раньше которого задание брать нельзя (экспоненциальный backoff).
    `available_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Аренда: если воркер умер, после истечения задание вернётся в очередь.
    `lease_expires_at`     DATETIME        DEFAULT NULL,
    `owner`                VARCHAR(64)     DEFAULT NULL,
    -- Уникальный токен захвата: позволяет надёжно перечитать своё задание
    -- в запасном варианте без SKIP LOCKED.
    `claim_token`          CHAR(32)        DEFAULT NULL,
    `error_message`        TEXT            DEFAULT NULL,
    `duration_ms`          INT UNSIGNED    DEFAULT NULL,
    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Один PDF под один профиль рендерим один раз.
    UNIQUE KEY `uk_job_file_profile` (`pdf_file_id`, `profile_code`),
    -- Рабочий индекс захвата: сначала фильтр по состоянию, затем по времени доступности.
    KEY `idx_job_claim` (`state`, `available_at`, `priority`, `id`),
    KEY `idx_job_lease` (`state`, `lease_expires_at`),
    KEY `idx_job_token` (`claim_token`),
    CONSTRAINT `fk_job_pdf` FOREIGN KEY (`pdf_file_id`) REFERENCES `pdf_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Готовый ZPL. Одна строка = одна страница PDF под один профиль.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zpl_labels` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pdf_file_id`         BIGINT UNSIGNED NOT NULL,
    -- Дублируем хэш содержимого: делает кэш контент-адресуемым и переживает
    -- переименование или повторную загрузку того же файла под другим именем.
    `pdf_sha256`          CHAR(64)        NOT NULL,
    `profile_code`        VARCHAR(64)     NOT NULL,
    `profile_fingerprint` CHAR(16)        NOT NULL,
    `page_no`             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `dpi`                 SMALLINT UNSIGNED NOT NULL,
    `width_dots`          SMALLINT UNSIGNED NOT NULL,
    `height_dots`         SMALLINT UNSIGNED NOT NULL,
    `compression`         ENUM('hex','acs','z64') NOT NULL,
    -- Готовые к отправке байты ^XA...^XZ. BLOB, а не TEXT: ZPL — это ASCII-поток,
    -- ему не нужны ни кодировка, ни collation, и BLOB гарантирует побайтовую сохранность.
    -- MEDIUMBLOB = до 16 МБ; этикетка 100x150 мм при 203 dpi занимает 15-60 КБ со сжатием ACS.
    `zpl`                 MEDIUMBLOB      NOT NULL,
    `zpl_bytes`           INT UNSIGNED    NOT NULL,
    `zpl_sha256`          CHAR(64)        NOT NULL,
    -- Доля чёрного: близко к 0 — пустая этикетка, близко к 1 — вероятная инверсия.
    `ink_coverage`        FLOAT           DEFAULT NULL,
    `render_ms`           INT UNSIGNED    DEFAULT NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Ключ кэша: содержимое PDF + параметры профиля + номер страницы.
    UNIQUE KEY `uk_label_cache` (`pdf_sha256`, `profile_fingerprint`, `page_no`),
    KEY `idx_label_file` (`pdf_file_id`, `page_no`),
    KEY `idx_label_profile` (`profile_code`, `created_at`),
    CONSTRAINT `fk_label_pdf` FOREIGN KEY (`pdf_file_id`) REFERENCES `pdf_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Настройки сервера, которые нужно проверить
-- ---------------------------------------------------------------------------
-- В /etc/mysql/mysql.conf.d/mysqld.cnf:
--   [mysqld]
--   max_allowed_packet = 64M      # BLOB с ZPL уходит одним пакетом
--   innodb_log_file_size = 256M   # запись должна помещаться в лог с запасом
--   wait_timeout = 28800          # воркер долгоживущий; Db переподключается сам
--
-- Проверить:  SHOW VARIABLES LIKE 'max_allowed_packet';
--
-- Пользователь для сервиса:
--   CREATE USER 'labelprint'@'localhost' IDENTIFIED BY 'смените-пароль';
--   GRANT SELECT, INSERT, UPDATE, DELETE ON labelprint.* TO 'labelprint'@'localhost';
--   FLUSH PRIVILEGES;
