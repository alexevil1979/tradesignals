<?php
declare(strict_types=1);

/**
 * Диагностика сигналов:
 *   php bin/debug_signals.php
 */
use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Strategy\CandleAnalyzer;
use App\Strategy\CandleRepository;
use App\Strategy\SignalGridConfig;
use App\Telegram\Bot;

require dirname(__DIR__) . '/bootstrap.php';

$settings = new SettingsRepository($pdo);
$symbol = (string) $config['bybit']['symbol'];
$paused = $settings->get('bot_paused', '1') === '1';
$bot = new Bot($config['telegram'], $logger);
$proxy = $bot->resolveProxy();

echo "symbol={$symbol}\n";
echo 'bot_paused=' . ($paused ? '1 (СИГНАЛЫ НЕ ИДУТ)' : '0') . "\n";
echo 'telegram.token=' . (trim((string) ($config['telegram']['token'] ?? '')) !== '' ? 'set' : 'EMPTY') . "\n";
echo 'telegram.chat_id=' . (trim((string) ($config['telegram']['chat_id'] ?? '')) !== '' ? 'set' : 'EMPTY') . "\n";
echo 'proxy=' . ($proxy !== '' ? $proxy : '(direct)') . "\n\n";

$raw = $settings->get(SignalGridConfig::SETTING_KEY);
$decoded = null;
if (is_string($raw) && $raw !== '') {
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decoded = null;
    }
}
$grid = SignalGridConfig::normalize($decoded);
$fromDb = is_string($raw) && $raw !== '';
echo 'signal_grid source=' . ($fromDb ? 'settings DB' : 'defaults') . "\n\n";

$repo = new CandleRepository($pdo);
$analyzer = new CandleAnalyzer();
$map = Intervals::chartMap();

foreach (SignalGridConfig::TIMEFRAMES as $tf) {
    $enabled = [];
    foreach ($grid['timeframes'][$tf] ?? [] as $row) {
        if (!empty($row['signal'])) {
            $enabled[] = (int) $row['bars'];
        }
    }
    if ($enabled === []) {
        continue;
    }

    $code = $map[$tf];
    $minBody = (float) ($grid['min_body'][$tf] ?? 0);
    $candles = $repo->latestConfirmed($symbol, $code, 30);
    $seq = $analyzer->currentSequence($candles, $minBody);
    $last = $candles === [] ? null : $candles[array_key_last($candles)];
    $openTime = $last['open_time'] ?? null;
    $closed = false;
    if (is_string($openTime)) {
        $openTs = strtotime($openTime . ' UTC');
        $closed = $openTs !== false && time() >= ($openTs + Intervals::durationSeconds($code));
    }

    $count = (int) ($seq['count'] ?? 0);
    $matchLevel = null;
    foreach ($enabled as $bars) {
        if ($count >= $bars && ($matchLevel === null || $bars > $matchLevel)) {
            $matchLevel = $bars;
        }
    }

    echo "=== {$tf} (interval {$code}) ===\n";
    echo '  min_body=' . $minBody . "\n";
    echo '  signal rows bars=[' . implode(',', $enabled) . "]\n";
    echo '  candles_confirmed=' . count($candles) . "\n";
    echo '  last_open_time=' . ($openTime ?? '—') . ' closed=' . ($closed ? 'yes' : 'no') . "\n";
    echo '  sequence=' . ($seq['label'] ?? '—') . ' reason=' . ($seq['reason'] ?? '—') . "\n";
    echo '  would_match_level=' . ($matchLevel !== null ? (string) $matchLevel : 'NO') . "\n\n";
}

echo "Готово. Если bot_paused=1 — снимите паузу.\n";
echo "Сигнал срабатывает, если длина серии >= любому включённому уровню bars, включая 1 (берётся максимальный подходящий).\n";
echo "Запуск обработки: php cron/process_signals.php\n";
