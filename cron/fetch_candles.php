<?php
declare(strict_types=1);

use App\Bybit\KlineService;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $bybitConfig = $config['bybit'];
    if (($bybitConfig['category'] ?? '') !== 'linear' || ($bybitConfig['symbol'] ?? '') !== 'BTCUSDT') {
        throw new RuntimeException('Ожидается инструмент BTCUSDT USDT Perpetual (category=linear).');
    }

    $settings = new SettingsRepository($pdo);
    // Котировки всегда с mainnet api.bybit.com — как в example/bbb/bothour/bybit_fill_tables.php
    $service = new KlineService($pdo, (string) $bybitConfig['category'], $settings);
    $total = 0;

    foreach (Intervals::codes() as $interval) {
        $result = $service->syncInterval($bybitConfig['symbol'], $interval);
        $total += $result['saved'];
        $logger->info('Свечи синхронизированы (mainnet).', [
            'interval' => $interval,
            'saved' => $result['saved'],
            'mode' => $result['mode'],
            'pages' => $result['pages'],
        ], 'cron');
        echo "[MAINNET] Интервал {$interval}: сохранено {$result['saved']} (режим {$result['mode']}, страниц {$result['pages']})\n";
    }

    echo "Всего сохранено/обновлено свечей: {$total}\n";
} catch (Throwable $exception) {
    $logger->error('Не удалось обновить свечи.', ['error' => $exception->getMessage()], 'cron');
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
