# Bybit Grid Bot

Начальный каркас бота для USDT-перпетуалов Bybit v5 (по умолчанию `BTCUSDT`) и панели управления на чистом PHP 8.2.

> **Важно:** торговля сопряжена с финансовым риском. По умолчанию включён testnet и бот находится на паузе. До реальной торговли необходимо протестировать стратегию и сверить точность объёмов с правилами Bybit.

## Требования

- PHP 8.2+ с расширениями `curl`, `pdo_mysql`, `json`;
- Composer 2;
- MySQL 5.7.8+ (рекомендуется MySQL 8);
- Apache 2.4+ с `mod_rewrite` и SSL.

## Локальный запуск в WSL 2

Локальное окружение запускается через Docker Desktop с интеграцией в используемый дистрибутив WSL 2. Это предпочтительный способ разработки: не нужно устанавливать PHP, Apache и MySQL внутри WSL вручную.

```bash
# В терминале WSL:
cd /mnt/c/Users/1/Documents/newsignals
cp .env.example .env
docker compose up --build -d
docker compose exec app php bin/create_admin.php admin 'ЗаменитеНаДлинныйПароль'
```

После запуска панель доступна по `http://localhost:8080/admin/`. База MySQL доступна на порту `3307` хоста, чтобы не конфликтовать с локальным MySQL.

Проверьте сервисы и журнал:

```bash
docker compose ps
docker compose logs -f app
```

Для теста без размещения сделок добавьте тестовую стратегию в MySQL, оставьте `bot_paused=0` и `trading_enabled=0`, а затем выполните:

```bash
docker compose exec -T db mysql -ubybit_bot -plocal_change_me bybit_bot < sql/dev_strategy.sql
docker compose exec app php cron/fetch_candles.php
docker compose exec app php cron/process_signals.php
```

`trading_enabled=0` — обязательный безопасный режим: сигналы сохраняются и могут уйти в Telegram, но ордера не создаются. Переключать на `1` можно только после проверки Bybit Testnet и API-ключей. Для `localhost` сертификат Let's Encrypt выдать нельзя; TLS настраивается только для публичного домена в production.

## Установка

Для production-развёртывания на VPS с доменом, Apache и Let's Encrypt см. [DEPLOY.md](DEPLOY.md).

1. Скопируйте `config/config.php` и задайте переменные окружения `DB_*`, `BYBIT_*`, `TELEGRAM_*`. Не добавляйте реальные секреты в Git.
2. Установите автозагрузчик:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. Создайте базу и таблицы:
   ```bash
   mysql -u root -p bybit_bot < sql/schema.sql
   ```
4. Создайте администратора:
   ```bash
   php bin/create_admin.php admin 'СложныйПарольНеКороче12'
   ```
5. Укажите `public/` в качестве `DocumentRoot` виртуального хоста Apache и перезапустите Apache.

## Apache и SSL

Пример виртуального хоста:

```apache
<VirtualHost *:80>
    ServerName bot.example.com
    DocumentRoot /var/www/bot/public
</VirtualHost>
```

Получите сертификат после направления DNS домена на сервер:

```bash
sudo certbot --apache -d bot.example.com
```

В production установите `APP_BASE_URL=https://bot.example.com`. Сертификат автоматически обновляется службой Certbot.

## Cron

Запуск каждую минуту:

```cron
* * * * * /usr/bin/php /var/www/bot/cron/fetch_candles.php >> /var/log/bybit-bot-candles.log 2>&1
* * * * * /usr/bin/php /var/www/bot/cron/process_signals.php >> /var/log/bybit-bot-signals.log 2>&1
```

## Bybit и Telegram

1. Сначала создайте ключ Bybit testnet без прав на вывод средств и включите `BYBIT_TESTNET=true`.
2. Создайте бота через `@BotFather`, задайте токен и `TELEGRAM_CHAT_ID`.
3. После реализации диспетчера команд настройте webhook на `https://ваш-домен/webhook.php`.

## Текущий этап

Созданы схема БД, конфигурация, PSR-4-структура, клиент Bybit с подписью v5 и повторными запросами, сохранение подтверждённых свечей, обработка сигналов с идемпотентностью, базовый движок правил и сетки, Telegram-клиент, защищённый вход и начальная страница Dashboard.

Пока сознательно допускается только одна активная стратегия для `BTCUSDT`: на одном аккаунте Bybit позиция по символу общая, и параллельные независимые сетки могли бы закрывать сделки друг друга. Мультистратегийный режим потребует отдельной модели распределения позиций или выделенных субаккаунтов.
