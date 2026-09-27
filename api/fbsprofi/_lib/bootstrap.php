<?php
declare(strict_types=1);

/*
 * Общие функции для ручек FBSPROFI:
 * настройки, JSON-ответы, чтение входных данных, запросы к сайту, сессии.
 */

// ---------------- НАСТРОЙКИ ----------------
function config(string $key): string
{
    static $env = null;
    if ($env === null) {
        $file = dirname(__DIR__) . '/.env';
        $env = is_file($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
    }

    $defaults = [
        // По умолчанию — тот же хост, на котором работает API (https://{SERVER_NAME}).
        'FBSPROFI_BASE_URL'       => '',
        'FBSPROFI_LOGIN_PATH'     => '/auth/submit_log.php',
        'FBSPROFI_LOGIN_FIELD'    => 'login',
        'FBSPROFI_PASSWORD_FIELD' => 'password',
        // префикс пути после входа => кабинет
        'FBSPROFI_CABINETS'       => '/owner=owner,/accounting=accounting,/client=client',
        'FBSPROFI_SESSION_TTL'    => '86400',
        'FBSPROFI_TIMEOUT'        => '15',
    ];

    $value = getenv($key);
    if ($value === false) {
        $value = $env[$key] ?? $defaults[$key] ?? '';
    }
    return (string)$value;
}

// ---------------- ОТВЕТЫ ----------------
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $code, string $message, int $status): never
{
    json_response(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        json_error('method_not_allowed', "Используйте метод $method", 405);
    }
}

// Данные запроса: form-data / x-www-form-urlencoded или JSON-тело.
function request_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

// ---------------- ЗАПРОСЫ К САЙТУ ----------------
/**
 * @return array{status:int, url:string, body:string}
 */
function site_request(string $method, string $url, string $cookieJar, array $form = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => (int)config('FBSPROFI_TIMEOUT'),
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_USERAGENT      => 'FBSPROFI-API/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/html'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        json_error('site_unavailable', "Сайт недоступен: $error", 502);
    }

    $result = [
        'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'url'    => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'body'   => (string)$body,
    ];
    curl_close($ch);
    return $result;
}

function site_url(string $pathOrUrl, ?string $base = null): string
{
    if (preg_match('~^https?://~i', $pathOrUrl)) {
        return $pathOrUrl;
    }
    $base = $base ?? config('FBSPROFI_BASE_URL');
    if ($base === '') {
        // Тот же хост, на котором работает API: https://{SERVER_NAME}
        $host = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = 'https://' . $host;
    }
    $base = rtrim($base, '/');
    if ($pathOrUrl === '') {
        return $base;
    }
    return $base . '/' . ltrim($pathOrUrl, '/');
}

function html_xpath(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    return new DOMXPath($doc);
}

// ---------------- СЕССИИ ----------------
function sessions_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function session_create(array $meta, string $cookieJar): string
{
    $token = bin2hex(random_bytes(32));
    $dir = sessions_dir();

    rename($cookieJar, "$dir/$token.cookies");
    chmod("$dir/$token.cookies", 0600);

    $meta['expires_at'] = time() + (int)config('FBSPROFI_SESSION_TTL');
    file_put_contents("$dir/$token.json", json_encode($meta, JSON_UNESCAPED_UNICODE), LOCK_EX);
    chmod("$dir/$token.json", 0600);

    return $token;
}
