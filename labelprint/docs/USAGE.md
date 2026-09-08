# Как пользоваться из своего PHP-кода

## Установка

Пошагово — [docs/INSTALL.md](INSTALL.md). Коротко:

```bash
sudo bash deploy/install.sh              # пакеты, копирование в /opt/labelprint, юниты
sudo mysql < db/schema.mysql.sql         # база и таблицы
cd /opt/labelprint
sudo cp config/config.example.php config/config.php
sudo nano config/config.php              # доступ к базе, путь к PDF, профиль
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

## Главное: этикетка по номеру отправления OZON

```php
$label = $api->savePosting($postingId, $pdfBytes);   // PDF скачан из API OZON

$zpl     = $label->zpl;       // текст этикетки на языке ZPL
$barcode = $label->barcode;   // распознанный QR, например '751466115153000'
```

Это один вызов: PDF сохраняется в `pdf_dir`, привязывается к номеру, рендерится
в ZPL, на растре распознаётся QR. **Около 365 мс.** Повторный вызов с тем же
файлом ничего не пересчитывает.

Если PDF уже лежит в `pdf_dir`:

```php
$label = $api->byPosting('0494051806-0963-1');   // ~1 мс из кэша
```

Связь «номер ↔ файл» устанавливается двумя способами:

* **явно** — `savePosting($postingId, $pdf)`. Надёжнее: имя файла из API OZON
  служебное (`print_to_sticker_11036.pdf`) и номера в себе не содержит;
* **по имени файла** — если положить его как `0494051806-0963-1.pdf`, сканер
  свяжет сам (шаблон настраивается: `scanner.posting_id_pattern`).

Номер отправления проверяется строго — только буквы, цифры, точка, дефис и
подчёркивание. Значение вида `../../etc/passwd` отклоняется: оно превращается
в имя файла.

Что ещё есть в `$label`:

```php
$label->id;            // для сверки со сканером
$label->postingId;
$label->codes;         // все распознанные коды (у OZON он один)
$label->widthDots;     // 464
$label->heightDots;    // 320
$label->dpi;           // 203
$label->toArray();     // готово к json_encode
```

Многостраничный файл: `$api->pagesByPosting($postingId)`.

## Остальные операции

### 1. Взять этикетку по пути к файлу

```php
$label = $api->label('2026/09/ozon-12345.pdf');            // путь относительно pdf_dir
$label = $api->label('ozon.pdf', renderIfMissing: true);   // отрендерить, если ещё нет
$api->zpl('multi.pdf');                                    // все страницы одной строкой
```

### 2. Проверить до печати

```php
if ($label->barcode === null) {
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

## Полные примеры

```bash
php examples/ozon.php 0494051806-0963-1 /tmp/скачанный.pdf 192.168.1.50
php examples/workflow.php ozon.pdf 192.168.1.50
```

## Если не хотите использовать классы

Всё то же самое доступно обычными запросами. Взять ZPL и код:

```sql
-- По номеру отправления
SELECT l.id, l.zpl, c.value AS barcode
  FROM zpl_labels l
  LEFT JOIN label_codes c ON c.zpl_label_id = l.id
 WHERE l.pdf_sha256 = (SELECT sha256 FROM pdf_files WHERE posting_id = ? ORDER BY id DESC LIMIT 1)
   AND l.profile_code = ?
 ORDER BY l.page_no;
```

Искать надо именно по `pdf_sha256`, а не соединением по `pdf_file_id`. Кэш
контент-адресуемый: два отправления с побайтово одинаковой этикеткой делят одну
строку в `zpl_labels`, и соединение по идентификатору файла нашло бы её только
для того, что отрендерился последним.

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
| `byPosting()` — готовая этикетка по номеру | ~1 мс |
| `savePosting()` — приём PDF, рендеринг, распознавание | ~365 мс |
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
