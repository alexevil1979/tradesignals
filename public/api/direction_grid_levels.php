<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Database\SettingsRepository;
use App\Strategy\CandleRepository;
use App\Strategy\DirectionGridConfig;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

$rawBody = file_get_contents('php://input');
$payload = null;
if (is_string($rawBody) && $rawBody !== '') {
    try {
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $payload = null;
    }
}
if (!is_array($payload)) {
    $payload = $_POST;
}

$settings = new SettingsRepository($pdo);
$raw = $settings->get(DirectionGridConfig::SETTING_KEY);
$decoded = null;
if (is_string($raw) && $raw !== '') {
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decoded = null;
    }
}
$configGrid = DirectionGridConfig::normalize($decoded);
$prevSig = DirectionGridConfig::signature($configGrid);

$postedLevels = is_array($payload['levels'] ?? null) ? $payload['levels'] : [];
for ($i = 0; $i < 3; $i++) {
    $row = is_array($postedLevels[$i] ?? null) ? $postedLevels[$i] : [];
    if (isset($row['offset']) && is_numeric($row['offset'])) {
        $configGrid['levels'][$i]['offset'] = max(0.01, 0 + $row['offset']);
    }
}

if (isset($payload['profit']) && is_numeric($payload['profit'])) {
    $configGrid['profit'] = max(0.01, 0 + $payload['profit']);
}
if (isset($payload['stop']) && is_numeric($payload['stop'])) {
    $configGrid['stop'] = max(0.01, 0 + $payload['stop']);
}

$configGrid = DirectionGridConfig::normalize($configGrid);
$settings->set(
    DirectionGridConfig::SETTING_KEY,
    json_encode($configGrid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
);

$rawState = $settings->get(DirectionGridConfig::STATE_KEY);
$decodedState = null;
if (is_string($rawState) && $rawState !== '') {
    try {
        $decodedState = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedState = null;
    }
}
$state = DirectionGridConfig::normalizeState($decodedState);

$newSig = DirectionGridConfig::signature($configGrid);
if ($prevSig !== $newSig) {
    $state['force_rebuild'] = true;
    $mode = (string) $configGrid['mode'];
    $anchor = isset($state['anchor']) && is_numeric($state['anchor'])
        ? (float) $state['anchor']
        : null;
    if ($anchor === null) {
        $symbol = (string) $config['bybit']['symbol'];
        $ext = (new CandleRepository($pdo))->extremumLastMinutes(
            $symbol,
            '1',
            (int) $configGrid['period_minutes']
        );
        if ($ext !== null) {
            $anchor = $mode === 'low' ? (float) $ext['low'] : (float) $ext['high'];
            $state['anchor'] = $anchor;
        }
    }
    if ($anchor !== null) {
        $newLevels = [];
        foreach ($state['levels'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx1 = (int) ($row['index'] ?? 0);
            $idx0 = ($idx1 >= 1 && $idx1 <= 3) ? $idx1 - 1 : max(0, $idx1);
            $offset = (float) ($configGrid['levels'][$idx0]['offset'] ?? 0);
            $row['price'] = $mode === 'low' ? $anchor + $offset : $anchor - $offset;
            $newLevels[] = $row;
        }
        if ($newLevels === []) {
            for ($i = 0; $i < 3; $i++) {
                $offset = (float) ($configGrid['levels'][$i]['offset'] ?? 0);
                $newLevels[] = [
                    'index' => $i + 1,
                    'link_id' => '',
                    'status' => 'New',
                    'price' => $mode === 'low' ? $anchor + $offset : $anchor - $offset,
                ];
            }
        }
        $state['levels'] = $newLevels;
        if (empty($state['wait_close']) && empty($state['filled_any'])) {
            $state['tp'] = $mode === 'low'
                ? $anchor - (float) $configGrid['profit']
                : $anchor + (float) $configGrid['profit'];
            $state['sl'] = $mode === 'low'
                ? $anchor + (float) $configGrid['stop']
                : $anchor - (float) $configGrid['stop'];
        }
    }
    $settings->set(
        DirectionGridConfig::STATE_KEY,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    );
}

$symbol = (string) $config['bybit']['symbol'];
$extremum = (new CandleRepository($pdo))->extremumLastMinutes(
    $symbol,
    '1',
    (int) $configGrid['period_minutes']
);
$overlay = DirectionGridConfig::chartOverlay($configGrid, $state, $extremum);

echo json_encode([
    'ok' => true,
    'direction_grid' => $overlay,
    'config' => [
        'profit' => $configGrid['profit'],
        'stop' => $configGrid['stop'],
        'levels' => array_map(
            static fn (array $row): array => [
                'offset' => $row['offset'],
                'size' => $row['size'],
            ],
            $configGrid['levels']
        ),
    ],
], JSON_THROW_ON_ERROR);
