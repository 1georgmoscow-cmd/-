# Как пользоваться из своего PHP-кода

## Установка, один раз

```bash
sudo bash deploy/install.sh              # пакеты, копирование в /opt/labelprint, юниты
mysql -u root -p < db/schema.mysql.sql   # база и таблицы
cd /opt/labelprint
cp config/config.example.php config/config.php
cp config/printers.example.php config/printers.php
$EDITOR config/config.php                # доступ к базе, путь к PDF, профиль по умолчанию
php bin/doctor.php                       # проверка окружения
sudo systemctl enable --now labelprint-scanner labelprint-worker@{1..4}
```

Для этикеток OZON в `config.php` поставьте `'default_profile' => 'ozon_203_58x40'`
и оставьте `barcode.dmtx => false` — DataMatrix на них нет, это экономит ~200 мс.

## Подключение

Composer не нужен, автозагрузчик свой:

```php
require '/opt/labelprint/src/bootstrap.php';

$api = LabelPrint\Api::boot();
```

## Четыре операции — это всё, что нужно

### 1. Взять готовый ZPL

```php
// Путь ОТНОСИТЕЛЬНО pdf_dir из конфига.
$label = $api->label('2026/09/ozon-12345.pdf');

$label->zpl;          // готовые байты ^XA…^XZ
$label->code();       // '751466115153000' — то, что вернёт сканер
$label->id;           // понадобится для сверки
$label->widthDots;    // 464
$label->heightDots;   // 320
```

Обычно этикетку уже отрендерил воркер, и это просто чтение строки из MySQL —
**около 5 мс**. Если файл появился секунду назад и ждать воркер некогда:

```php
$label = $api->label('ozon.pdf', renderIfMissing: true);   // отрендерит здесь же, ~390 мс
```

Многостраничный файл:

```php
foreach ($api->labels('multi.pdf') as $label) { /* … */ }
$api->zpl('multi.pdf');                                    // все страницы одной строкой
```

### 2. Проверить до печати

```php
if (!$label->hasCodes()) {
    // На этикетке не распознан ни один код — сканер её не подтвердит.
    // Узнать об этом лучше сейчас, чем когда она уже на коробке.
}
```

### 3. Напечатать

```php
$api->send($label->zpl, '192.168.1.50');    // сетевой принтер, порт 9100
```

Это запись байтов в сокет: **около 0,5 мс**. Ошибки — обычное исключение:

```php
try {
    $api->send($label->zpl, $printerIp);
} catch (RuntimeException $e) {
    // 'Принтер 192.168.1.50:9100 недоступен: Connection refused (111)'
}
```

### 4. Сверить после наклейки

```php
$scanned = $_POST['scanned'];               // то, что ввёл сканер штрихкода

if ($api->verify($label->id, $scanned)) {
    // наклеена та этикетка
} else {
    // не та — не давать закрыть операцию
}
```

Сравнение идёт и по исходным байтам, и по виду без управляющих символов:
ручные сканеры по-разному обходятся с разделителем `GS`.

Обратный поиск — сканер дал код, надо понять, чья это этикетка:

```php
foreach ($api->findByCode($scanned) as $found) {
    $found['path'];          // '2026/09/ozon-12345.pdf'
    $found['page_no'];
    $found['zpl_label_id'];
}
```

## Полный пример

`examples/workflow.php` — весь процесс с комментариями:

```bash
php examples/workflow.php ozon.pdf 192.168.1.50
```

## Если не хотите использовать классы

Всё то же самое доступно обычными запросами. Взять ZPL:

```sql
SELECT l.id, l.zpl
  FROM zpl_labels l
  JOIN pdf_files f ON f.id = l.pdf_file_id
 WHERE f.path = ?
   AND l.pdf_sha256 = f.sha256           -- обязательно: только текущая версия файла
   AND l.profile_code = ?
 ORDER BY l.page_no;
```

Сверить со сканером:

```sql
SELECT 1 FROM label_codes
 WHERE zpl_label_id = ?
   AND (value_sha1 = SHA1(?) OR normalized_sha1 = SHA1(?));
```

Отправить на принтер — четыре строки без всяких библиотек:

```php
$socket = stream_socket_client('tcp://192.168.1.50:9100', $errno, $error, 3);
fwrite($socket, $zpl);
fclose($socket);
```

## Сколько это стоит по времени

| Операция | Время |
|---|---|
| Чтение готового ZPL из MySQL | ~5 мс |
| Рендеринг «на месте», если в кэше нет | ~390 мс |
| Отправка на сетевой принтер | ~0,5 мс |
| Сверка со сканером | ~1 мс (по индексу) |

Печать по «горячему» кэшу — это чтение строки и запись в сокет, то есть **единицы
миллисекунд**. Вся тяжёлая работа уже сделана воркером заранее.

## Из командной строки

```bash
php bin/status.php                                   # очередь, хранилище, коды
php bin/verify.php --file=ozon.pdf "751466115153000" # сверка, код возврата 0 или 1
php bin/render.php ozon.pdf --codes                  # что распознаётся на этикетке
php bin/render.php ozon.pdf --preview=out.pbm        # посмотреть растр глазами
php bin/doctor.php                                   # проверка окружения
```
