<?php
declare(strict_types=1);

/*
 * POST /api/fbsprofi/auth/login.php
 *
 * Вход логином и паролем для тех, у кого нет бейджа:
 * владелец фулфилмента, бухгалтерия, клиент.
 * Отправляет логин/пароль на форму входа сайта, определяет кабинет
 * по адресу, куда сайт перенаправил, и выдаёт токен для остальных ручек.
 *
 * Сайт: POST https://{SERVER_NAME}/auth/submit_log.php  (login=...&password=...)
 *
 * Тело запроса: login, password (form-data, x-www-form-urlencoded или JSON)
 *
 * 200 {"ok":true,"token":"...","cabinet":"owner|accounting|client","expires_at":...}
 * 400 не передан логин или пароль
 * 401 неверный логин или пароль
 * 405 не POST
 * 502 сайт недоступен или кабинет не распознан
 */

require __DIR__ . '/../_lib/bootstrap.php';

require_method('POST');

$input = request_input();
$login = trim((string)($input['login'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($login === '' || $password === '') {
    json_error('validation_error', 'Укажите логин и пароль', 400);
}

$cookieJar = tempnam(sessions_dir(), 'login_');

// json_response() завершает скрипт через exit, поэтому чистим временный файл
// в shutdown-обработчике. При успехе он уже перенесён в сессию — is_file() ложно.
register_shutdown_function(static function () use ($cookieJar): void {
    if (is_file($cookieJar)) {
        unlink($cookieJar);
    }
});

// ----------- ОТПРАВКА ЛОГИНА И ПАРОЛЯ НА ФОРМУ ВХОДА САЙТА -----------
$submitUrl = site_url(config('FBSPROFI_LOGIN_PATH'));
$result = site_request('POST', $submitUrl, $cookieJar, [
    config('FBSPROFI_LOGIN_FIELD')    => $login,
    config('FBSPROFI_PASSWORD_FIELD') => $password,
]);

// Если после отправки снова видим поле пароля — вход не удался.
$stillLoginForm = html_xpath($result['body'])->query('//input[@type="password"]')->length > 0;
if ($stillLoginForm || $result['status'] === 401 || $result['status'] === 403) {
    json_error('invalid_credentials', 'Неверный логин или пароль', 401);
}
if ($result['status'] >= 400) {
    json_error('site_error', "Сайт вернул код {$result['status']}", 502);
}

// ----------- ОПРЕДЕЛЕНИЕ КАБИНЕТА ПО АДРЕСУ, КУДА ПЕРЕНАПРАВИЛ САЙТ -----------
$path = parse_url($result['url'], PHP_URL_PATH) ?: '/';
$cabinet = null;
foreach (explode(',', config('FBSPROFI_CABINETS')) as $pair) {
    [$prefix, $name] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
    if ($prefix !== '' && str_starts_with($path, $prefix)) {
        $cabinet = $name;
        break;
    }
}

if ($cabinet === null) {
    json_error('unknown_cabinet', "Вход выполнен, но кабинет не распознан (адрес: $path)", 502);
}

$token = session_create([
    'login'   => $login,
    'cabinet' => $cabinet,
    'home'    => $path,
], $cookieJar);

json_response([
    'ok'         => true,
    'token'      => $token,
    'cabinet'    => $cabinet,
    'expires_at' => time() + (int)config('FBSPROFI_SESSION_TTL'),
]);
