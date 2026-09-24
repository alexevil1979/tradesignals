# Перенос проекта на новый VPS (один в один)

Полный перенос production `td.1tlt.ru` со старого VPS на новый **без потери данных и настроек**: тот же код, та же БД, те же секреты, те же cron/Apache/PHP, тот же домен.

Для чистой установки с нуля см. [DEPLOY.md](DEPLOY.md). Этот документ — именно **миграция**.

Готовые Apache-конфиги лежат в репозитории:

- [`deploy/apache/td.1tlt.ru.conf`](deploy/apache/td.1tlt.ru.conf) — HTTP → HTTPS
- [`deploy/apache/td.1tlt.ru-le-ssl.conf`](deploy/apache/td.1tlt.ru-le-ssl.conf) — HTTPS, сертификаты из `/etc/letsencrypt/live/td.1tlt.ru/`

> Перед cutover держите `trading_enabled=0` (или не включайте торговлю), пока новый сервер не проверен. У API-ключа Bybit не должно быть разрешения на вывод средств. Если у ключа включён IP whitelist — обновите IP **до** переключения DNS.

---

## Что переносится 1:1

| Компонент | Где на старом VPS | Как перенести |
|-----------|-------------------|---------------|
| Код приложения | `/ssd/www/tradesignals` | `git clone` + `composer install` **или** rsync всего каталога |
| Секреты | `config/local.php` | **скопировать файл** (не из Git) |
| База MySQL | БД `tradesignals` | `mysqldump` → restore |
| Apache vhost | см. `deploy/apache/*.conf` | скопировать из репо (пути к LE уже прописаны) |
| PHP 8.2 CLI/FPM | `/usr/local/php82` | тот же стек, FPM listen `127.0.0.1:9072` |
| `open_basedir` | CLI `php.ini` + FPM pool `www.conf` | добавить `/ssd/www/tradesignals` |
| Cron | crontab пользователя деплоя | те же 2 строки + `/var/log/tradesignals` |
| Логи cron (опционально) | `/var/log/tradesignals/*.log` | rsync при необходимости |
| App storage | `storage/logs`, `storage/locks` | создать каталоги; логи можно скопировать |
| SSL | `/etc/letsencrypt/live/td.1tlt.ru/` | **уже лежат** на новом VPS (стандартный путь LE) — Apache только ссылается на них |
| Telegram proxy | локальный SOCKS/HTTP (если был) | поднять тот же прокси или поправить `local.php` |
| DNS | A-запись `td.1tlt.ru` | сменить на IP нового VPS в конце |

Не коммитить и не светить в чатах: `.env`, `config/local.php`, ключи Bybit, токены Telegram, дампы БД с паролями, приватные ключи Let's Encrypt.

---

## Порядок cutover (шпаргалка)

```
1. Старый: инвентаризация → стоп cron → финальный дамп + local.php
2. Новый:  пакеты, PHP 8.2 :9072, MySQL, каталоги, open_basedir
3. Новый:  код + local.php + restore БД
4. Новый:  проверить /etc/letsencrypt/live/td.1tlt.ru/ → поставить Apache-конфиги из deploy/apache/
5. Новый:  проверка по IP (Host: td.1tlt.ru), Bybit IP, Telegram proxy
6. DNS → HTTPS работает на новых сертах → cron только на новом → стоп старого
```

---

## 0. Подготовка

1. На новом VPS есть Linux (Debian/Ubuntu предпочтительно), свободные порты **80** и **443**, место под дамп + код.
2. Root/SSH на **оба** сервера.
3. Зафиксируйте:
   - пароль MySQL `tradesignals` (из `config/local.php`);
   - путь к PHP (`/usr/local/php82/bin/php`);
   - порт FPM (`9072`);
   - crontab;
   - наличие Telegram-прокси;
   - что сертификаты уже в `/etc/letsencrypt/live/td.1tlt.ru/` на новом VPS.
4. DNS **не** переключайте, пока БД, `local.php` и Apache не проверены локально.

Проверка сертификатов на **новом** VPS (до настройки Apache):

```bash
ls -la /etc/letsencrypt/live/td.1tlt.ru/
# ожидаются (обычно симлинки):
#   fullchain.pem → ../../archive/td.1tlt.ru/fullchainN.pem
#   privkey.pem   → ../../archive/td.1tlt.ru/privkeyN.pem
#   cert.pem, chain.pem

sudo openssl x509 -in /etc/letsencrypt/live/td.1tlt.ru/fullchain.pem -noout -dates -subject
```

Если каталога нет — скопируйте весь `/etc/letsencrypt` со старого VPS (см. шаг 3, опциональный блок SSL) **или** выпустите заново через certbot после DNS (запасной сценарий в конце шага 7).

---

## 1. Инвентаризация на старом VPS

Выполните на **старом** сервере и сохраните вывод:

```bash
hostname -I
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" -v
mysql --version
git -C /ssd/www/tradesignals rev-parse HEAD
git -C /ssd/www/tradesignals status -sb

ls -la /etc/apache2/sites-enabled/td.1tlt.ru*
grep -nE 'DocumentRoot|SetHandler|ProxyPassMatch|ServerName|SSLCertificate' \
  /etc/apache2/sites-enabled/td.1tlt.ru*.conf

"$PHP_BIN" -i | grep open_basedir
grep -R "open_basedir\|listen\|9072" /usr/local/php82/etc/php-fpm.d/ /usr/local/php82/etc/php-fpm.conf 2>/dev/null

crontab -l
ls -la /var/log/tradesignals
ls -la /ssd/www/tradesignals/config/local.php
# НЕ печатайте содержимое local.php в лог/чат

ls -la /etc/letsencrypt/live/td.1tlt.ru/ 2>/dev/null || true

mysql -u tradesignals -p -h 127.0.0.1 -e "
SELECT table_schema,
       ROUND(SUM(data_length+index_length)/1024/1024,1) AS mb
FROM information_schema.tables
WHERE table_schema='tradesignals'
GROUP BY table_schema;"
```

Запомните `COMMIT_SHA` из `git rev-parse HEAD` — на новом нужно тот же коммит.

---

## 2. Заморозка записи на старом VPS

```bash
crontab -e
# закомментируйте обе строки tradesignals:
# * * * * * flock ... fetch_candles.php ...
# * * * * * flock ... process_signals.php ...

sleep 70
ls /tmp/tradesignals-*.lock 2>/dev/null || echo "locks free"
```

В админке при необходимости оставьте торговлю выключенной (`trading_enabled=0`).

После финального дампа cron бота на старом **не должен писать**.

---

## 3. Дамп базы и бэкап секретов (старый VPS)

```bash
sudo mkdir -p /ssd/backups
STAMP=$(date +%F-%H%M%S)
BACKUP_DIR="/ssd/backups/migrate-${STAMP}"
mkdir -p "$BACKUP_DIR"

mysqldump -u tradesignals -p -h 127.0.0.1 \
  --single-transaction --routines --triggers --events \
  tradesignals > "$BACKUP_DIR/tradesignals.sql"

cp -a /ssd/www/tradesignals/config/local.php "$BACKUP_DIR/local.php"
crontab -l > "$BACKUP_DIR/crontab.txt" 2>/dev/null || true

# Опционально: логи и storage
tar -C /var/log -czf "$BACKUP_DIR/tradesignals-logs.tgz" tradesignals 2>/dev/null || true
tar -C /ssd/www/tradesignals -czf "$BACKUP_DIR/storage.tgz" storage 2>/dev/null || true

# Опционально: весь /etc/letsencrypt (если на новом сертов ещё нет)
# sudo tar -C /etc -czf "$BACKUP_DIR/letsencrypt.tgz" letsencrypt

ls -lh "$BACKUP_DIR"
sha256sum "$BACKUP_DIR/tradesignals.sql" > "$BACKUP_DIR/tradesignals.sql.sha256"
```

Скачайте бэкап на новый VPS:

```bash
scp -r root@OLD_IP:/ssd/backups/migrate-YYYY-MM-DD-HHMMSS root@NEW_IP:/root/
```

На новом:

```bash
cd /root/migrate-...
sha256sum -c tradesignals.sql.sha256
```

Если переносите сертификаты вручную:

```bash
# на новом VPS
sudo tar -C /etc -xzf /root/migrate-.../letsencrypt.tgz
sudo ls -la /etc/letsencrypt/live/td.1tlt.ru/
```

---

## 4. Базовая подготовка нового VPS

```bash
sudo apt update
sudo apt install -y apache2 git composer unzip \
  mysql-server mysql-client flock curl
# certbot не обязателен, если сертификаты уже в /etc/letsencrypt/live
# при желании для renew: sudo apt install -y certbot python3-certbot-apache

sudo a2enmod rewrite headers ssl proxy proxy_fcgi
```

### PHP 8.2

Production: `/usr/local/php82/bin/php` и FPM на **9072**.

- **Вариант A (1:1):** тот же custom PHP 8.2 + php-fpm, listen `127.0.0.1:9072`, модули `curl`, `mbstring`, `mysqli`, `pdo_mysql`.
- **Вариант B:** системный PHP ≥ 8.2 — тогда замените путь в crontab и порт/handler в Apache-конфигах.

```bash
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" -v
"$PHP_BIN" -m | grep -E 'curl|mbstring|mysqli|pdo_mysql'
ss -lntp | grep -E '9000|9072' || true
```

### `open_basedir`

В CLI и в FPM pool (`/usr/local/php82/etc/php-fpm.d/www.conf` → `php_admin_value[open_basedir]`):

```ini
open_basedir=/ssd/www/tradesignals:/usr/local/bin:/tmp:/usr/local/php82:/dev/urandom
```

Не удаляйте чужие пути — добавляйте через `:`.

```bash
sudo systemctl restart php82-fpm   # имя unit может отличаться
"$PHP_BIN" -i | grep open_basedir
```

### Каталоги

```bash
sudo mkdir -p /ssd/www /ssd/backups /var/log/tradesignals
sudo mkdir -p /var/www/html/.well-known/acme-challenge
sudo chown www-data:www-data /var/log/tradesignals
```

---

## 5. MySQL на новом VPS

Пароль возьмите **из старого** `config/local.php` (файл секретов не меняем):

```bash
mysql -u root -p <<'SQL'
CREATE DATABASE tradesignals CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tradesignals'@'127.0.0.1' IDENTIFIED BY 'ТОТ_ЖЕ_ПАРОЛЬ_ЧТО_В_local.php';
CREATE USER 'tradesignals'@'localhost' IDENTIFIED BY 'ТОТ_ЖЕ_ПАРОЛЬ_ЧТО_В_local.php';
GRANT ALL PRIVILEGES ON tradesignals.* TO 'tradesignals'@'127.0.0.1';
GRANT ALL PRIVILEGES ON tradesignals.* TO 'tradesignals'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Восстановите **полный** дамп (отдельно `schema.sql` / миграции **не** гоняйте — они уже внутри дампа):

```bash
mysql -u tradesignals -p -h 127.0.0.1 tradesignals < /root/migrate-.../tradesignals.sql
mysql -u tradesignals -p -h 127.0.0.1 tradesignals -e "
SHOW TABLES;
SELECT COUNT(*) AS admins FROM admins;
SELECT COUNT(*) AS candles FROM candles;
SELECT COUNT(*) AS signals FROM signals;"
```

Сверьте счётчики со старым сервером. Админа создавать заново **не нужно**.

---

## 6. Код приложения на новом VPS

### Рекомендуемый способ (Git + тот же commit)

```bash
sudo git clone https://github.com/alexevil1979/tradesignals.git /ssd/www/tradesignals
cd /ssd/www/tradesignals
sudo git checkout <COMMIT_SHA_СО_СТАРОГО>
sudo chown -R "$USER":www-data /ssd/www/tradesignals

PHP_BIN=/usr/local/php82/bin/php
COMPOSER_BIN="$(command -v composer)"
export COMPOSER_HOME=/tmp/tradesignals-composer
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$COMPOSER_HOME"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
```

### Альтернатива: rsync со старого (максимально 1:1, включая vendor)

```bash
rsync -aHAX --info=progress2 \
  root@OLD_IP:/ssd/www/tradesignals/ /ssd/www/tradesignals/
```

### Секреты и права

```bash
cp /root/migrate-.../local.php /ssd/www/tradesignals/config/local.php
chmod 640 /ssd/www/tradesignals/config/local.php
chown "$USER":www-data /ssd/www/tradesignals/config/local.php

mkdir -p storage/logs storage/locks
touch storage/logs/.gitkeep storage/locks/.gitkeep
# tar -C /ssd/www/tradesignals -xzf /root/migrate-.../storage.tgz

find /ssd/www/tradesignals -type d -exec chmod 755 {} \;
find /ssd/www/tradesignals -type f -exec chmod 644 {} \;
chmod 640 /ssd/www/tradesignals/config/local.php
```

Проверка БД и Telegram:

```bash
cd /ssd/www/tradesignals
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" -r 'require "vendor/autoload.php"; $c=require "config/config.php"; echo $c["database"]["host"]."|".$c["database"]["name"]."|".$c["database"]["user"]."|".strlen((string)$c["database"]["password"]).PHP_EOL;'
mysql -u tradesignals -p -h 127.0.0.1 tradesignals -e "SELECT 1"
"$PHP_BIN" bin/test_telegram.php
```

---

## 7. Apache + SSL (сертификаты уже в Let's Encrypt)

Конфиги **не** выпускают сертификат — только указывают стандартные пути:

```
SSLCertificateFile    /etc/letsencrypt/live/td.1tlt.ru/fullchain.pem
SSLCertificateKeyFile /etc/letsencrypt/live/td.1tlt.ru/privkey.pem
```

### 7.1. Убедиться, что серты на месте

```bash
sudo test -f /etc/letsencrypt/live/td.1tlt.ru/fullchain.pem \
  && sudo test -f /etc/letsencrypt/live/td.1tlt.ru/privkey.pem \
  && echo "certs OK" \
  || echo "НЕТ СЕРТОВ — скопируйте /etc/letsencrypt или выпустите certbot"
```

### 7.2. Поставить vhost из репозитория

```bash
cd /ssd/www/tradesignals

sudo cp deploy/apache/td.1tlt.ru.conf /etc/apache2/sites-available/
sudo cp deploy/apache/td.1tlt.ru-le-ssl.conf /etc/apache2/sites-available/

# options-ssl-apache.conf появляется после первого certbot; если файла нет — строка IncludeOptional просто ничего не подключит
ls /etc/letsencrypt/options-ssl-apache.conf 2>/dev/null || true

sudo a2dissite 000-default.conf 2>/dev/null || true
sudo a2ensite td.1tlt.ru.conf
sudo a2ensite td.1tlt.ru-le-ssl.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Содержимое SSL-vhost (кратко): `DocumentRoot /ssd/www/tradesignals/public`, PHP через `ProxyPassMatch` на `fcgi://127.0.0.1:9072/...`, сертификаты из `/etc/letsencrypt/live/td.1tlt.ru/`.

### 7.3. Проверка до смены DNS (по IP)

```bash
echo '<?php echo PHP_VERSION, " ", __FILE__;' > /ssd/www/tradesignals/public/phpver.php
chmod 644 /ssd/www/tradesignals/public/phpver.php

curl -s -H 'Host: td.1tlt.ru' http://127.0.0.1/phpver.php
# редирект на https — нормально; проверьте HTTPS локально:
curl -sk -H 'Host: td.1tlt.ru' https://127.0.0.1/phpver.php
# должно быть 8.2.x и путь .../public/phpver.php

rm /ssd/www/tradesignals/public/phpver.php
```

Если `No input file specified` — проверьте FPM `:9072` и `open_basedir` в pool (см. [DEPLOY.md](DEPLOY.md)).

### 7.4. Запасной сценарий: сертификатов нет

Только если `/etc/letsencrypt/live/td.1tlt.ru/` пуст и перенести со старого нельзя. **После** смены DNS:

```bash
sudo apt install -y certbot python3-certbot-apache
# временно оставьте только HTTP-vhost без редиректа, либо:
sudo certbot certonly --webroot -w /var/www/html -d td.1tlt.ru
# затем снова a2ensite оба конфига из deploy/apache/ и reload
```

---

## 8. Cron на новом VPS

Пока DNS не переключён, cron на новом **не** включайте (иначе дубли Telegram / торговля с двух машин). Перед cutover:

```bash
sudo mkdir -p /var/log/tradesignals
sudo chown www-data:www-data /var/log/tradesignals
crontab -e
```

```cron
* * * * * flock -n /tmp/tradesignals-candles.lock /usr/local/php82/bin/php /ssd/www/tradesignals/cron/fetch_candles.php >> /var/log/tradesignals/candles.log 2>&1
* * * * * flock -n /tmp/tradesignals-signals.lock /usr/local/php82/bin/php /ssd/www/tradesignals/cron/process_signals.php >> /var/log/tradesignals/signals.log 2>&1
```

Ручной прогон один раз (ещё без DNS):

```bash
cd /ssd/www/tradesignals
/usr/local/php82/bin/php cron/fetch_candles.php
/usr/local/php82/bin/php cron/process_signals.php
tail -n 50 /var/log/tradesignals/candles.log /var/log/tradesignals/signals.log
```

---

## 9. Telegram-прокси (если был)

Если в `local.php` указано `'proxy' => 'socks5h://127.0.0.1:1080'`:

```bash
ss -lntp | grep 1080
/usr/local/php82/bin/php /ssd/www/tradesignals/bin/test_telegram.php
```

Либо поднимите тот же прокси, либо временно уберите/`proxy` на рабочий endpoint.

---

## 10. Bybit IP whitelist

1. Публичный IP нового VPS: `curl -4 ifconfig.me`
2. В кабинете Bybit добавьте новый IP (старый можно оставить до полного cutover)
3. Только после этого включайте торговые вызовы на новом сервере

---

## 11. Cutover: DNS → только новый сервер

### 11.1. Финальный догон дампа

Если после первого бэкапа на старом снова писали — повторите шаг 3 и заново импортируйте на новый (осторожно: пересоздание БД). Если cron уже остановлен — достаточно первого дампа.

### 11.2. DNS

Смените A-запись `td.1tlt.ru` на IP **нового** VPS. TTL лучше заранее уменьшить (300 с).

```bash
dig +short td.1tlt.ru
# должен стать NEW_IP
```

### 11.3. HTTPS на уже лежащих сертификатах

Certbot заново **не** нужен, если файлы в `/etc/letsencrypt/live/td.1tlt.ru/` валидны:

```bash
curl -I https://td.1tlt.ru/
curl -I https://td.1tlt.ru/admin/
sudo openssl x509 -in /etc/letsencrypt/live/td.1tlt.ru/fullchain.pem -noout -dates
```

Для автообновления (если certbot установлен и аккаунт LE настроен):

```bash
sudo systemctl status certbot.timer
sudo certbot renew --dry-run
```

### 11.4. Cron только на новом, стоп старого

На **старом**:

```bash
crontab -e   # строки tradesignals закомментированы
sudo a2dissite td.1tlt.ru.conf td.1tlt.ru-le-ssl.conf
sudo systemctl reload apache2
```

На **новом** — раскомментируйте crontab (шаг 8), если ещё не включён.

### 11.5. Чеклист

| Проверка | Действие | Ожидание |
|----------|----------|----------|
| HTTPS | `curl -I https://td.1tlt.ru/` | 200/302, сертификат валиден |
| Пути LE | `ls /etc/letsencrypt/live/td.1tlt.ru/` | fullchain.pem, privkey.pem |
| Админка | вход тем же логином/паролем | сессия работает |
| PHP | временный `phpver.php` | 8.2.x |
| Свечи | `fetch_candles.php` | без фаталов, свежие данные |
| Сигналы | `process_signals.php` | без фаталов в логе |
| Telegram | `bin/test_telegram.php` | сообщение уходит |
| Mobile API | `POST /api/mobile/login.php` | токен |
| Bybit | при необходимости | нет ошибок auth/IP |

Старый VPS держите 24–48 ч как cold backup.

---

## 12. Откат

1. Верните A-запись DNS на IP **старого** VPS.
2. На старом раскомментируйте crontab и включите Apache/vhost.
3. На новом остановите cron и сайт.

Дамп из шага 3 — точка восстановления.

---

## 13. Частые ошибки

| Симптом | Причина | Что сделать |
|---------|---------|-------------|
| Apache не стартует / SSL error | нет файлов в `/etc/letsencrypt/live/td.1tlt.ru/` | проверить пути, скопировать LE или `certbot certonly` |
| Composer / «PHP >= 8.2» в браузере | Apache бьёт в старый FPM `:9000` | handler на `:9072` |
| `No input file specified` | FPM / `open_basedir` | `ProxyPassMatch` из `deploy/apache/`, путь в pool |
| Access denied MySQL | другой пароль / `@localhost` vs `@127.0.0.1` | оба GRANT + пароль как в `local.php` |
| Пустая админка | импортировали только schema | полный `tradesignals.sql` |
| Дубли сигналов/Telegram | cron на двух VPS | cron только на одном |
| Bybit 403 / IP | whitelist | добавить IP нового VPS |
| Telegram timeout | нет прокси | поднять SOCKS или убрать `proxy` |

---

## Краткая шпаргалка

1. Инвентаризация старого → стоп cron.  
2. Дамп БД + `config/local.php` + crontab (+ опционально `letsencrypt.tgz`).  
3. Новый VPS: PHP 8.2 `:9072`, MySQL, `open_basedir`, каталоги.  
4. БД/user → restore дампа.  
5. Код (тот же commit **или** rsync) + `local.php`.  
6. Проверить `/etc/letsencrypt/live/td.1tlt.ru/` → `cp deploy/apache/*.conf` → `a2ensite` → reload.  
7. Bybit IP + Telegram proxy.  
8. DNS → cron только на новом → стоп старого.  
9. Чеклист 11.5.

Дальнейшие обновления кода — раздел «Обновление из Git» в [DEPLOY.md](DEPLOY.md).
