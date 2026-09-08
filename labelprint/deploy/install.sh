#!/usr/bin/env bash
# Развёртывание labelprint на Ubuntu 22.04 / 24.04.
# Запускать из каталога проекта: sudo bash deploy/install.sh
set -euo pipefail

TARGET=${TARGET:-/opt/labelprint}
PDF_DIR=${PDF_DIR:-/upload/pdf}
SERVICE_USER=${SERVICE_USER:-www-data}
WORKERS=${WORKERS:-$(nproc)}

echo "==> Установка пакетов"
apt-get update -qq
apt-get install -y --no-install-recommends \
    php-cli php-mysql php-mbstring php-zip \
    ghostscript poppler-utils mupdf-tools \
    zbar-tools dmtx-utils acl

echo "==> Копирование в ${TARGET}"
mkdir -p "${TARGET}"
cp -r bin config db deploy src tests "${TARGET}/"
mkdir -p "${TARGET}/storage/logs"
chown -R "${SERVICE_USER}:${SERVICE_USER}" "${TARGET}/storage"

if [[ ! -f "${TARGET}/config/config.php" ]]; then
    cp "${TARGET}/config/config.example.php" "${TARGET}/config/config.php"
    echo "    создан config/config.php — впишите доступ к базе"
fi
if [[ ! -f "${TARGET}/config/printers.php" ]]; then
    cp "${TARGET}/config/printers.example.php" "${TARGET}/config/printers.php"
fi

echo "==> Каталог с PDF: ${PDF_DIR}"
mkdir -p "${PDF_DIR}"
# Воркеру достаточно чтения; писать туда должен веб-сервер.
setfacl -m "u:${SERVICE_USER}:rx" "${PDF_DIR}" 2>/dev/null \
    || chgrp "${SERVICE_USER}" "${PDF_DIR}" \
    || echo "    предупреждение: раздайте права на ${PDF_DIR} вручную"

echo "==> Служебные юниты"
cp deploy/labelprint-worker@.service deploy/labelprint-scanner.service /etc/systemd/system/
systemctl daemon-reload

cat <<TXT

Готово. Осталось:

  1. Создать базу и таблицы:
       mysql -u root -p < ${TARGET}/db/schema.mysql.sql
  2. Вписать доступ в ${TARGET}/config/config.php
  3. Проверить окружение:
       php ${TARGET}/bin/doctor.php
  4. Запустить:
       systemctl enable --now labelprint-scanner
       systemctl enable --now labelprint-worker@{1..${WORKERS}}
  5. Посмотреть логи:
       journalctl -u 'labelprint-*' -f

TXT
