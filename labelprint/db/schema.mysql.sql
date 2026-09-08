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
    -- Именно VARBINARY, а не VARCHAR: имена файлов в Linux — это байты без гарантии
    -- кодировки. PDF, пришедший по FTP с именем в CP1251, невалиден как UTF-8, и
    -- VARCHAR(1024) utf8mb4 отверг бы его с ошибкой 1366 — файл никогда бы не попал
    -- в очередь. VARBINARY хранит байты как есть.
    `path`          VARBINARY(1024) NOT NULL,
    -- SHA-1 от пути: короткий ключ уникальности вместо индекса на 1024 байта.
    `path_sha1`     CHAR(40)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- SHA-256 содержимого: именно он делает кэш контент-адресуемым.
    -- ascii_bin вместо utf8mb4: 64 байта на значение вместо 256, вчетверо меньше индекс.
    `sha256`        CHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
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
    `profile_code`         VARCHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Отпечаток параметров профиля: если профиль изменили, старый ZPL больше не подходит.
    `profile_fingerprint`  CHAR(16)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- dead — попытки исчерпаны, задание больше не берётся в работу.
    -- Отдельно от failed: failed можно вернуть в очередь пачкой, dead требует разбора.
    `state`                ENUM('pending','running','done','failed','dead') NOT NULL DEFAULT 'pending',
    -- Больше значение — раньше берём в работу.
    `priority`             TINYINT         NOT NULL DEFAULT 0,
    `attempts`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 3,
    -- Момент, раньше которого задание брать нельзя (экспоненциальный backoff).
    `available_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Аренда: если воркер умер, после истечения задание вернётся в очередь.
    `lease_expires_at`     DATETIME        DEFAULT NULL,
    `owner`                VARCHAR(64)     CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    -- Уникальный токен захвата: позволяет надёжно перечитать своё задание
    -- в запасном варианте без SKIP LOCKED.
    `claim_token`          CHAR(32)        CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    `error_message`        TEXT            DEFAULT NULL,
    `duration_ms`          INT UNSIGNED    DEFAULT NULL,
    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Один PDF под один профиль рендерим один раз.
    UNIQUE KEY `uk_job_file_profile` (`pdf_file_id`, `profile_code`),
    -- Индекс повторяет ORDER BY запроса захвата: state — константа, дальше
    -- priority DESC, затем id ASC. Тогда строки читаются уже в нужном порядке
    -- и запрос останавливается на первой подходящей, без сортировки всего набора.
    -- available_at сюда намеренно НЕ входит: как диапазонное условие он оборвал бы
    -- использование индекса для сортировки, и каждый захват делал бы filesort.
    -- Он остаётся обычным фильтром — отложенных заданий в очереди обычно единицы.
    -- (В MySQL 5.7 ключевое слово DESC разбирается, но игнорируется: там сортировка
    -- останется, что при небольшой очереди несущественно.)
    KEY `idx_job_claim` (`state`, `priority` DESC, `id`),
    KEY `idx_job_lease` (`state`, `lease_expires_at`),
    -- Уникальный: два задания не могут нести один токен захвата, и это гарантирует,
    -- что перечитывание своего задания в запасном варианте не найдёт чужое.
    UNIQUE KEY `uk_job_token` (`claim_token`),
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
    `pdf_sha256`          CHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `profile_code`        VARCHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `profile_fingerprint` CHAR(16)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `page_no`             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `dpi`                 SMALLINT UNSIGNED NOT NULL,
    `width_dots`          SMALLINT UNSIGNED NOT NULL,
    `height_dots`         SMALLINT UNSIGNED NOT NULL,
    `compression`         ENUM('hex','acs','z64') NOT NULL,
    -- Готовые к отправке байты ^XA...^XZ. BLOB, а не TEXT: ZPL — это ASCII-поток,
    -- ему не нужны ни кодировка, ни collation, и BLOB гарантирует побайтовую сохранность.
    -- MEDIUMBLOB = до 16 МБ. Замеры: обычная этикетка 100x150 при 203 dpi — 15 КБ (ACS)
    -- или 5 КБ (Z64); худший реальный случай, полностраничный растр с полутоном, — 227 КБ;
    -- теоретический потолок ACS равен удвоенному размеру растра, то есть 2,2 МБ даже для
    -- ошибочно загруженного A4 при 300 dpi. Запас семикратный, LONGBLOB не нужен.
    `zpl`                 MEDIUMBLOB      NOT NULL,
    `zpl_bytes`           INT UNSIGNED    NOT NULL,
    `zpl_sha256`          CHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Доля чёрного: близко к 0 — пустая этикетка, близко к 1 — вероятная инверсия.
    `ink_coverage`        FLOAT           DEFAULT NULL,
    `render_ms`           INT UNSIGNED    DEFAULT NULL,
    -- created_at не трогается при перерендере: по нему видно, когда этикетка появилась.
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Ключ кэша: содержимое PDF + параметры профиля + номер страницы.
    UNIQUE KEY `uk_label_cache` (`pdf_sha256`, `profile_fingerprint`, `page_no`),
    KEY `idx_label_file` (`pdf_file_id`, `page_no`),
    KEY `idx_label_profile` (`profile_code`, `created_at`),
    CONSTRAINT `fk_label_pdf` FOREIGN KEY (`pdf_file_id`) REFERENCES `pdf_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Коды, распознанные на готовых этикетках
--
-- Ради этой таблицы и делалось распознавание: оператор наклеивает этикетку,
-- сканирует её ручным сканером, а система сверяет считанное с тем, что
-- действительно напечатано.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `label_codes` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `zpl_label_id`        BIGINT UNSIGNED NOT NULL,
    `pdf_sha256`          CHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `profile_fingerprint` CHAR(16)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `page_no`             SMALLINT UNSIGNED NOT NULL,
    -- Как называет символику декодер: QR-Code, CODE-128, EAN-13, DataMatrix.
    `symbology`           VARCHAR(24)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Кто распознал: zbar или dmtx.
    `reader`              VARCHAR(16)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- ИМЕННО VARBINARY. Код «Честного знака» содержит разделители GS (0x1D),
    -- а QR может нести любые байты. Текстовая колонка с кодировкой отвергла бы
    -- такое значение или молча его исказила — и сверка со сканером перестала бы работать.
    `value`               VARBINARY(4096) NOT NULL,
    `value_sha1`          CHAR(40)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- То же значение без управляющих байтов: часть ручных сканеров выбрасывает GS,
    -- часть отдаёт как есть. Храним оба вида, чтобы сверка работала при любой настройке.
    `value_normalized`    VARBINARY(4096) NOT NULL,
    `normalized_sha1`     CHAR(40)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    -- Оценка уверенности от zbar. Низкая на растре, который уедет на принтер,
    -- означает, что и сканер на складе, скорее всего, код не возьмёт.
    `quality`             SMALLINT UNSIGNED DEFAULT NULL,
    -- Где код расположен на этикетке, в точках — для разбора проблем.
    `box_x`               SMALLINT UNSIGNED DEFAULT NULL,
    `box_y`               SMALLINT UNSIGNED DEFAULT NULL,
    `box_w`               SMALLINT UNSIGNED DEFAULT NULL,
    `box_h`               SMALLINT UNSIGNED DEFAULT NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code_label_value` (`zpl_label_id`, `value_sha1`),
    -- Обратное направление сверки: по считанному коду найти этикетку.
    KEY `idx_code_value` (`value_sha1`),
    KEY `idx_code_normalized` (`normalized_sha1`),
    KEY `idx_code_page` (`pdf_sha256`, `profile_fingerprint`, `page_no`),
    CONSTRAINT `fk_code_label` FOREIGN KEY (`zpl_label_id`) REFERENCES `zpl_labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Настройки сервера, которые нужно проверить
-- ---------------------------------------------------------------------------
-- В /etc/mysql/mysql.conf.d/mysqld.cnf:
--   [mysqld]
--   max_allowed_packet = 64M          # в MySQL 8 это уже значение по умолчанию,
--                                     # но Debian/Ubuntu кладут свои файлы конфигурации,
--                                     # поэтому лучше задать явно
--   innodb_redo_log_capacity = 512M   # в MySQL 8.0.30+ пришло на смену innodb_log_file_size;
--                                     # меняется на лету через SET GLOBAL
--   wait_timeout = 28800              # воркер долгоживущий; Db переподключается сам
--
-- Проверить:  SHOW VARIABLES LIKE 'max_allowed_packet';
--
-- Если реплики нет, двоичный лог удваивает объём записи: при binlog_format=ROW
-- каждый ZPL пишется и в таблицу, и в binlog, который по умолчанию хранится 30 дней.
-- Отключается через skip-log-bin, либо сокращается binlog_expire_logs_seconds.
--
-- Пользователь для сервиса:
--   CREATE USER 'labelprint'@'localhost' IDENTIFIED BY 'смените-пароль';
--   GRANT SELECT, INSERT, UPDATE, DELETE ON labelprint.* TO 'labelprint'@'localhost';
--   FLUSH PRIVILEGES;
