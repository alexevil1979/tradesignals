<?php
declare(strict_types=1);

use App\Bybit\Client;
use App\Bybit\InstrumentService;
use App\Bybit\OrderService;
use App\Bybit\PositionService;
use App\Database\SettingsRepository;
use App\Strategy\CandleAnalyzer;
use App\Strategy\CandleRepository;
use App\Strategy\GridManager;
use App\Strategy\RuleEngine;
use App\Strategy\SignalRepository;
use App\Strategy\StrategyRepository;
use App\Strategy\TradingProcessor;
use App\Telegram\Bot;

require dirname(__DIR__) . '/bootstrap.php';

$settings = new SettingsRepository($pdo);
if ($settings->get('bot_paused', '1') === '1') {
    echo "Бот на паузе.\n";
    exit;
}

$strategies = (new StrategyRepository($pdo))->active();
if (count($strategies) > 1) {
    $message = 'Для безопасности пока поддерживается только одна активная стратегия на BTCUSDT.';
    $logger->error($message, ['active_strategies' => count($strategies)], 'cron');
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
if ($strategies === []) {
    echo "Нет активных стратегий.\n";
    exit;
}

$strategy = $strategies[0];
$candles = (new CandleRepository($pdo))->latestConfirmed(
    $config['bybit']['symbol'],
    $strategy['interval_code'],
    $strategy['max_count'] + 1,
);
if (count($candles) < $strategy['min_count'] + 1) {
    echo "Недостаточно закрытых свечей.\n";
    exit;
}

$bybitConfig = $config['bybit'] + ['max_retries' => $config['trading']['max_api_retries']];
$client = new Client($bybitConfig, $logger);
$processor = new TradingProcessor(
    new RuleEngine(new CandleAnalyzer()),
    new GridManager(),
    new SignalRepository($pdo),
    new OrderService($client, $pdo, $logger),
    new PositionService($client, $pdo),
    new InstrumentService($client),
    new Bot($config['telegram'], $logger),
    $logger,
    $settings->get('trading_enabled', '0') === '1',
);
$processor->process($strategy, $bybitConfig['symbol'], $candles);
$logger->info('Обработка сигналов завершена.', ['strategy_id' => $strategy['id']], 'cron');
echo "Обработка стратегии #{$strategy['id']} завершена.\n";
