# Перенос проекта на новый VPS (один в один)

Полный перенос production `td.1tlt.ru` со старого VPS на новый **без потери данных и настроек**: тот же код, та же БД, те же секреты, тот же домен.

**Отличия нового VPS от старого:**
- PHP — **системный 8.2 из apt** (`/usr/bin/php8.2`, FPM-сокет `/run/php/php8.2-fpm.sock`), **не** сборка из исходников `/usr/local/php82` и **не** порт `9072`.
- MySQL root: пользователь `root`, пароль `qweasd333123` (все команды ниже с этим паролем).

Для чистой установки с нуля см. [DEPLOY.md](DEPLOY.md). Этот документ — именно **миграция**.

Готовые Apache-конфиги:

- [`deploy/apache/td.1tlt.ru.conf`](deploy/apache/td.1tlt.ru.conf) — HTTP → HTTPS
- [`deploy/apache/td.1tlt.ru-le-ssl.conf`](deploy/apache/td.1tlt.ru-le-ssl.conf) — HTTPS, сертификаты из `/etc/letsencrypt/live/td.1tlt.ru/`, PHP через `php8.2-fpm`

> Перед cutover держите `trading_enabled=0`. У API-ключа Bybit не должно быть разрешения на вывод средств. Если у ключа IP whitelist — обновите IP **до** переключения DNS.

---

## Учётки MySQL (новый VPS)

| Роль | User | Password | Использование |
|------|------|----------|----------------|
| Админ БД | `root` | `qweasd333123` | создание БД, restore, админские запросы |
| Приложение | `tradesignals` | как в `config/local.php` (обычно `qweasd333123`) | PHP / cron |

Пример:

```bash
mysql -u root -p'qweasd333123' -e "SELECT VERSION();"
```

---

## Что переносится 1:1

| Компонент | Где на старом | Как на новом |
|-----------|---------------|--------------|
| Код | `/ssd/www/tradesignals` | `git clone` + `composer` **или** rsync |
| Секреты | `config/local.php` | скопировать файл (не из Git) |
| База | БД `tradesignals` | `mysqldump` → restore через `root` |
| Apache | vhost | `deploy/apache/*.conf` |
| PHP | часто `/usr/local/php82` :9072 | **apt** `php8.2` + `php8.2-fpm` (сокет) |
| Cron | crontab | те же скрипты, бинарник `/usr/bin/php8.2` |
| SSL | Let's Encrypt | уже в `/etc/letsencrypt/live/td.1tlt.ru/` |
| DNS | A `td.1tlt.ru` | сменить в конце |

Не коммитить в Git: `.env`, `config/local.php`, ключи Bybit, токены Telegram, дампы БД, приватные ключи LE.

---

## Порядок cutover

```
1. Старый: инвентаризация → стоп cron → дамп + local.php
2. Новый:  apt PHP 8.2 + php8.2-fpm, MySQL, каталоги
3. Новый:  код + local.php + restore БД (mysql root)
4. Новый:  /etc/letsencrypt/live/td.1tlt.ru/ → Apache из deploy/apache/
5. Новый:  проверка по IP, Bybit IP, Telegram proxy
6. DNS → HTTPS → cron только на новом → стоп старого
```

---

## 0. Подготовка

1. Новый VPS: Debian/Ubuntu, порты 80/443, место под дамп + код.
2. Root/SSH на оба сервера.
3. На новом уже есть (или ставите в шаге 4):
   - системный PHP 8.2 + php8.2-fpm;
   - MySQL с `root` / `qweasd333123`;
   - сертификаты в `/etc/letsencrypt/live/td.1tlt.ru/`.
4. DNS **не** переключайте, пока БД, `local.php` и Apache не проверены.

```bash
ls -la /etc/letsencrypt/live/td.1tlt.ru/
sudo openssl x509 -in /etc/letsencrypt/live/td.1tlt.ru/fullchain.pem -noout -dates -subject
mysql -u root -p'qweasd333123' -e "SELECT VERSION();"
php8.2 -v
systemctl is-active php8.2-fpm
ls -la /run/php/php8.2-fpm.sock
```

---

## 1. Инвентаризация на старом VPS

```bash
hostname -I
# на старом PHP часто custom — зафиксируйте путь из crontab
crontab -l
git -C /ssd/www/tradesignals rev-parse HEAD
git -C /ssd/www/tradesignals status -sb

ls -la /etc/apache2/sites-enabled/td.1tlt.ru* 2>/dev/null
ls -la /ssd/www/tradesignals/config/local.php
ls -la /etc/letsencrypt/live/td.1tlt.ru/ 2>/dev/null || true

# размер БД (если на старом тот же root-пароль)
mysql -u root -p'qweasd333123' -e "
SELECT table_schema,
       ROUND(SUM(data_length+index_length)/1024/1024,1) AS mb
FROM information_schema.tables
WHERE table_schema='tradesignals'
GROUP BY table_schema;"
```

Запомните `COMMIT_SHA` — на новом тот же коммит.

---

## 2. Заморозка записи на старом VPS

```bash
crontab -e
# закомментируйте обе строки tradesignals (fetch_candles / process_signals)

sleep 70
ls /tmp/tradesignals-*.lock 2>/dev/null || echo "locks free"
```

`trading_enabled=0` в админке при необходимости. После финального дампа cron на старом **не должен писать**.

---

## 3. Дамп базы и бэкап секретов (старый VPS)

```bash
sudo mkdir -p /ssd/backups
STAMP=$(date +%F-%H%M%S)
BACKUP_DIR="/ssd/backups/migrate-${STAMP}"
mkdir -p "$BACKUP_DIR"

mysqldump -u root -p'qweasd333123' \
  --single-transaction --routines --triggers --events \
  tradesignals > "$BACKUP_DIR/tradesignals.sql"

cp -a /ssd/www/tradesignals/config/local.php "$BACKUP_DIR/local.php"
crontab -l > "$BACKUP_DIR/crontab.txt" 2>/dev/null || true

tar -C /var/log -czf "$BACKUP_DIR/tradesignals-logs.tgz" tradesignals 2>/dev/null || true
tar -C /ssd/www/tradesignals -czf "$BACKUP_DIR/storage.tgz" storage 2>/dev/null || true
# sudo tar -C /etc -czf "$BACKUP_DIR/letsencrypt.tgz" letsencrypt

ls -lh "$BACKUP_DIR"
sha256sum "$BACKUP_DIR/tradesignals.sql" > "$BACKUP_DIR/tradesignals.sql.sha256"
```

```bash
scp -r root@OLD_IP:/ssd/backups/migrate-YYYY-MM-DD-HHMMSS root@NEW_IP:/root/
```

На новом:

```bash
cd /root/migrate-...
sha256sum -c tradesignals.sql.sha256
```

---

## 4. Базовая подготовка нового VPS

```bash
sudo apt update
sudo apt install -y apache2 git composer unzip flock curl \
  mysql-server mysql-client \
  php8.2 php8.2-cli php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip
# certbot опционально (серты уже в /etc/letsencrypt/live):
# sudo apt install -y certbot python3-certbot-apache

sudo a2enmod rewrite headers ssl proxy proxy_fcgi
sudo systemctl enable --now php8.2-fpm
```

### PHP 8.2 (системный apt, не из исходников)

```bash
PHP_BIN=/usr/bin/php8.2
"$PHP_BIN" -v                    # PHP 8.2.x
"$PHP_BIN" -m | grep -E 'curl|mbstring|mysqli|pdo_mysql'
systemctl status php8.2-fpm --no-pager
ls -la /run/php/php8.2-fpm.sock
# listen по умолчанию — unix-сокет, НЕ 9072
grep -E '^listen\s*=' /etc/php/8.2/fpm/pool.d/www.conf
```

Cron и Composer на новом VPS всегда вызывают `/usr/bin/php8.2` (или `php8.2`). Пути `/usr/local/php82` и порт `9072` со старого сервера **не используйте**.

### `open_basedir`

На стандартном apt PHP `open_basedir` обычно пустой — тогда ничего не меняйте:

```bash
php8.2 -i | grep open_basedir
grep -R open_basedir /etc/php/8.2/fpm/pool.d/ /etc/php/8.2/cli/ 2>/dev/null || true
```

Если ограничение уже задано — добавьте `/ssd/www/tradesignals` через `:` и:

```bash
sudo systemctl restart php8.2-fpm
```

### Каталоги

```bash
sudo mkdir -p /ssd/www /ssd/backups /var/log/tradesignals
sudo mkdir -p /var/www/html/.well-known/acme-challenge
sudo chown www-data:www-data /var/log/tradesignals
```

### MySQL root

Если root ещё без пароля / другой пароль — выставьте `qweasd333123` (один раз):

```bash
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'qweasd333123'; FLUSH PRIVILEGES;"
# или для auth_socket-only систем:
# sudo mysql
# ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'qweasd333123';
# FLUSH PRIVILEGES;
```

Проверка: `mysql -u root -p'qweasd333123' -e "SELECT 1"`

---

## 5. MySQL: БД и restore

Пароль пользователя приложения возьмите из старого `config/local.php` (часто тот же `qweasd333123`):

```bash
mysql -u root -p'qweasd333123' <<'SQL'
CREATE DATABASE IF NOT EXISTS tradesignals CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'tradesignals'@'127.0.0.1' IDENTIFIED BY 'qweasd333123';
CREATE USER IF NOT EXISTS 'tradesignals'@'localhost' IDENTIFIED BY 'qweasd333123';
GRANT ALL PRIVILEGES ON tradesignals.* TO 'tradesignals'@'127.0.0.1';
GRANT ALL PRIVILEGES ON tradesignals.* TO 'tradesignals'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Если пароль в `local.php` другой — подставьте его в `IDENTIFIED BY`, либо после копирования `local.php` оставьте как в файле.

Restore **полного** дампа (отдельно `schema.sql` не гоняйте):

```bash
mysql -u root -p'qweasd333123' tradesignals < /root/migrate-.../tradesignals.sql

mysql -u root -p'qweasd333123' tradesignals -e "
SHOW TABLES;
SELECT COUNT(*) AS admins FROM admins;
SELECT COUNT(*) AS candles FROM candles;
SELECT COUNT(*) AS signals FROM signals;"
```

Админа создавать заново **не нужно**.

---

## 6. Код приложения

### Git + тот же commit

```bash
sudo git clone https://github.com/alexevil1979/tradesignals.git /ssd/www/tradesignals
cd /ssd/www/tradesignals
sudo git checkout <COMMIT_SHA_СО_СТАРОГО>
sudo chown -R "$USER":www-data /ssd/www/tradesignals

PHP_BIN=/usr/bin/php8.2
COMPOSER_BIN="$(command -v composer)"
export COMPOSER_HOME=/tmp/tradesignals-composer
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$COMPOSER_HOME"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
```

### Или rsync со старого

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

find /ssd/www/tradesignals -type d -exec chmod 755 {} \;
find /ssd/www/tradesignals -type f -exec chmod 644 {} \;
chmod 640 /ssd/www/tradesignals/config/local.php
```

```bash
cd /ssd/www/tradesignals
PHP_BIN=/usr/bin/php8.2
"$PHP_BIN" -r 'require "vendor/autoload.php"; $c=require "config/config.php"; echo $c["database"]["host"]."|".$c["database"]["name"]."|".$c["database"]["user"]."|".strlen((string)$c["database"]["password"]).PHP_EOL;'
mysql -u root -p'qweasd333123' tradesignals -e "SELECT 1"
"$PHP_BIN" bin/test_telegram.php
```

---

## 7. Apache + SSL (серты уже в Let's Encrypt)

В SSL-конфиге уже прописано:

```
SSLCertificateFile    /etc/letsencrypt/live/td.1tlt.ru/fullchain.pem
SSLCertificateKeyFile /etc/letsencrypt/live/td.1tlt.ru/privkey.pem
SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"
```

### 7.1. Серты

```bash
sudo test -f /etc/letsencrypt/live/td.1tlt.ru/fullchain.pem \
  && sudo test -f /etc/letsencrypt/live/td.1tlt.ru/privkey.pem \
  && echo "certs OK"
```

### 7.2. Vhost из репозитория

```bash
cd /ssd/www/tradesignals
sudo cp deploy/apache/td.1tlt.ru.conf /etc/apache2/sites-available/
sudo cp deploy/apache/td.1tlt.ru-le-ssl.conf /etc/apache2/sites-available/

sudo a2dissite 000-default.conf 2>/dev/null || true
sudo a2ensite td.1tlt.ru.conf
sudo a2ensite td.1tlt.ru-le-ssl.conf
sudo apachectl configtest
sudo systemctl reload apache2
sudo systemctl reload php8.2-fpm
```

### 7.3. Проверка до DNS

```bash
echo '<?php echo PHP_VERSION, " ", __FILE__;' > /ssd/www/tradesignals/public/phpver.php
chmod 644 /ssd/www/tradesignals/public/phpver.php

curl -sk -H 'Host: td.1tlt.ru' https://127.0.0.1/phpver.php
# 8.2.x и путь .../public/phpver.php

rm /ssd/www/tradesignals/public/phpver.php
```

Если 503 / пусто — `systemctl status php8.2-fpm`, `ls /run/php/php8.2-fpm.sock`, `tail /var/log/apache2/td.1tlt.ru-ssl-error.log`.

### 7.4. Нет сертов

После DNS: `sudo certbot certonly --webroot -w /var/www/html -d td.1tlt.ru`, затем снова включите конфиги из `deploy/apache/`.

---

## 8. Cron (новый VPS — php8.2)

Пока DNS не переключён, cron на новом **не** включайте.

```bash
sudo mkdir -p /var/log/tradesignals
sudo chown www-data:www-data /var/log/tradesignals
crontab -e
```

```cron
* * * * * flock -n /tmp/tradesignals-candles.lock /usr/bin/php8.2 /ssd/www/tradesignals/cron/fetch_candles.php >> /var/log/tradesignals/candles.log 2>&1
* * * * * flock -n /tmp/tradesignals-signals.lock /usr/bin/php8.2 /ssd/www/tradesignals/cron/process_signals.php >> /var/log/tradesignals/signals.log 2>&1
```

Ручной прогон:

```bash
cd /ssd/www/tradesignals
/usr/bin/php8.2 cron/fetch_candles.php
/usr/bin/php8.2 cron/process_signals.php
tail -n 50 /var/log/tradesignals/candles.log /var/log/tradesignals/signals.log
```

---

## 9. Telegram-прокси (если был)

```bash
ss -lntp | grep 1080
/usr/bin/php8.2 /ssd/www/tradesignals/bin/test_telegram.php
```

---

## 10. Bybit IP whitelist

1. `curl -4 ifconfig.me`
2. Добавьте IP нового VPS в whitelist ключа
3. Затем торговые вызовы на новом сервере

---

## 11. Cutover

### 11.1. DNS

A-запись `td.1tlt.ru` → IP нового VPS (TTL заранее 300 с).

```bash
dig +short td.1tlt.ru
```

### 11.2. HTTPS

```bash
curl -I https://td.1tlt.ru/
curl -I https://td.1tlt.ru/admin/
```

### 11.3. Cron только на новом

На старом — crontab бота закомментирован, `a2dissite` при необходимости.  
На новом — строки из шага 8 активны.

### 11.4. Чеклист

| Проверка | Ожидание |
|----------|----------|
| `curl -I https://td.1tlt.ru/` | 200/302 |
| `php8.2 -v` / `phpver.php` | 8.2.x (apt) |
| `ls /run/php/php8.2-fpm.sock` | сокет есть |
| админка | тот же логин/пароль |
| cron / ручной fetch+process | без фаталов |
| `bin/test_telegram.php` | сообщение уходит |
| Mobile API login | токен |

Старый VPS — cold backup 24–48 ч.

---

## 12. Откат

1. DNS обратно на старый IP.  
2. На старом — crontab + Apache.  
3. На новом — стоп cron и сайт.

---

## 13. Частые ошибки

| Симптом | Причина | Что сделать |
|---------|---------|-------------|
| Apache SSL error | нет файлов в `/etc/letsencrypt/live/td.1tlt.ru/` | проверить пути / скопировать LE |
| 503 PHP | php8.2-fpm не запущен / другой сокет | `systemctl start php8.2-fpm`, сверить `listen` в pool |
| «PHP >= 8.2» в браузере | старый handler `:9072` / другой PHP | конфиг из `deploy/apache/` (сокет 8.2) |
| Access denied MySQL | неверный пароль root/app | `root` / `qweasd333123`; app — как в `local.php` |
| Пустая админка | не полный дамп | restore через `mysql -u root -p'qweasd333123'` |
| Дубли Telegram | cron на двух VPS | cron только на одном |
| В crontab остался `/usr/local/php82` | скопировали старый crontab | заменить на `/usr/bin/php8.2` |

---

## Краткая шпаргалка

1. Стоп cron на старом → дамп `mysqldump -u root -p'qweasd333123'` + `local.php`.  
2. Новый: `apt install php8.2 php8.2-fpm ...`, MySQL root `qweasd333123`.  
3. Restore дампа, код + `local.php`, `composer` через `php8.2`.  
4. Серты в `/etc/letsencrypt/live/td.1tlt.ru/` → `deploy/apache/*.conf` → reload.  
5. Cron с `/usr/bin/php8.2`.  
6. DNS → стоп старого.

Дальнейшие обновления кода — [DEPLOY.md](DEPLOY.md) (на новом VPS везде подставляйте `/usr/bin/php8.2` вместо `/usr/local/php82/bin/php`).
