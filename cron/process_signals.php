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
use App\Strategy\SignalGridProcessor;
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

$symbol = (string) $config['bybit']['symbol'];
$telegram = new Bot($config['telegram'], $logger);
$candleRepository = new CandleRepository($pdo);
$signalRepository = new SignalRepository($pdo);

$gridProcessor = new SignalGridProcessor(
    $settings,
    $candleRepository,
    new CandleAnalyzer(),
    $signalRepository,
    $telegram,
    $logger,
);
$gridCreated = $gridProcessor->process($symbol);
$logger->info('Обработка матрицы сигналов завершена.', [
    'symbol' => $symbol,
    'created' => $gridCreated,
], 'cron');
echo "Матрица сигналов: создано {$gridCreated}.\n";

$strategies = (new StrategyRepository($pdo))->active();
if (count($strategies) > 1) {
    $message = 'Для безопасности пока поддерживается только одна активная стратегия на BTCUSDT.';
    $logger->error($message, ['active_strategies' => count($strategies)], 'cron');
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

if ($strategies === []) {
    echo "Классических активных стратегий нет.\n";
    exit;
}

$strategy = $strategies[0];
$candles = $candleRepository->latestConfirmed(
    $symbol,
    $strategy['interval_code'],
    $strategy['max_count'] + 1,
);
if (count($candles) < $strategy['min_count'] + 1) {
    echo "Недостаточно закрытых свечей для классической стратегии.\n";
    exit;
}

$bybitConfig = $config['bybit'] + ['max_retries' => $config['trading']['max_api_retries']];
$client = new Client($bybitConfig, $logger);
$processor = new TradingProcessor(
    new RuleEngine(new CandleAnalyzer()),
    new GridManager(),
    $signalRepository,
    new OrderService($client, $pdo, $logger),
    new PositionService($client, $pdo),
    new InstrumentService($client),
    $telegram,
    $logger,
    $settings->get('trading_enabled', '0') === '1',
);
$processor->process($strategy, $symbol, $candles);
$logger->info('Обработка классической стратегии завершена.', ['strategy_id' => $strategy['id']], 'cron');
echo "Обработка стратегии #{$strategy['id']} завершена.\n";
