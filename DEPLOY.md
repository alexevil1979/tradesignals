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
CREATE USER 'tradesignals'@'127.0.0.1' IDENTIFIED BY 'CHANGE_STRONG_DB_PASSWORD';
CREATE USER 'tradesignals'@'localhost' IDENTIFIED BY 'CHANGE_STRONG_DB_PASSWORD';
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

Создайте администратора:

```bash
PHP_BIN=/usr/local/php82/bin/php
"$PHP_BIN" bin/create_admin.php admin 'ПАРОЛЬ_ДЛИНОЙ_НЕ_МЕНЕЕ_12_СИМВОЛОВ'
```

## 4. Apache

Создайте `/etc/apache2/sites-available/td.1tlt.ru.conf`:

```apache
<VirtualHost *:80>
    ServerName td.1tlt.ru
    DocumentRoot /ssd/www/tradesignals/public

    <Directory /ssd/www/tradesignals/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/td.1tlt.ru-error.log
    CustomLog ${APACHE_LOG_DIR}/td.1tlt.ru-access.log combined
</VirtualHost>
```

Включите сайт и проверьте конфигурацию:

```bash
sudo a2dissite 000-default.conf
sudo a2ensite td.1tlt.ru.conf
sudo apachectl configtest
sudo systemctl reload apache2
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
