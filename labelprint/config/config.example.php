<?php
declare(strict_types=1);

/**
 * Скопируйте в config/config.php и поправьте под сервер.
 * Любой параметр можно переопределить переменной окружения:
 *   db.dsn -> LABELPRINT_DB_DSN, worker.max_jobs -> LABELPRINT_WORKER_MAX_JOBS
 */

return [
    // Каталог, куда складываются исходные PDF.
    'pdf_dir' => '/upload/pdf',

    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=labelprint;charset=utf8mb4',
        'user' => 'labelprint',
        'password' => 'change-me',
        'options' => [],
    ],

    // Путь к Ghostscript. Проверить: which gs
    'ghostscript' => '/usr/bin/gs',

    // Необязательно: pdfinfo/pdfimages из poppler-utils ускоряют разбор PDF.
    'pdfinfo' => '/usr/bin/pdfinfo',

    'render' => [
        // Жёсткий таймаут на один вызов Ghostscript, секунды.
        'timeout' => 30,
        // Рендерить в 8-битный серый и бинаризовать самим (точный контроль порога,
        // штрихкоды не размываются полутоном). false — просить у gs сразу 1 бит.
        'grayscale_threshold' => true,
        // Максимальный размер растра в точках — предохранитель от «бомб» в PDF.
        'max_dots' => 40_000_000,
    ],

    'worker' => [
        // Пауза, когда очередь пуста, миллисекунды.
        'poll_interval_ms' => 250,
        // Рестарт процесса после N задач — простейшая защита от утечек памяти.
        'max_jobs' => 500,
        // Рестарт по времени жизни, секунды.
        'max_lifetime_sec' => 3600,
        // Сколько раз пробуем задачу перед переводом в failed.
        'max_attempts' => 3,
        // Аренда задачи: если воркер умер, через столько секунд задачу заберёт другой.
        'lease_sec' => 120,
        // Базовая задержка экспоненциального backoff, секунды (5, 25, 125...).
        'backoff_base_sec' => 5,
    ],

    'scanner' => [
        // Интервал обхода каталога, миллисекунды (используется, если нет inotify).
        'interval_ms' => 1000,
        // Файл считается дописанным, если его размер и mtime не менялись столько раз подряд.
        'stable_checks' => 2,
        // Игнорировать файлы, изменённые менее N секунд назад (страховка от долитых частями).
        'min_age_sec' => 1,
        'extensions' => ['pdf'],
        // Рекурсивный обход подкаталогов /upload/pdf.
        'recursive' => true,
    ],

    // Профиль, под который рендерим, если в задаче не указан другой.
    'default_profile' => 'zebra_203_100x150',

    'log' => [
        'level' => 'info',
        // null — писать в STDERR (под systemd попадёт в journald)
        'file' => null,
        'json' => false,
    ],
];
