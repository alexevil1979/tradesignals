<?php
declare(strict_types=1);

use App\Database\SettingsRepository;
use App\Strategy\CandleRepository;
use App\Strategy\DirectionGridConfig;
use App\Strategy\MaTouchConfig;
use App\Strategy\PriceChannelConfig;
use App\Strategy\RangeAlertConfig;
use App\Strategy\SignalGridConfig;
use App\Strategy\LevelGridConfig;

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$readJson = $api['readJson'];
$pdo = $api['pdo'];
$config = $api['config'];

$settings = new SettingsRepository($pdo);

$loadJson = static function (SettingsRepository $settings, string $key): mixed {
    $raw = $settings->get($key);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    try {
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $direction = DirectionGridConfig::normalize($loadJson($settings, DirectionGridConfig::SETTING_KEY));
    $directionState = DirectionGridConfig::normalizeState($loadJson($settings, DirectionGridConfig::STATE_KEY));
    $symbol = (string) $config['bybit']['symbol'];
    $extremum = (new CandleRepository($pdo))->extremumLastMinutes(
        $symbol,
        '1',
        (int) $direction['period_minutes']
    );

    $json([
        'ok' => true,
        'strategies' => [
            'direction_grid' => $direction,
            'direction_grid_state' => [
                'anchor' => $directionState['anchor'],
                'filled_any' => $directionState['filled_any'],
                'wait_close' => $directionState['wait_close'],
                'stopped' => $directionState['stopped'],
                'force_rebuild' => $directionState['force_rebuild'],
            ],
            'direction_grid_overlay' => DirectionGridConfig::chartOverlay($direction, $directionState, $extremum),
            'ma_touch' => MaTouchConfig::normalize($loadJson($settings, MaTouchConfig::SETTING_KEY)),
            'price_channel' => PriceChannelConfig::normalize($loadJson($settings, PriceChannelConfig::SETTING_KEY)),
            'range_alert' => RangeAlertConfig::normalize($loadJson($settings, RangeAlertConfig::SETTING_KEY)),
            'signal_grid' => SignalGridConfig::normalize($loadJson($settings, SignalGridConfig::SETTING_KEY)),
            'level_grid' => LevelGridConfig::normalize($loadJson($settings, LevelGridConfig::SETTING_KEY)),
        ],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $json(['ok' => false, 'error' => 'Метод не поддерживается.'], 405);
}

$body = $readJson();
$section = (string) ($body['section'] ?? '');
$saved = [];

if ($section === 'direction_grid' || isset($body['direction_grid'])) {
    $incoming = is_array($body['direction_grid'] ?? null) ? $body['direction_grid'] : $body;
    $prev = DirectionGridConfig::normalize($loadJson($settings, DirectionGridConfig::SETTING_KEY));
    $next = DirectionGridConfig::normalize($incoming);
    // Сохраняем sound/telegram/size из incoming с нормализацией через merge.
    if (isset($incoming['levels']) && is_array($incoming['levels'])) {
        $levels = [];
        for ($i = 0; $i < 3; $i++) {
            $row = is_array($incoming['levels'][$i] ?? null) ? $incoming['levels'][$i] : [];
            $levels[] = [
                'offset' => $row['offset'] ?? $next['levels'][$i]['offset'],
                'size' => $row['size'] ?? $next['levels'][$i]['size'],
                'sound' => array_key_exists('sound', $row) ? !empty($row['sound']) : !empty($next['levels'][$i]['sound']),
                'telegram' => array_key_exists('telegram', $row) ? !empty($row['telegram']) : !empty($next['levels'][$i]['telegram']),
            ];
        }
        $next = DirectionGridConfig::normalize(array_merge($next, ['levels' => $levels]));
    }
    $settings->set(DirectionGridConfig::SETTING_KEY, json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    $state = DirectionGridConfig::normalizeState($loadJson($settings, DirectionGridConfig::STATE_KEY));
    if (!empty($next['enabled']) && !empty($state['stopped'])) {
        $state['stopped'] = false;
    }
    if (DirectionGridConfig::signature($prev) !== DirectionGridConfig::signature($next)) {
        $state['force_rebuild'] = true;
    }
    $settings->set(DirectionGridConfig::STATE_KEY, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $saved['direction_grid'] = $next;
}

if ($section === 'ma_touch' || isset($body['ma_touch'])) {
    $incoming = is_array($body['ma_touch'] ?? null) ? $body['ma_touch'] : [];
    $next = MaTouchConfig::normalize($incoming);
    $settings->set(MaTouchConfig::SETTING_KEY, json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $saved['ma_touch'] = $next;
}

if ($section === 'price_channel' || isset($body['price_channel'])) {
    $incoming = is_array($body['price_channel'] ?? null) ? $body['price_channel'] : [];
    $next = PriceChannelConfig::normalize($incoming);
    $settings->set(PriceChannelConfig::SETTING_KEY, json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $saved['price_channel'] = $next;
}

if ($section === 'range_alert' || isset($body['range_alert'])) {
    $incoming = is_array($body['range_alert'] ?? null) ? $body['range_alert'] : [];
    $next = RangeAlertConfig::normalize($incoming);
    $settings->set(RangeAlertConfig::SETTING_KEY, json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $saved['range_alert'] = $next;
}

if ($saved === []) {
    $json(['ok' => false, 'error' => 'Укажите section или блок стратегии для сохранения.'], 422);
}

$json(['ok' => true, 'saved' => $saved]);
