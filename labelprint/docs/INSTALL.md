# Установка на Ubuntu, по шагам

Проверено на Ubuntu 24.04 и 22.04. Всё, что ниже, выполняется от root
(или через `sudo`). Занимает минут десять.

---

## Шаг 1. Зависимости

```bash
sudo apt update
sudo apt install -y \
    php-cli php-mysql php-mbstring php-xml \
    ghostscript poppler-utils mupdf-tools \
    zbar-tools dmtx-utils \
    mysql-server acl
```

Что зачем:

| Пакет | Зачем |
|---|---|
| `php-cli` | воркер и утилиты (нужен PHP 8.1+) |
| `php-mysql` | подключение к базе |
| `php-mbstring`, `php-xml` | обрезка строк и разбор вывода zbar |
| `ghostscript` | растеризация PDF (основной движок) |
| `mupdf-tools` | быстрый движок, втрое быстрее Ghostscript |
| `poppler-utils` | точное определение размера и ориентации страницы |
| `zbar-tools` | распознавание QR и штрихкодов |
| `dmtx-utils` | DataMatrix; для OZON не нужен, но пусть будет |
| `mysql-server` | хранилище (подойдёт и `mariadb-server`) |
| `acl` | раздача прав на каталог с PDF |

Проверьте версию PHP — нужна 8.1 или новее:

```bash
php -v
```

Если в вашей Ubuntu PHP старее, подключите репозиторий:

```bash
sudo add-apt-repository ppa:ondrej/php && sudo apt update
sudo apt install -y php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml
```

Composer **не нужен** — в проекте свой автозагрузчик.

---

## Шаг 2. Файлы программы

```bash
sudo mkdir -p /opt/labelprint
sudo cp -r bin config db deploy docs examples src tests /opt/labelprint/
sudo mkdir -p /opt/labelprint/storage/logs
```

---

## Шаг 3. База данных

```bash
sudo mysql < /opt/labelprint/db/schema.mysql.sql

sudo mysql -e "
CREATE USER IF NOT EXISTS 'labelprint'@'localhost' IDENTIFIED BY 'ПРИДУМАЙТЕ_ПАРОЛЬ';
GRANT SELECT, INSERT, UPDATE, DELETE ON labelprint.* TO 'labelprint'@'localhost';
FLUSH PRIVILEGES;"
```

---

## Шаг 4. Каталог для PDF

```bash
sudo mkdir -p /upload/pdf
# писать туда будет веб-сервер, читать — воркер
sudo chown www-data:www-data /upload/pdf
sudo chmod 2750 /upload/pdf
sudo setfacl -m u:www-data:rwx -m d:u:www-data:rwx /upload/pdf
```

---

## Шаг 5. Конфигурация

```bash
cd /opt/labelprint
sudo cp config/config.example.php config/config.php
sudo cp config/printers.example.php config/printers.php
sudo nano config/config.php
```

Поправьте четыре строки:

```php
'pdf_dir' => '/upload/pdf',
'db' => [
    'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=labelprint;charset=utf8mb4',
    'user'     => 'labelprint',
    'password' => 'ТОТ_САМЫЙ_ПАРОЛЬ',
],
'default_profile' => 'ozon_203_58x40',   // для этикеток OZON
```

Пароль лучше держать вне файла — тогда оставьте в конфиге пустую строку
и положите его в `/etc/labelprint/env`:

```bash
sudo mkdir -p /etc/labelprint
echo 'LABELPRINT_DB_PASSWORD=ТОТ_САМЫЙ_ПАРОЛЬ' | sudo tee /etc/labelprint/env
sudo chmod 600 /etc/labelprint/env
```

и раскомментируйте `EnvironmentFile` в юнитах systemd.

---

## Шаг 6. Проверка

```bash
sudo -u www-data php /opt/labelprint/bin/doctor.php
```

Должно быть без строк `FAIL`. Проверяется всё, что ломается молча: наличие
Ghostscript и zbar, права на каталог, применённая схема, версия MySQL,
`max_allowed_packet`, и распознаётся ли встроенный эталонный QR.

---

## Шаг 7. Запуск служб

```bash
sudo cp /opt/labelprint/deploy/labelprint-worker@.service \
        /opt/labelprint/deploy/labelprint-scanner.service /etc/systemd/system/
sudo systemctl daemon-reload

# сканер — ровно один
sudo systemctl enable --now labelprint-scanner
# воркеров — по числу ядер
sudo systemctl enable --now labelprint-worker@{1..4}

sudo systemctl status 'labelprint-*' --no-pager
journalctl -u 'labelprint-*' -f
```

---

## Шаг 8. Контрольный прогон

Положите любую этикетку OZON в `/upload/pdf` и посмотрите:

```bash
php /opt/labelprint/bin/status.php
```

Через секунду-две в разделе «Распознанные коды» должен появиться `QR-Code 1`.

Проверить конкретный файл, ничего не записывая в базу:

```bash
php /opt/labelprint/bin/render.php ФАЙЛ.pdf --codes
php /opt/labelprint/bin/render.php ФАЙЛ.pdf --preview=/tmp/label.pbm
```

---

## Обновление

```bash
cd /opt/labelprint
sudo cp -r /путь/к/новой/версии/{bin,src,db,deploy,docs,examples} .
sudo mysql < db/schema.mysql.sql        # схема идемпотентна
sudo systemctl restart 'labelprint-*'
php bin/doctor.php
```

Конфиги `config/config.php` и `config/printers.php` не перезаписываются.

---

## Если что-то не так

| Симптом | Что смотреть |
|---|---|
| `doctor.php` пишет FAIL про каталог | права на `/upload/pdf`, шаг 4 |
| `doctor.php` пишет FAIL про таблицы | схема не применена, шаг 3 |
| Файлы лежат, но ничего не происходит | `systemctl status labelprint-scanner`, права на чтение |
| Задания в `failed` | `php bin/status.php --failures` покажет причину |
| Этикетка без кодов | `php bin/render.php ФАЙЛ --codes --preview=/tmp/x.pbm`, посмотреть растр |
