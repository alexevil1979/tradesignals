<?php
declare(strict_types=1);

use App\Bybit\Client;
use App\Bybit\InstrumentService;
use App\Bybit\KlineService;
use App\Bybit\OrderService;
use App\Bybit\PositionService;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Strategy\CandleAnalyzer;
use App\Strategy\CandleRepository;
use App\Strategy\GridManager;
use App\Strategy\RuleEngine;
use App\Strategy\DirectionGridProcessor;
use App\Strategy\LevelGridProcessor;
use App\Strategy\PriceChannelConfig;
use App\Strategy\PriceChannelProcessor;
use App\Strategy\MaTouchConfig;
use App\Strategy\MaTouchProcessor;
use App\Strategy\RangeAlertProcessor;
use App\Strategy\SignalGridConfig;
use App\Strategy\SignalGridProcessor;
use App\Strategy\SignalRepository;
use App\Strategy\StrategyRepository;
use App\Strategy\TradingProcessor;
use App\Telegram\Bot;

require dirname(__DIR__) . '/bootstrap.php';

$settings = new SettingsRepository($pdo);
if ($settings->get('bot_paused', '1') === '1') {
    $logger->warning('process_signals: бот на паузе (bot_paused=1).', [], 'cron');
    echo "Бот на паузе (settings.bot_paused=1). Сигналы не обрабатываются.\n";
    echo "Снимите паузу: UPDATE settings SET setting_value='0' WHERE setting_key='bot_paused';\n";
    exit;
}

$symbol = (string) $config['bybit']['symbol'];
$telegram = new Bot($config['telegram'], $logger);
$candleRepository = new CandleRepository($pdo);
$signalRepository = new SignalRepository($pdo);

// Перед проверкой сигналов подтягиваем свежие закрытые бары (иначе M1 часто «не видит» только что закрытую минуту).
try {
    $klineService = new KlineService($pdo, (string) $config['bybit']['category'], $settings);
    $rawGrid = $settings->get(SignalGridConfig::SETTING_KEY);
    $decodedGrid = null;
    if (is_string($rawGrid) && $rawGrid !== '') {
        try {
            $decodedGrid = json_decode($rawGrid, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decodedGrid = null;
        }
    }
    $grid = SignalGridConfig::normalize($decodedGrid);
    $rawMa = $settings->get(MaTouchConfig::SETTING_KEY);
    $decodedMa = null;
    if (is_string($rawMa) && $rawMa !== '') {
        try {
            $decodedMa = json_decode($rawMa, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decodedMa = null;
        }
    }
    $maTouch = MaTouchConfig::normalize($decodedMa);
    $rawPc = $settings->get(PriceChannelConfig::SETTING_KEY);
    $decodedPc = null;
    if (is_string($rawPc) && $rawPc !== '') {
        try {
            $decodedPc = json_decode($rawPc, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decodedPc = null;
        }
    }
    $priceChannel = PriceChannelConfig::normalize($decodedPc);
    $map = Intervals::chartMap();
    $tfsToSync = [];
    foreach (SignalGridConfig::TIMEFRAMES as $tf) {
        foreach ($grid['timeframes'][$tf] ?? [] as $row) {
            if (!empty($row['signal'])) {
                $tfsToSync[$tf] = true;
                break;
            }
        }
        if (!empty($maTouch['timeframes'][$tf])) {
            $tfsToSync[$tf] = true;
        }
        if (!empty($priceChannel['timeframes'][$tf])) {
            $tfsToSync[$tf] = true;
        }
    }
    foreach (array_keys($tfsToSync) as $tf) {
        $code = $map[$tf] ?? null;
        if ($code === null) {
            continue;
        }
        $sync = $klineService->syncInterval($symbol, $code);
        echo "Синхр. {$tf} ({$code}): {$sync['saved']} баров\n";
    }
} catch (Throwable $exception) {
    $logger->error('Не удалось синхронизировать свечи перед сигналами.', [
        'error' => $exception->getMessage(),
    ], 'cron');
    echo "Предупреждение: синхронизация свечей не удалась: {$exception->getMessage()}\n";
}

$gridProcessor = new SignalGridProcessor(
    $settings,
    $candleRepository,
    new CandleAnalyzer(),
    $signalRepository,
    $telegram,
    $logger,
);
$gridCreated = $gridProcessor->process($symbol);
$levelCreated = (new LevelGridProcessor(
    $settings,
    $candleRepository,
    $signalRepository,
    $telegram,
    $logger,
))->process($symbol);
$rangeCreated = (new RangeAlertProcessor(
    $settings,
    $candleRepository,
    $signalRepository,
    $telegram,
    $logger,
))->process($symbol);

$bybitConfig = $config['bybit'] + ['max_retries' => $config['trading']['max_api_retries']];
$client = new Client($bybitConfig, $logger);
$instruments = new InstrumentService($client);
$orderService = new OrderService($client, $pdo, $logger, $instruments);
$positionService = new PositionService($client, $pdo);
$tradingEnabled = $settings->get('trading_enabled', '0') === '1';

$maTouchCreated = (new MaTouchProcessor(
    $settings,
    $candleRepository,
    $signalRepository,
    $telegram,
    $logger,
    $orderService,
    $positionService,
    $instruments,
    $tradingEnabled,
))->process($symbol);

$directionCreated = (new DirectionGridProcessor(
    $settings,
    $candleRepository,
    $orderService,
    $positionService,
    $instruments,
    $telegram,
    $logger,
    $tradingEnabled,
))->process($symbol);

$priceChannelCreated = (new PriceChannelProcessor(
    $settings,
    $candleRepository,
    $signalRepository,
    $telegram,
    $logger,
))->process($symbol);

$logger->info('Обработка матрицы сигналов завершена.', [
    'symbol' => $symbol,
    'created' => $gridCreated + $levelCreated + $rangeCreated + $maTouchCreated + $directionCreated + $priceChannelCreated,
    'bars' => $gridCreated,
    'levels' => $levelCreated,
    'range' => $rangeCreated,
    'ma_touch' => $maTouchCreated,
    'direction_grid' => $directionCreated,
    'price_channel' => $priceChannelCreated,
], 'cron');
echo "Матрица сигналов: бары {$gridCreated}, уровни {$levelCreated}, диапазон {$rangeCreated}, MA28 {$maTouchCreated}, сетка {$directionCreated}, PC {$priceChannelCreated}.\n";

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

$processor = new TradingProcessor(
    new RuleEngine(new CandleAnalyzer()),
    new GridManager(),
    $signalRepository,
    $orderService,
    $positionService,
    $instruments,
    $telegram,
    $logger,
    $tradingEnabled,
);
$processor->process($strategy, $symbol, $candles);
$logger->info('Обработка классической стратегии завершена.', ['strategy_id' => $strategy['id']], 'cron');
echo "Обработка стратегии #{$strategy['id']} завершена.\n";
