<?php
declare(strict_types=1);

use App\Bybit\Client;
use App\Bybit\KlineService;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $bybitConfig = $config['bybit'] + ['max_retries' => $config['trading']['max_api_retries']];
    $service = new KlineService(new Client($bybitConfig, $logger), $pdo);
    $candles = $service->fetch($bybitConfig['symbol'], $config['trading']['candle_interval']);
    $saved = $service->save($bybitConfig['symbol'], $config['trading']['candle_interval'], $candles);
    $logger->info('Свечи успешно обновлены.', ['count' => $saved], 'cron');
    echo "Сохранено свечей: {$saved}\n";
} catch (Throwable $exception) {
    $logger->error('Не удалось обновить свечи.', ['error' => $exception->getMessage()], 'cron');
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
