# Production-деплой: td.1tlt.ru

Инструкция рассчитана на Linux VPS с Apache, PHP 8.2+ и уже установленным MySQL 5.7.8+.

> До включения торгов оставьте Bybit Testnet и параметр `trading_enabled=0`. У API-ключа Bybit не должно быть разрешения на вывод средств.

## 1. DNS и системные пакеты

До выпуска сертификата A-запись `td.1tlt.ru` должна указывать на IP VPS, а порты 80 и 443 быть доступны извне.

```bash
sudo apt update
sudo apt install -y apache2 git composer unzip \
  certbot python3-certbot-apache mysql-client
sudo a2enmod rewrite headers ssl
```

На целевом VPS PHP 8.2 расположен в `/usr/local/php82/bin/php`. Проверьте версии:

```bash
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" -v
"$PHP_BIN" -m | grep -E 'curl|mbstring|mysqli|pdo_mysql'
mysql --version
```

`PHP_BIN` должен показывать PHP не ниже 8.2, а вывод модулей — `curl`, `mbstring`, `mysqli` и `pdo_mysql`. MySQL нужен версии не ниже 5.7.8, поскольку схема использует тип `JSON`. В MySQL 5.7 конструкции `CHECK` принимаются, но сервер их не применяет: корректность параметров стратегий проверяется приложением.

### `open_basedir` для custom PHP

На VPS custom PHP может ограничивать доступ к каталогам через `open_basedir`. Путь `/ssd/www/tradesignals` обязан быть в списке разрешённых, иначе CLI не сможет подключить даже `bootstrap.php`.

```bash
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" --ini
"$PHP_BIN" -i | grep open_basedir
```

В загруженном `php.ini` или дополнительном `.ini` добавьте пути к новому проекту и Composer, не удаляя существующие пути:

```ini
open_basedir=/ssd/www/tradesignals:/usr/local/bin:/tmp:/usr/local/php82:/dev/urandom
```

Если на сервере уже перечислены другие сайты, сохраните их в значении через `:`. После изменения повторно проверьте:

```bash
"$PHP_BIN" -i | grep open_basedir
```

## 2. Создание базы

Замените сильный пароль и сохраните его для `config/local.php`:

```bash
mysql -u root -p <<'SQL'
CREATE DATABASE tradesignals CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tradesignals'@'127.0.0.1' IDENTIFIED BY 'qweasd333123';
CREATE USER 'tradesignals'@'localhost' IDENTIFIED BY 'qweasd333123';
GRANT ALL PRIVILEGES ON tradesignals.* TO 'tradesignals'@'127.0.0.1';
GRANT ALL PRIVILEGES ON tradesignals.* TO 'tradesignals'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Создаются оба локальных пользователя, поскольку MySQL может авторизовать loopback-подключение как `localhost` даже при `host = 127.0.0.1` в PHP-конфигурации.

## 3. Клонирование приложения

```bash
sudo mkdir -p /ssd/www
sudo git clone https://github.com/alexevil1979/tradesignals.git /ssd/www/tradesignals
sudo chown -R "$USER":www-data /ssd/www/tradesignals
cd /ssd/www/tradesignals
PHP_BIN=/usr/local/php82/bin/php
COMPOSER_BIN="$(command -v composer)"
export COMPOSER_HOME=/tmp/tradesignals-composer
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$COMPOSER_HOME"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
mysql -u tradesignals -p -h 127.0.0.1 tradesignals < sql/schema.sql
cp config/local.php.example config/local.php
chmod 640 config/local.php
chown "$USER":www-data config/local.php
```

Команды выше допустимы при deploy от `root`: `COMPOSER_HOME` перенаправлен в разрешённый `/tmp`, а `COMPOSER_ALLOW_SUPERUSER=1` отключает интерактивное предупреждение. В обычной конфигурации предпочтительнее отдельный непривилегированный пользователь для деплоя.

Отредактируйте `config/local.php`: задайте пароль базы, ключи Bybit и Telegram. Этот файл исключён из Git и находится выше `public/`, поэтому не отдаётся веб-сервером.

Проверьте, что PHP читает именно эти учётные данные и что пароль совпадает с MySQL:

```bash
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" -r 'require "vendor/autoload.php"; $c=require "config/config.php"; echo $c["database"]["host"]."|".$c["database"]["name"]."|".$c["database"]["user"]."|".strlen((string)$c["database"]["password"]).PHP_EOL;'
mysql -u tradesignals -p -h 127.0.0.1 tradesignals -e "SELECT 1"
mysql -u tradesignals -p -h localhost tradesignals -e "SELECT 1"
```

Если CLI с `-h 127.0.0.1` проходит, а PHP нет — в `config/local.php` пароль отличается от введённого вручную. Если `host` в конфиге `localhost`, PDO использует Unix-сокет и учётную запись `tradesignals@localhost`.

Создайте администратора:

```bash
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" bin/create_admin.php admin 'ПАРОЛЬ_ДЛИНОЙ_НЕ_МЕНЕЕ_12_СИМВОЛОВ'
```

## 4. Apache

Сайт обязан обслуживаться тем же PHP 8.2, что и CLI (`/usr/local/php82`). Если Apache использует старый PHP, в браузере появится:

`Composer detected issues in your platform: Your Composer dependencies require a PHP version ">= 8.2.0".`

На этом VPS:
- порт `9000` — старый PHP-FPM (не подходит);
- порт `9072` — PHP 8.2 из `/usr/local/php82` (как у `testtelega`).

В `/etc/apache2/sites-enabled/td.1tlt.ru-le-ssl.conf` (и HTTP-конфиге, если есть) замените:

```apache
SetHandler "proxy:fcgi://127.0.0.1:9000"
```

на:

```apache
SetHandler "proxy:fcgi://127.0.0.1:9072"
```

Затем:

```bash
apachectl configtest
systemctl reload apache2
```

Проверьте версию PHP именно в веб-контексте:

```bash
echo '<?php echo PHP_VERSION;' > /ssd/www/tradesignals/public/phpver.php
# затем: https://td.1tlt.ru/phpver.php  — должно быть 8.2.x
rm /ssd/www/tradesignals/public/phpver.php
```

Проверьте handler и FPM:

```bash
ls /usr/local/php82/sbin/php-fpm /usr/local/php82/var/run/*.sock 2>/dev/null
ss -lntp | grep -E '9000|9072'
grep -n SetHandler /etc/apache2/sites-enabled/td.1tlt.ru*.conf
apachectl -M | grep -E 'proxy|php|fcgid'
```

Если нужен новый vhost с нуля, используйте `DocumentRoot /ssd/www/tradesignals/public` и handler на `9072`:

```apache
<VirtualHost *:80>
    ServerName td.1tlt.ru
    DocumentRoot /ssd/www/tradesignals/public

    <Directory /ssd/www/tradesignals/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:fcgi://127.0.0.1:9072"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/td.1tlt.ru-error.log
    CustomLog ${APACHE_LOG_DIR}/td.1tlt.ru-access.log combined
</VirtualHost>
```

Включите сайт и модули proxy:

```bash
sudo a2enmod proxy proxy_fcgi
sudo a2ensite td.1tlt.ru.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

После смены handler снова откройте `phpver.php`: версия должна начинаться с `8.2`.

Если браузер показывает `No input file specified` при уже верном `DocumentRoot` и порте `9072`, добавьте в SSL-vhost:

```apache
DirectoryIndex index.php

<FilesMatch \.php$>
    SetHandler "proxy:fcgi://127.0.0.1:9072"
</FilesMatch>
ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "%{reqenv:DOCUMENT_ROOT}%{reqenv:SCRIPT_NAME}"
```

Быстрая правка на сервере:

```bash
python3 - <<'PY'
from pathlib import Path
path = Path('/etc/apache2/sites-enabled/td.1tlt.ru-le-ssl.conf')
text = path.read_text()
needle = '''    <Directory /ssd/www/tradesignals/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>


     <FilesMatch "\\.php$">
                        SetHandler "proxy:fcgi://127.0.0.1:9072"
                    </FilesMatch>'''
replacement = '''    DirectoryIndex index.php

    <Directory /ssd/www/tradesignals/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    <FilesMatch \\.php$>
        SetHandler "proxy:fcgi://127.0.0.1:9072"
    </FilesMatch>
    ProxyFCGISetEnvIf "true" SCRIPT_FILENAME "%{reqenv:DOCUMENT_ROOT}%{reqenv:SCRIPT_NAME}"'''
if needle not in text:
    raise SystemExit('block not found; edit file manually')
path.write_text(text.replace(needle, replacement, 1))
print('updated')
PY
apachectl configtest
systemctl reload apache2
echo '<?php echo PHP_VERSION, " ", __FILE__;' > /ssd/www/tradesignals/public/phpver.php
chown -R www-data:www-data /ssd/www/tradesignals/public
find /ssd/www/tradesignals -type d -exec chmod 755 {} \;
find /ssd/www/tradesignals -type f -exec chmod 644 {} \;
chmod 640 /ssd/www/tradesignals/config/local.php
# проверьте https://td.1tlt.ru/phpver.php и https://td.1tlt.ru/admin/index.php
```

Если всё ещё пусто, сравните pool FPM с `testtelega` и правами файлов:

```bash
ls -la /ssd/www/tradesignals/public/admin/index.php
namei -l /ssd/www/tradesignals/public/admin/index.php
grep -R "listen\|open_basedir\|user\|group\|chdir" /usr/local/php82/etc/php-fpm.d/ 2>/dev/null | head -n 50
```

Важно: если в браузере открыт `http://td.1tlt.ru/...` (без HTTPS), используется HTTP-vhost, а не только `td.1tlt.ru-le-ssl.conf`. Проверьте оба файла:

```bash
ls -la /etc/apache2/sites-enabled/td.1tlt.ru*
sed -n '1,80p' /etc/apache2/sites-enabled/td.1tlt.ru.conf
sed -n '1,80p' /etc/apache2/sites-enabled/td.1tlt.ru-le-ssl.conf
```

Если `SetHandler` всё ещё даёт `No input file specified` даже для `/phpver.php`, замените handler на явный `ProxyPassMatch` с полным путём к файлу (часто надёжнее на custom PHP-FPM):

```apache
DocumentRoot /ssd/www/tradesignals/public
DirectoryIndex index.php

<Directory /ssd/www/tradesignals/public>
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>

ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://127.0.0.1:9072/ssd/www/tradesignals/public/$1
```

Удалите блок `<FilesMatch>...</FilesMatch>` / `SetHandler`, чтобы не было двойной обработки. Примените и в HTTP, и в HTTPS vhost:

```bash
apachectl configtest
systemctl reload apache2
# проверяйте именно https://td.1tlt.ru/phpver.php
```

## 5. SSL Let's Encrypt

```bash
sudo certbot --apache -d td.1tlt.ru --redirect --agree-tos -m YOUR_EMAIL@example.com
sudo systemctl status certbot.timer
```

Проверьте:

```bash
curl -I https://td.1tlt.ru/
curl -I https://td.1tlt.ru/admin/
```

## 6. Cron

Создайте каталог логов:

```bash
sudo mkdir -p /var/log/tradesignals
sudo chown www-data:www-data /var/log/tradesignals
```

Откройте crontab пользователя, от которого развёрнуто приложение:

```bash
crontab -e
```

```cron
* * * * * flock -n /tmp/tradesignals-candles.lock /usr/local/php82/bin/php /ssd/www/tradesignals/cron/fetch_candles.php >> /var/log/tradesignals/candles.log 2>&1
* * * * * flock -n /tmp/tradesignals-signals.lock /usr/local/php82/bin/php /ssd/www/tradesignals/cron/process_signals.php >> /var/log/tradesignals/signals.log 2>&1
```

Проверьте запуск вручную:

```bash
cd /ssd/www/tradesignals
/usr/local/php82/bin/php cron/fetch_candles.php
/usr/local/php82/bin/php cron/process_signals.php
```

## 7. Обновление из Git

Перед обновлением создайте дамп базы. Не используйте `git reset --hard`, чтобы не потерять `config/local.php`.

```bash
mysqldump -u tradesignals -p -h 127.0.0.1 tradesignals > "/ssd/backups/tradesignals-$(date +%F-%H%M%S).sql"
cd /ssd/www/tradesignals
git pull --ff-only origin main
PHP_BIN=/usr/local/php82/bin/php
COMPOSER_BIN="$(command -v composer)"
export COMPOSER_HOME=/tmp/tradesignals-composer
export COMPOSER_ALLOW_SUPERUSER=1
mkdir -p "$COMPOSER_HOME"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader
sudo systemctl reload apache2
```

При появлении новой миграции применяйте её один раз, например:

```bash
mysql -u tradesignals -p -h 127.0.0.1 tradesignals < sql/001_signal_idempotency.sql
```

## 8. Проверка перед включением торгов

1. Убедитесь, что в `config/local.php` `testnet => true`.
2. Войдите на `https://td.1tlt.ru/admin/`.
3. Импортируйте тестовую стратегию только в testnet:
   ```bash
   mysql -u tradesignals -p -h 127.0.0.1 tradesignals < sql/dev_strategy.sql
   ```
4. Оставьте `trading_enabled=0`, проверьте запись свечей, сигналов и Telegram.
5. Включайте `trading_enabled=1` только после проверки объёмов, TP/SL и разрешений API-ключа.
