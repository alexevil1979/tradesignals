<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Bybit\Client;
use App\Bybit\InstrumentService;
use App\Bybit\KlineService;
use App\Bybit\OrderService;
use App\Bybit\PositionService;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Strategy\CandleAnalyzer;
use App\Strategy\CandleRepository;
use App\Strategy\DirectionGridProcessor;
use App\Strategy\LevelGridProcessor;
use App\Strategy\MaTouchProcessor;
use App\Strategy\RangeAlertProcessor;
use App\Strategy\SignalGridProcessor;
use App\Strategy\SignalRepository;
use App\Telegram\Bot;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
header('Content-Type: application/json; charset=utf-8');

if (!$auth->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Только POST.'], JSON_THROW_ON_ERROR);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
if (!$auth->verifyCsrf(is_string($csrf) ? $csrf : null)) {
    http_response_code(419);
    echo json_encode(['error' => 'Недействительный CSRF-токен.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $bybitConfig = $config['bybit'];
    $symbol = (string) $bybitConfig['symbol'];
    $settings = new SettingsRepository($pdo);
    $service = new KlineService($pdo, (string) $bybitConfig['category'], $settings);
    $results = [];
    $total = 0;

    foreach (Intervals::codes() as $interval) {
        $result = $service->syncInterval($symbol, $interval);
        $results[$interval] = $result;
        $total += $result['saved'];
    }

    $logger->info('Котировки обновлены с Dashboard (1 мин).', ['saved' => $total], 'quotes');

    $signalsCreated = 0;
    $signalsSkipped = null;
    $botPaused = $settings->get('bot_paused', '1') === '1';

    if ($botPaused) {
        $signalsSkipped = 'bot_paused';
        $logger->info('Сигналы пропущены: бот на паузе.', [], 'trading');
    } else {
        // Lock внутри проекта: /tmp часто закрыт open_basedir у PHP-FPM.
        $lockDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $lockPath = $lockDir . DIRECTORY_SEPARATOR . 'tradesignals-signals.lock';
        $lock = @fopen($lockPath, 'c+');
        $locked = false;

        if ($lock === false) {
            $logger->warning('Lock сигналов недоступен, обрабатываем без блокировки.', [
                'path' => $lockPath,
            ], 'trading');
        } elseif (!flock($lock, LOCK_EX | LOCK_NB)) {
            $signalsSkipped = 'busy';
            fclose($lock);
            $lock = false;
            $logger->info('Сигналы пропущены: уже выполняется другая обработка.', [], 'trading');
        } else {
            $locked = true;
        }

        if ($signalsSkipped !== 'busy') {
            try {
                $signalRepo = new SignalRepository($pdo);
                $candleRepo = new CandleRepository($pdo);
                $telegram = new Bot($config['telegram'], $logger);
                $processor = new SignalGridProcessor(
                    $settings,
                    $candleRepo,
                    new CandleAnalyzer(),
                    $signalRepo,
                    $telegram,
                    $logger,
                );
                $barCreated = $processor->process($symbol);
                $levelProcessor = new LevelGridProcessor(
                    $settings,
                    $candleRepo,
                    $signalRepo,
                    $telegram,
                    $logger,
                );
                $levelCreated = $levelProcessor->process($symbol);
                $rangeCreated = (new RangeAlertProcessor(
                    $settings,
                    $candleRepo,
                    $signalRepo,
                    $telegram,
                    $logger,
                ))->process($symbol);
                $maTouchCreated = (new MaTouchProcessor(
                    $settings,
                    $candleRepo,
                    $signalRepo,
                    $telegram,
                    $logger,
                ))->process($symbol);

                $directionCreated = 0;
                try {
                    $bybitConfig = $config['bybit'] + ['max_retries' => $config['trading']['max_api_retries']];
                    $client = new Client($bybitConfig, $logger);
                    $instruments = new InstrumentService($client);
                    $directionCreated = (new DirectionGridProcessor(
                        $settings,
                        $candleRepo,
                        new OrderService($client, $pdo, $logger, $instruments),
                        new PositionService($client, $pdo),
                        $instruments,
                        $telegram,
                        $logger,
                        $settings->get('trading_enabled', '0') === '1',
                    ))->process($symbol);
                } catch (Throwable $exception) {
                    $logger->error('Direction grid: ошибка обработки с Dashboard.', [
                        'error' => $exception->getMessage(),
                    ], 'trading');
                }

                $signalsCreated = $barCreated + $levelCreated + $rangeCreated + $maTouchCreated + $directionCreated;
                $logger->info('Обработка матрицы сигналов с Dashboard.', [
                    'symbol' => $symbol,
                    'created' => $signalsCreated,
                    'bars' => $barCreated,
                    'levels' => $levelCreated,
                    'range' => $rangeCreated,
                    'ma_touch' => $maTouchCreated,
                    'direction_grid' => $directionCreated,
                ], 'cron');
            } finally {
                if (is_resource($lock)) {
                    if ($locked) {
                        flock($lock, LOCK_UN);
                    }
                    fclose($lock);
                }
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'saved' => $total,
        'intervals' => $results,
        'signals_created' => $signalsCreated,
        'signals_skipped' => $signalsSkipped,
        'bot_paused' => $botPaused,
        'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $logger->error('Ошибка автообновления котировок.', ['error' => $exception->getMessage()], 'quotes');
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
