<?php
declare(strict_types=1);

use App\Bybit\Client;
use App\Bybit\KlineService;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $bybitConfig = $config['bybit'] + ['max_retries' => $config['trading']['max_api_retries']];
    if (($bybitConfig['category'] ?? '') !== 'linear' || ($bybitConfig['symbol'] ?? '') !== 'BTCUSDT') {
        throw new RuntimeException('Ожидается инструмент BTCUSDT USDT Perpetual (category=linear).');
    }

    $settings = new SettingsRepository($pdo);
    $service = new KlineService(
        new Client($bybitConfig, $logger),
        $pdo,
        (string) $bybitConfig['category'],
        $settings,
    );
    $total = 0;

    foreach (Intervals::codes() as $interval) {
        $result = $service->syncInterval($bybitConfig['symbol'], $interval);
        $total += $result['saved'];
        $status = $result['history_complete'] ? 'история полная' : 'история ещё догружается';
        $network = !empty($bybitConfig['testnet']) ? 'TESTNET' : 'MAINNET';
        $logger->info('Свечи синхронизированы.', [
            'interval' => $interval,
            'saved' => $result['saved'],
            'mode' => $result['mode'],
            'pages' => $result['pages'],
            'history_complete' => $result['history_complete'],
            'network' => $network,
        ], 'cron');
        echo "[{$network}] Интервал {$interval}: сохранено {$result['saved']} (режим {$result['mode']}, страниц {$result['pages']}, {$status})\n";
    }

    echo "Всего сохранено/обновлено свечей: {$total}\n";
} catch (Throwable $exception) {
    $logger->error('Не удалось обновить свечи.', ['error' => $exception->getMessage()], 'cron');
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
