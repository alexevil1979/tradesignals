# TradeSignals Android

Мобильный клиент для управления ботом. Все расчёты и торговля выполняются на сервере (`td.1tlt.ru`); приложение — контроль и мониторинг по REST API.

## Требования

- Android Studio Hedgehog+ / JDK 17
- Android 8.0+ (API 26)
- На VPS применена миграция `sql/002_api_tokens.sql`

## Миграция на сервере

```bash
cd /ssd/www/tradesignals
git pull --ff-only origin main
mysql -u tradesignals -p -h 127.0.0.1 tradesignals < sql/002_api_tokens.sql
```

## Сборка APK

```bash
cd android
./gradlew :app:assembleDebug
# артефакт: app/build/outputs/apk/debug/app-debug.apk
```

На Windows:

```bat
cd android
gradlew.bat :app:assembleDebug
```

## Первый запуск

1. Укажите базовый URL API, например `https://td.1tlt.ru`
2. Войдите логином/паролем админки
3. Dashboard обновляется каждые 5 с; звук L1 — при срабатывании уровней со флагом «звук»

## API (Bearer token)

| Метод | Путь | Назначение |
|-------|------|------------|
| POST | `/api/mobile/login.php` | Логин → token |
| POST | `/api/mobile/logout.php` | Отзыв токена |
| GET | `/api/mobile/dashboard.php` | Цена, бот, сетка, ордера, сигналы |
| POST | `/api/mobile/bot.php` | `paused` / `trading_enabled` |
| GET/POST | `/api/mobile/strategies.php` | Чтение/сохранение стратегий |
| POST | `/api/mobile/direction_grid.php` | Отступы/свитчи сетки |
| GET | `/api/mobile/alert.php` | Триггер звука L* |
| GET | `/api/mobile/candles.php` | Свечи |
| GET | `/api/mobile/orders.php` | Ордера и позиции |
| GET | `/api/mobile/signals.php` | Сигналы |
| GET | `/api/mobile/logs.php` | Логи |

Заголовок: `Authorization: Bearer <token>`
