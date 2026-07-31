<?php
declare(strict_types=1);

use App\Bybit\KlineService;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;

require dirname(__DIR__) . '/bootstrap.php';

$bybitConfig = $config['bybit'];
if (($bybitConfig['category'] ?? '') !== 'linear' || ($bybitConfig['symbol'] ?? '') !== 'BTCUSDT') {
    fwrite(STDERR, "Ожидается BTCUSDT USDT Perpetual (category=linear).\n");
    exit(1);
}

$settings = new SettingsRepository($pdo);
$service = new KlineService($pdo, (string) $bybitConfig['category'], $settings);

$deleted = $service->clearAll($bybitConfig['symbol']);
echo "Удалено кривых/старых свечей: {$deleted}\n";
echo "Начинаем первичную загрузку по логике example (mainnet, до 5000 баров на ТФ)...\n\n";

$total = 0;
foreach (Intervals::codes() as $interval) {
    $result = $service->syncInterval($bybitConfig['symbol'], $interval);
    $total += $result['saved'];
    echo "Интервал {$interval}: сохранено {$result['saved']} (режим {$result['mode']}, страниц {$result['pages']})\n";
}

echo "\nГотово. Всего сохранено: {$total}\n";
