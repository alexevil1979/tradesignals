<?php
declare(strict_types=1);

/*
 * Не храните рабочие ключи в репозитории.
 * Значения из таблицы settings могут безопасно переопределить
 * параметры подключённой базы данных через SettingsRepository.
 */
$config = [
    'app' => [
        'env' => getenv('APP_ENV') ?: 'production',
        'base_url' => getenv('APP_BASE_URL') ?: 'https://example.com',
        'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
        'session_name' => 'bybit_bot_session',
    ],
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'bybit_bot',
        'user' => getenv('DB_USER') ?: 'bybit_bot',
        'password' => getenv('DB_PASSWORD') ?: 'change_me',
        'charset' => 'utf8mb4',
    ],
    'bybit' => [
        'api_key' => getenv('BYBIT_API_KEY') ?: '',
        'api_secret' => getenv('BYBIT_API_SECRET') ?: '',
        'testnet' => filter_var(getenv('BYBIT_TESTNET') ?: true, FILTER_VALIDATE_BOOL),
        'category' => 'linear',
        'symbol' => 'BTCUSDT',
        'recv_window' => 5000,
        'timeout' => 15,
    ],
    'telegram' => [
        'token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
        'chat_id' => getenv('TELEGRAM_CHAT_ID') ?: '',
    ],
    'trading' => [
        'candle_interval' => '1',
        'polling_interval_seconds' => 60,
        'max_api_retries' => 3,
    ],
];

// Локальный production-файл остаётся за пределами Git и может переопределять
// только необходимые параметры, включая API-ключи и пароль БД.
$localConfigFile = __DIR__ . '/local.php';
if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
    if (!is_array($localConfig)) {
        throw new RuntimeException('config/local.php должен возвращать массив настроек.');
    }
    $config = array_replace_recursive($config, $localConfig);
}

return $config;
