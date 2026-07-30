<?php
declare(strict_types=1);

use App\Bybit\Client;
use App\Bybit\KlineService;
use App\Helpers\Intervals;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $bybitConfig = $config['bybit'] + ['max_retries' => $config['trading']['max_api_retries']];
    $service = new KlineService(new Client($bybitConfig, $logger), $pdo);
    $total = 0;

    foreach (Intervals::codes() as $interval) {
        $candles = $service->fetch($bybitConfig['symbol'], $interval, 100);
        $saved = $service->save($bybitConfig['symbol'], $interval, $candles);
        $total += $saved;
        $logger->info('Свечи обновлены.', [
            'interval' => $interval,
            'count' => $saved,
        ], 'cron');
        echo "Интервал {$interval}: сохранено {$saved}\n";
    }

    echo "Всего свечей: {$total}\n";
} catch (Throwable $exception) {
    $logger->error('Не удалось обновить свечи.', ['error' => $exception->getMessage()], 'cron');
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
