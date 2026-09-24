# Перенос проекта на новый VPS (один в один)

Инструкция для полного переноса production `td.1tlt.ru` со старого VPS на новый **без потери данных и настроек**. Цель: тот же код, та же БД, те же секреты, те же cron/Apache/PHP, тот же домен.

Для чистой установки с нуля см. [DEPLOY.md](DEPLOY.md). Этот документ — именно **миграция**.

> Перед cutover держите `trading_enabled=0` (или не включайте торговлю), пока новый сервер не проверен. У API-ключа Bybit не должно быть разрешения на вывод средств. Если у ключа включён IP whitelist — обновите IP **до** переключения DNS.

## Что переносится 1:1

| Компонент | Где на старом VPS | Как перенести |
|-----------|-------------------|---------------|
| Код приложения | `/ssd/www/tradesignals` | `git clone` + `composer install` **или** rsync всего каталога |
| Секреты | `config/local.php` | **скопировать файл** (не из Git) |
| База MySQL | БД `tradesignals` | `mysqldump` → restore |
| Apache vhost | `/etc/apache2/sites-available/td.1tlt.ru*.conf` | скопировать и поправить пути/handler при необходимости |
| PHP 8.2 CLI/FPM | `/usr/local/php82` | установить такой же стек **или** повторить handler `9072` / пути |
| `open_basedir` | CLI `php.ini` + FPM pool `www.conf` | добавить `/ssd/www/tradesignals` |
| Cron | crontab пользователя деплоя | те же 2 строки + каталог `/var/log/tradesignals` |
| Логи cron (опционально) | `/var/log/tradesignals/*.log` | rsync при необходимости истории |
| App storage | `storage/logs`, `storage/locks` | создать пустые каталоги; логи можно скопировать |
| SSL | Let's Encrypt для `td.1tlt.ru` | **новый** выпуск на новом IP после DNS |
| Telegram proxy | локальный SOCKS/HTTP (если был) | поднять тот же прокси или поправить `local.php` |
| DNS | A-запись `td.1tlt.ru` | сменить на IP нового VPS в конце |

Не коммитить и не светить в чатах: `.env`, `config/local.php`, ключи Bybit, токены Telegram, дампы БД с паролями.

---

## 0. Подготовка

1. Убедитесь, что на новом VPS есть:
   - Linux (как на старом — Debian/Ubuntu предпочтительно);
   - свободные порты **80** и **443**;
   - достаточно места (дамп БД + код + запас).
2. Заведите root/SSH-доступ на **оба** сервера.
3. Зафиксируйте на бумаге/в менеджере паролей:
   - пароль MySQL `tradesignals`;
   - путь к PHP (`/usr/local/php82/bin/php`);
   - порт FPM (`9072` на текущем production);
   - содержимое crontab;
   - наличие Telegram-прокси.
4. На новом VPS заранее поставьте системные пакеты (см. шаг 3), но **не** переключайте DNS, пока БД и конфиг не восстановлены.

Рекомендуемый порядок cutover:

```
старый: стоп cron → финальный дамп → (опционально read-only)
новый: код + local.php + restore БД + Apache + cron (ещё без DNS)
DNS → SSL → проверка → стоп старого Apache/cron
```

---

## 1. Инвентаризация на старом VPS

Выполните на **старом** сервере и сохраните вывод:

```bash
# Пути и версии
hostname -I
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" -v
mysql --version
git -C /ssd/www/tradesignals rev-parse HEAD
git -C /ssd/www/tradesignals status -sb

# Конфиги Apache
ls -la /etc/apache2/sites-enabled/td.1tlt.ru*
grep -nE 'DocumentRoot|SetHandler|ProxyPassMatch|ServerName|open_basedir' \
  /etc/apache2/sites-enabled/td.1tlt.ru*.conf

# PHP open_basedir (CLI и FPM)
"$PHP_BIN" -i | grep open_basedir
grep -R "open_basedir\|listen\|9072" /usr/local/php82/etc/php-fpm.d/ /usr/local/php82/etc/php-fpm.conf 2>/dev/null

# Cron
crontab -l
ls -la /var/log/tradesignals

# Секреты на месте?
ls -la /ssd/www/tradesignals/config/local.php
# НЕ печатайте содержимое local.php в лог/чат

# Размер БД (оценка)
mysql -u tradesignals -p -h 127.0.0.1 -e "
SELECT table_schema,
       ROUND(SUM(data_length+index_length)/1024/1024,1) AS mb
FROM information_schema.tables
WHERE table_schema='tradesignals'
GROUP BY table_schema;"
```

Скопируйте файлы vhost себе локально (через `scp`), чтобы перенести 1:1:

```bash
# с вашей машины
scp root@OLD_IP:/etc/apache2/sites-available/td.1tlt.ru.conf ./
scp root@OLD_IP:/etc/apache2/sites-available/td.1tlt.ru-le-ssl.conf ./
```

---

## 2. Заморозка записи на старом VPS

Чтобы дамп совпал с финальным состоянием:

```bash
# Остановить cron-задачи бота (закомментируйте строки или удалите временно)
crontab -e
# закомментируйте:
# * * * * * flock ... fetch_candles.php ...
# * * * * * flock ... process_signals.php ...

# Дождаться завершения текущих flock (до ~1 минуты)
sleep 70
ls /tmp/tradesignals-*.lock 2>/dev/null || echo "locks free"
```

В админке при необходимости оставьте торговлю выключенной (`trading_enabled=0`).

Старый сайт можно оставить доступным только для чтения/просмотра до переключения DNS; главное — **cron бота не должен писать** после финального дампа.

---

## 3. Дамп базы и бэкап секретов (старый VPS)

```bash
sudo mkdir -p /ssd/backups
STAMP=$(date +%F-%H%M%S)
BACKUP_DIR="/ssd/backups/migrate-${STAMP}"
mkdir -p "$BACKUP_DIR"

# Полный дамп БД (с процедурами/триггерами на всякий случай)
mysqldump -u tradesignals -p -h 127.0.0.1 \
  --single-transaction --routines --triggers --events \
  tradesignals > "$BACKUP_DIR/tradesignals.sql"

# Секреты и служебные файлы (НЕ в Git)
cp -a /ssd/www/tradesignals/config/local.php "$BACKUP_DIR/local.php"
crontab -l > "$BACKUP_DIR/crontab.txt" 2>/dev/null || true

# Опционально: логи и storage
tar -C /var/log -czf "$BACKUP_DIR/tradesignals-logs.tgz" tradesignals 2>/dev/null || true
tar -C /ssd/www/tradesignals -czf "$BACKUP_DIR/storage.tgz" storage 2>/dev/null || true

# Опционально: весь каталог приложения (включая vendor) — если не хотите composer на новом
# tar -C /ssd/www -czf "$BACKUP_DIR/tradesignals-app.tgz" tradesignals

ls -lh "$BACKUP_DIR"
sha256sum "$BACKUP_DIR/tradesignals.sql" > "$BACKUP_DIR/tradesignals.sql.sha256"
```

Скачайте бэкап на безопасное место (или сразу на новый VPS):

```bash
# с вашей машины / на новый сервер
scp -r root@OLD_IP:/ssd/backups/migrate-YYYY-MM-DD-HHMMSS ./
# или напрямую:
scp -r root@OLD_IP:/ssd/backups/migrate-YYYY-MM-DD-HHMMSS root@NEW_IP:/root/
```

Проверка целостности дампа:

```bash
sha256sum -c tradesignals.sql.sha256
```

---

## 4. Базовая подготовка нового VPS

На **новом** сервере:

```bash
sudo apt update
sudo apt install -y apache2 git composer unzip \
  certbot python3-certbot-apache mysql-server mysql-client \
  flock curl
sudo a2enmod rewrite headers ssl proxy proxy_fcgi
```

### PHP 8.2

Production рассчитан на `/usr/local/php82/bin/php` и FPM на порту **9072**.

- **Вариант A (предпочтительно для 1:1):** установите тот же custom PHP 8.2 + php-fpm, что на старом (модули `curl`, `mbstring`, `mysqli`, `pdo_mysql`), listen `127.0.0.1:9072`.
- **Вариант B:** системный PHP ≥ 8.2 из apt — тогда во всех командах и crontab замените путь к бинарнику и handler FPM на фактический.

Проверка:

```bash
PHP_BIN=/usr/local/php82/bin/php   # или /usr/bin/php
"$PHP_BIN" -v
"$PHP_BIN" -m | grep -E 'curl|mbstring|mysqli|pdo_mysql'
ss -lntp | grep -E '9000|9072' || true
```

### `open_basedir`

Добавьте `/ssd/www/tradesignals` в CLI и в FPM pool (как в DEPLOY.md), не удаляя чужие пути:

```ini
open_basedir=/ssd/www/tradesignals:/usr/local/bin:/tmp:/usr/local/php82:/dev/urandom
```

После правок FPM:

```bash
sudo systemctl restart php82-fpm   # имя unit может отличаться
"$PHP_BIN" -i | grep open_basedir
```

### Каталоги

```bash
sudo mkdir -p /ssd/www /ssd/backups /var/log/tradesignals
sudo chown www-data:www-data /var/log/tradesignals
```

---

## 5. MySQL на новом VPS

Создайте ту же БД и пользователя. Пароль возьмите **из старого** `config/local.php` (чтобы файл секретов не менять):

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

Восстановите дамп (полная схема + данные — `schema.sql` / миграции отдельно **не** гоняйте, они уже внутри дампа):

```bash
mysql -u tradesignals -p -h 127.0.0.1 tradesignals < /root/migrate-.../tradesignals.sql
mysql -u tradesignals -p -h 127.0.0.1 tradesignals -e "SHOW TABLES; SELECT COUNT(*) AS admins FROM admins; SELECT COUNT(*) AS candles FROM candles; SELECT COUNT(*) AS signals FROM signals;"
```

Сверьте примерные счётчики со старым сервером.

---

## 6. Код приложения на новом VPS

### Рекомендуемый способ (Git + тот же commit)

```bash
sudo git clone https://github.com/alexevil1979/tradesignals.git /ssd/www/tradesignals
cd /ssd/www/tradesignals

# Закрепить тот же commit, что был на старом (из шага 1)
sudo git checkout <COMMIT_SHA_СО_СТАРОГО>

sudo chown -R "$USER":www-data /ssd/www/tradesignals

PHP_BIN=/usr/local/php82/bin/php
COMPOSER_BIN="$(command -v composer)"
export COMPOSER_HOME=/tmp/tradesignals-composer
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$COMPOSER_HOME"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
```

### Альтернатива: rsync каталога со старого (максимально 1:1, включая vendor)

```bash
# на новом, от root
rsync -aHAX --info=progress2 \
  root@OLD_IP:/ssd/www/tradesignals/ /ssd/www/tradesignals/
```

После любого способа **обязательно** положите секреты:

```bash
cp /root/migrate-.../local.php /ssd/www/tradesignals/config/local.php
chmod 640 /ssd/www/tradesignals/config/local.php
chown "$USER":www-data /ssd/www/tradesignals/config/local.php

mkdir -p storage/logs storage/locks
touch storage/logs/.gitkeep storage/locks/.gitkeep
# если копировали storage.tgz:
# tar -C /ssd/www/tradesignals -xzf /root/migrate-.../storage.tgz

# Права
find /ssd/www/tradesignals -type d -exec chmod 755 {} \;
find /ssd/www/tradesignals -type f -exec chmod 644 {} \;
chmod 640 /ssd/www/tradesignals/config/local.php
# vendor/bin и cron должны оставаться исполняемыми через php, не через chmod +x обязательно
```

Проверка, что PHP видит БД:

```bash
cd /ssd/www/tradesignals
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" -r 'require "vendor/autoload.php"; $c=require "config/config.php"; echo $c["database"]["host"]."|".$c["database"]["name"]."|".$c["database"]["user"]."|".strlen((string)$c["database"]["password"]).PHP_EOL;'
mysql -u tradesignals -p -h 127.0.0.1 tradesignals -e "SELECT 1"
"$PHP_BIN" bin/test_telegram.php   # если токен/прокси верны
```

Админа заново создавать **не нужно** — он уже в дампе.

---

## 7. Apache на новом VPS

1. Скопируйте vhost со старого (или создайте по образцу из DEPLOY.md).
2. `DocumentRoot` = `/ssd/www/tradesignals/public`.
3. PHP handler — тот же FPM, что на старом (`proxy:fcgi://127.0.0.1:9072` или `ProxyPassMatch` с полным путём).

```bash
sudo cp td.1tlt.ru.conf /etc/apache2/sites-available/
# SSL-конфиг пока можно отложить до certbot; либо скопировать le-ssl и поправить пути к сертификатам после выпуска

sudo a2ensite td.1tlt.ru.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Проверка **по IP** до смены DNS (подставьте IP нового VPS):

```bash
echo '<?php echo PHP_VERSION, " ", __FILE__;' > /ssd/www/tradesignals/public/phpver.php
chmod 644 /ssd/www/tradesignals/public/phpver.php
curl -s -H 'Host: td.1tlt.ru' http://127.0.0.1/phpver.php
# должно быть 8.2.x и путь к public/phpver.php
rm /ssd/www/tradesignals/public/phpver.php
```

Если `No input file specified` — см. раздел Apache в [DEPLOY.md](DEPLOY.md) (`ProxyFCGISetEnvIf` / `ProxyPassMatch`, `open_basedir` в FPM).

---

## 8. Cron на новом VPS

Пока DNS не переключён, cron на новом можно **не** включать (чтобы два сервера не торговали/не слали дубли в Telegram). Перед cutover вставьте те же строки, что на старом:

```bash
sudo mkdir -p /var/log/tradesignals
sudo chown www-data:www-data /var/log/tradesignals
crontab -e
```

```cron
* * * * * flock -n /tmp/tradesignals-candles.lock /usr/local/php82/bin/php /ssd/www/tradesignals/cron/fetch_candles.php >> /var/log/tradesignals/candles.log 2>&1
* * * * * flock -n /tmp/tradesignals-signals.lock /usr/local/php82/bin/php /ssd/www/tradesignals/cron/process_signals.php >> /var/log/tradesignals/signals.log 2>&1
```

Ручной прогон (один раз для проверки, ещё без DNS):

```bash
cd /ssd/www/tradesignals
/usr/local/php82/bin/php cron/fetch_candles.php
/usr/local/php82/bin/php cron/process_signals.php
tail -n 50 /var/log/tradesignals/candles.log /var/log/tradesignals/signals.log
```

---

## 9. Telegram-прокси (если был)

Если в `local.php` указано `'proxy' => 'socks5h://127.0.0.1:1080'`, на новом VPS должен слушаться тот же локальный прокси (как в botfabric), иначе Telegram не уйдёт.

```bash
ss -lntp | grep 1080
/usr/local/php82/bin/php /ssd/www/tradesignals/bin/test_telegram.php
```

Либо временно смените `proxy` на рабочий endpoint / пустую строку, если новый VPS имеет прямой доступ к `api.telegram.org`.

---

## 10. Bybit IP whitelist

Если у API-ключа ограничение по IP:

1. Узнайте публичный IP нового VPS: `curl -4 ifconfig.me`.
2. В кабинете Bybit добавьте новый IP (и при необходимости оставьте старый до полного cutover).
3. Только после этого включайте торговые вызовы на новом сервере.

---

## 11. Cutover: DNS → SSL → только новый сервер

### 11.1. Финальный догон дампа (если после первого бэкапа на старом снова писали)

Если cron на старом уже остановлен и прошло мало времени — достаточно первого дампа. Иначе повторите шаг 3 и заново импортируйте на новый (осторожно: `DROP`/пересоздание БД).

### 11.2. DNS

В DNS-провайдере смените A-запись `td.1tlt.ru` на IP **нового** VPS. TTL лучше заранее уменьшить (300 с).

Проверка:

```bash
dig +short td.1tlt.ru
# должен стать NEW_IP
```

### 11.3. SSL

На **новом** VPS (когда A-запись уже указывает сюда):

```bash
sudo certbot --apache -d td.1tlt.ru --redirect --agree-tos -m YOUR_EMAIL@example.com
sudo systemctl status certbot.timer
curl -I https://td.1tlt.ru/
curl -I https://td.1tlt.ru/admin/
```

Старые сертификаты со старого сервера **не** переносятся как основной сценарий — проще выпустить заново через certbot.

### 11.4. Включить cron только на новом

Убедитесь, что на **старом** crontab бота закомментирован и Apache можно остановить или отключить сайт:

```bash
# на старом
crontab -e   # строки tradesignals закомментированы
sudo a2dissite td.1tlt.ru.conf td.1tlt.ru-le-ssl.conf
sudo systemctl reload apache2
# опционально: sudo systemctl stop apache2
```

На **новом** раскомментируйте/добавьте crontab (шаг 8).

### 11.5. Проверочный чеклист

| Проверка | Команда / действие | Ожидание |
|----------|--------------------|----------|
| HTTPS | `curl -I https://td.1tlt.ru/` | 200/302, сертификат валиден |
| Админка | вход под тем же логином/паролем | сессия работает |
| PHP версия | временный `phpver.php` | 8.2.x |
| Свечи | cron / ручной `fetch_candles.php` | рост строк / свежий `updated_at` |
| Сигналы | `process_signals.php` | без фатальных ошибок в логе |
| Telegram | `bin/test_telegram.php` | сообщение уходит |
| Mobile API | `POST /api/mobile/login.php` | токен (как в DEPLOY.md) |
| Bybit | при необходимости | нет ошибок auth/IP |

После успеха можно хранить старый VPS 24–48 ч как cold backup, затем удалить БД/ключи только когда уверены.

---

## 12. Откат

Если новый сервер не готов:

1. Верните A-запись DNS на IP **старого** VPS.
2. На старом раскомментируйте crontab и включите Apache/vhost.
3. На новом остановите cron и сайт, чтобы не было двойной торговли / дублей Telegram.

Дамп из шага 3 остаётся точкой восстановления.

---

## 13. Частые ошибки

| Симптом | Причина | Что сделать |
|---------|---------|-------------|
| Composer / «PHP >= 8.2» в браузере | Apache бьёт в старый FPM `:9000` | handler на `:9072` / тот же PHP, что CLI |
| `No input file specified` | FPM не видит `SCRIPT_FILENAME` / `open_basedir` | `ProxyPassMatch` или `ProxyFCGISetEnvIf`; путь в `open_basedir` |
| Access denied MySQL | другой пароль / только `@localhost` vs `@127.0.0.1` | оба GRANT + тот же пароль, что в `local.php` |
| Пустая админка / нет стратегий | восстановили только `schema.sql`, не дамп | импортировать полный `tradesignals.sql` |
| Дубли сигналов/Telegram | cron крутится на двух VPS | оставить cron только на одном |
| Bybit 403 / IP error | whitelist | добавить IP нового VPS |
| Telegram timeout | нет прокси | поднять SOCKS или убрать `proxy` в `local.php` |

---

## Краткая шпаргалка порядка

1. Инвентаризация старого → стоп cron.  
2. Дамп БД + копия `config/local.php` + crontab + (опционально) vhost/logs.  
3. Новый VPS: PHP 8.2, MySQL, Apache, каталоги, `open_basedir`.  
4. Создать БД/user → restore дампа.  
5. Код (git checkout того же commit **или** rsync) + положить `local.php`.  
6. Vhost + проверка по Host/IP.  
7. Bybit IP + Telegram proxy.  
8. DNS → certbot → cron только на новом → стоп старого.  
9. Чеклист из п. 11.5.

После миграции дальнейшие обновления кода — по разделу «Обновление из Git» в [DEPLOY.md](DEPLOY.md).
