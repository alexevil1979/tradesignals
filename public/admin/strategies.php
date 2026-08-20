<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Database\SettingsRepository;
use App\Strategy\CandleRepository;
use App\Strategy\DirectionGridConfig;
use App\Strategy\LevelGridConfig;
use App\Strategy\MaTouchConfig;
use App\Strategy\RangeAlertConfig;
use App\Strategy\SignalGridConfig;
use App\Helpers\ChartUiState;
use App\Helpers\Intervals;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
$auth->requireLogin();

$settings = new SettingsRepository($pdo);
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!$auth->verifyCsrf($token)) {
        $flash = 'Неверный CSRF-токен. Обновите страницу и попробуйте снова.';
        $flashType = 'danger';
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'reset_bars') {
            $grid = SignalGridConfig::defaults();
            $settings->set(SignalGridConfig::SETTING_KEY, json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $flash = 'Сетка баров сброшена к значениям из Excel.';
        } elseif ($action === 'reset_levels') {
            $levelGrid = LevelGridConfig::defaults();
            $settings->set(LevelGridConfig::SETTING_KEY, json_encode($levelGrid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $flash = 'Сетка уровней очищена.';
        } elseif ($action === 'reset_range') {
            $rangeAlert = RangeAlertConfig::defaults();
            $settings->set(RangeAlertConfig::SETTING_KEY, json_encode($rangeAlert, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $settings->set(RangeAlertConfig::STATE_KEY, json_encode(RangeAlertConfig::defaultState(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $flash = 'Диапазон сброшен.';
        } elseif ($action === 'reset_ma_touch') {
            $maTouch = MaTouchConfig::defaults();
            $settings->set(MaTouchConfig::SETTING_KEY, json_encode($maTouch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $settings->set(MaTouchConfig::STATE_KEY, json_encode(MaTouchConfig::defaultState(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $flash = 'Стратегия MA28 сброшена.';
        } elseif ($action === 'reset_direction_grid') {
            $directionGrid = DirectionGridConfig::defaults();
            $settings->set(DirectionGridConfig::SETTING_KEY, json_encode($directionGrid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $settings->set(DirectionGridConfig::STATE_KEY, json_encode(DirectionGridConfig::defaultState(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $flash = 'Стратегия слежения сброшена.';
        } else {
            $grid = SignalGridConfig::fromPost($_POST);
            $settings->set(SignalGridConfig::SETTING_KEY, json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $levelGrid = LevelGridConfig::fromPost($_POST);
            $settings->set(LevelGridConfig::SETTING_KEY, json_encode($levelGrid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $rangeAlert = RangeAlertConfig::fromPost($_POST);
            $settings->set(RangeAlertConfig::SETTING_KEY, json_encode($rangeAlert, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $maTouch = MaTouchConfig::fromPost($_POST);
            $settings->set(MaTouchConfig::SETTING_KEY, json_encode($maTouch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $prevDirectionRaw = $settings->get(DirectionGridConfig::SETTING_KEY);
            $prevDirectionDecoded = null;
            if (is_string($prevDirectionRaw) && $prevDirectionRaw !== '') {
                try {
                    $prevDirectionDecoded = json_decode($prevDirectionRaw, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    $prevDirectionDecoded = null;
                }
            }
            $prevDirection = DirectionGridConfig::normalize($prevDirectionDecoded);

            $directionGrid = DirectionGridConfig::fromPost($_POST);
            $settings->set(DirectionGridConfig::SETTING_KEY, json_encode($directionGrid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

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
            $stateChanged = false;
            // Ручное включение снимает runtime-stop.
            if (!empty($directionGrid['enabled']) && $state['stopped']) {
                $state['stopped'] = false;
                $stateChanged = true;
            }
            // Смена параметров сетки → перестановка на следующем тике (сохраняем link_id для отмены).
            if (DirectionGridConfig::signature($prevDirection) !== DirectionGridConfig::signature($directionGrid)) {
                $state['force_rebuild'] = true;
                $stateChanged = true;
            }
            if ($stateChanged) {
                $settings->set(DirectionGridConfig::STATE_KEY, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            $flash = 'Стратегии сохранены.';
        }
    }
}

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
$rowCount = max(array_map(
    static fn (string $tf): int => count($grid['timeframes'][$tf]),
    SignalGridConfig::TIMEFRAMES
));

$rawLevels = $settings->get(LevelGridConfig::SETTING_KEY);
$decodedLevels = null;
if (is_string($rawLevels) && $rawLevels !== '') {
    try {
        $decodedLevels = json_decode($rawLevels, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedLevels = null;
    }
}
$levelGrid = LevelGridConfig::normalize($decodedLevels);

$rawRange = $settings->get(RangeAlertConfig::SETTING_KEY);
$decodedRange = null;
if (is_string($rawRange) && $rawRange !== '') {
    try {
        $decodedRange = json_decode($rawRange, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedRange = null;
    }
}
$rangeAlert = RangeAlertConfig::normalize($decodedRange);

$rawMaTouch = $settings->get(MaTouchConfig::SETTING_KEY);
$decodedMaTouch = null;
if (is_string($rawMaTouch) && $rawMaTouch !== '') {
    try {
        $decodedMaTouch = json_decode($rawMaTouch, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedMaTouch = null;
    }
}
$maTouch = MaTouchConfig::normalize($decodedMaTouch);

$rawDirection = $settings->get(DirectionGridConfig::SETTING_KEY);
$decodedDirection = null;
if (is_string($rawDirection) && $rawDirection !== '') {
    try {
        $decodedDirection = json_decode($rawDirection, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedDirection = null;
    }
}
$directionGrid = DirectionGridConfig::normalize($decodedDirection);

$rawDgState = $settings->get(DirectionGridConfig::STATE_KEY);
$decodedDgState = null;
if (is_string($rawDgState) && $rawDgState !== '') {
    try {
        $decodedDgState = json_decode($rawDgState, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedDgState = null;
    }
}
$directionState = DirectionGridConfig::normalizeState($decodedDgState);

$symbol = (string) $config['bybit']['symbol'];
$lastPrice = null;
$range12h = null;
$range24h = null;
$range48h = null;
$dgExtremum = null;
try {
    $candleRepo = new CandleRepository($pdo);
    $m1 = $candleRepo->latestConfirmed($symbol, '1', 1);
    if ($m1 !== []) {
        $lastPrice = (float) $m1[array_key_last($m1)]['close_price'];
    }
    $range12h = $candleRepo->extremumLastHours($symbol, '1', 12);
    $range24h = $candleRepo->extremumLastHours($symbol, '1', 24);
    $range48h = $candleRepo->extremumLastHours($symbol, '1', 48);
    $dgExtremum = $candleRepo->extremumLastMinutes($symbol, '1', (int) $directionGrid['period_minutes']);
} catch (Throwable) {
    $lastPrice = null;
    $range12h = null;
    $range24h = null;
    $range48h = null;
    $dgExtremum = null;
}

$csrfToken = htmlspecialchars($auth->csrfToken(), ENT_QUOTES, 'UTF-8');
$chartNavHref = htmlspecialchars(ChartUiState::chartHref(Intervals::chartMap()), ENT_QUOTES, 'UTF-8');
$minBodyNote = htmlspecialchars(SignalGridConfig::MIN_BODY_NOTE, ENT_QUOTES, 'UTF-8');
$lastPriceLabel = $lastPrice !== null
    ? htmlspecialchars(LevelGridConfig::formatPriceKey($lastPrice), ENT_QUOTES, 'UTF-8')
    : '—';
$low12Label = $range12h !== null
    ? htmlspecialchars(RangeAlertConfig::formatPrice($range12h['low']), ENT_QUOTES, 'UTF-8')
    : null;
$high12Label = $range12h !== null
    ? htmlspecialchars(RangeAlertConfig::formatPrice($range12h['high']), ENT_QUOTES, 'UTF-8')
    : null;
$low24Label = $range24h !== null
    ? htmlspecialchars(RangeAlertConfig::formatPrice($range24h['low']), ENT_QUOTES, 'UTF-8')
    : null;
$high24Label = $range24h !== null
    ? htmlspecialchars(RangeAlertConfig::formatPrice($range24h['high']), ENT_QUOTES, 'UTF-8')
    : null;
$low48Label = $range48h !== null
    ? htmlspecialchars(RangeAlertConfig::formatPrice($range48h['low']), ENT_QUOTES, 'UTF-8')
    : null;
$high48Label = $range48h !== null
    ? htmlspecialchars(RangeAlertConfig::formatPrice($range48h['high']), ENT_QUOTES, 'UTF-8')
    : null;

$dgAnchor = null;
$dgPreviewPrices = [];
$dgPreviewTp = null;
$dgPreviewSl = null;
if ($dgExtremum !== null) {
    $dgAnchor = $directionGrid['mode'] === 'low' ? $dgExtremum['low'] : $dgExtremum['high'];
    foreach ($directionGrid['levels'] as $lvl) {
        $offset = (float) $lvl['offset'];
        $dgPreviewPrices[] = $directionGrid['mode'] === 'low'
            ? $dgAnchor + $offset
            : $dgAnchor - $offset;
    }
    $dgPreviewTp = $directionGrid['mode'] === 'low'
        ? $dgAnchor - (float) $directionGrid['profit']
        : $dgAnchor + (float) $directionGrid['profit'];
    $dgPreviewSl = $directionGrid['mode'] === 'low'
        ? $dgAnchor + (float) $directionGrid['stop']
        : $dgAnchor - (float) $directionGrid['stop'];
}

/**
 * @param list<array<string, mixed>> $rows
 * @param 'above'|'below' $side
 */
$renderLevelRows = static function (array $rows, string $side): void {
    $prefix = $side === 'above' ? 'level_above' : 'level_below';
    if ($rows === []) {
        echo '<tr class="level-grid-empty"><td colspan="7" class="text-secondary small text-center py-3">Нет уровней. Сгенерируйте или добавьте вручную.</td></tr>';

        return;
    }
    foreach ($rows as $i => $row) {
        $name = $prefix . '[' . $i . ']';
        ?>
        <tr>
            <td>
                <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                       type="number" step="any" min="0"
                       name="<?= $name ?>[price]"
                       value="<?= htmlspecialchars((string) $row['price'], ENT_QUOTES, 'UTF-8') ?>">
            </td>
            <td class="text-center">
                <input class="form-check-input" type="checkbox"
                       name="<?= $name ?>[signal]" value="1"
                       data-level-side="<?= $side ?>" data-field="signal"
                    <?= !empty($row['signal']) ? 'checked' : '' ?>>
            </td>
            <td class="text-center">
                <input class="form-check-input" type="checkbox"
                       name="<?= $name ?>[order]" value="1"
                       data-level-side="<?= $side ?>" data-field="order"
                    <?= !empty($row['order']) ? 'checked' : '' ?>>
            </td>
            <td>
                <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                       type="text" inputmode="decimal"
                       name="<?= $name ?>[size]"
                       value="<?= htmlspecialchars((string) $row['size'], ENT_QUOTES, 'UTF-8') ?>">
            </td>
            <td>
                <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                       type="number" step="any"
                       name="<?= $name ?>[reserve]"
                       value="<?= htmlspecialchars((string) $row['reserve'], ENT_QUOTES, 'UTF-8') ?>">
            </td>
            <td>
                <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                       type="number" step="any"
                       name="<?= $name ?>[stop]"
                       value="<?= htmlspecialchars((string) $row['stop'], ENT_QUOTES, 'UTF-8') ?>">
            </td>
            <td>
                <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                       type="number" step="any"
                       name="<?= $name ?>[profit]"
                       value="<?= htmlspecialchars((string) $row['profit'], ENT_QUOTES, 'UTF-8') ?>">
            </td>
        </tr>
        <?php
    }
};
?>
<!doctype html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Стратегии — Bybit Grid Bot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/admin.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin/">Bybit Grid Bot</a>
        <div class="navbar-nav">
            <a class="nav-link" href="/admin/">Dashboard</a>
            <a class="nav-link" href="<?= $chartNavHref ?>">График</a>
            <a class="nav-link active" href="/admin/strategies.php">Стратегии</a>
            <a class="nav-link" href="/admin/orders.php">Ордера</a>
            <a class="nav-link" href="/admin/settings.php">Настройки</a>
            <a class="nav-link" href="/admin/logs.php">Логи</a>
            <a class="nav-link" href="/admin/signals.php">Сигналы</a>
        </div>
    </div>
</nav>
<main class="container-fluid px-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Стратегии</h1>
            <p class="text-secondary mb-0">Сетка баров, уровни, диапазон, MA28 и слежение за хаем/лоем. Сохранение применяется ко всем стратегиям.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" form="strategies-form" name="action" value="save" class="btn btn-success">Сохранить всё</button>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" id="strategies-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

        <div class="accordion strategy-accordion" id="strategiesAccordion">
            <div class="accordion-item bg-black border-secondary mb-3">
                <h2 class="accordion-header">
                    <button class="accordion-button bg-dark text-light" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseBars"
                            aria-expanded="true" aria-controls="collapseBars">
                        Сетка баров
                        <span class="badge text-bg-secondary ms-2 fw-normal">серия свечей</span>
                    </button>
                </h2>
                <div id="collapseBars" class="accordion-collapse collapse show">
                    <div class="accordion-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <p class="text-secondary small mb-0">Уровень <strong>баров</strong> от 1 и выше — сигнал при серии ≥ этого числа.</p>
                            <button type="submit" name="action" value="reset_bars" class="btn btn-sm btn-outline-warning"
                                    onclick="return confirm('Сбросить сетку баров к значениям из Excel?');">Сбросить сетку баров</button>
                        </div>

                        <div class="card bg-black border-secondary mb-3">
                            <div class="card-body py-3">
                                <div class="small text-secondary mb-2"><?= $minBodyNote ?></div>
                                <div class="row g-2 align-items-end">
                                    <?php foreach (SignalGridConfig::TIMEFRAMES as $tf): ?>
                                        <div class="col-6 col-md-4 col-xl-2">
                                            <label class="form-label small text-secondary mb-1" for="min-body-<?= $tf ?>">мин. тело · <?= $tf ?></label>
                                            <input
                                                class="form-control form-control-sm bg-dark text-light border-secondary"
                                                type="number"
                                                step="any"
                                                min="0"
                                                id="min-body-<?= $tf ?>"
                                                name="min_body[<?= $tf ?>]"
                                                value="<?= htmlspecialchars((string) $grid['min_body'][$tf], ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card bg-black border-secondary">
                            <div class="card-body p-0">
                                <div class="table-responsive signal-grid-wrap">
                                    <table class="table table-sm table-dark table-bordered mb-0 align-middle signal-grid">
                                        <thead>
                                        <tr>
                                            <th class="signal-grid-sticky text-secondary" rowspan="2">баров</th>
                                            <?php foreach (SignalGridConfig::TIMEFRAMES as $tf): ?>
                                                <th class="text-center text-info signal-grid-tf-toggle"
                                                    data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-colspan-expanded="7"
                                                    data-colspan-collapsed="2"
                                                    colspan="7"
                                                    title="Клик: свернуть/развернуть <?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr>
                                            <?php foreach (SignalGridConfig::TIMEFRAMES as $tf): ?>
                                                <th class="text-center small" data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="bars">баров</th>
                                                <th class="text-center small signal-grid-toggle"
                                                    data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-field="signal"
                                                    data-col="signal"
                                                    title="Клик: вкл/выкл все сигналы <?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>">сигнал</th>
                                                <th class="text-center small signal-grid-toggle"
                                                    data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-field="order"
                                                    data-col="order"
                                                    title="Клик: вкл/выкл все ордера <?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>">ордер</th>
                                                <th class="text-center small" data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="size">размер</th>
                                                <th class="text-center small" data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="reserve">запас</th>
                                                <th class="text-center small" data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="stop">стоп</th>
                                                <th class="text-center small" data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="profit">профит</th>
                                            <?php endforeach; ?>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php for ($i = 0; $i < $rowCount; $i++): ?>
                                            <tr>
                                                <th class="signal-grid-sticky text-secondary small">#<?= $i + 1 ?></th>
                                                <?php foreach (SignalGridConfig::TIMEFRAMES as $tf): ?>
                                                    <?php
                                                    $row = $grid['timeframes'][$tf][$i] ?? [
                                                        'bars' => 1,
                                                        'signal' => false,
                                                        'order' => false,
                                                        'size' => '0.001',
                                                        'reserve' => 10,
                                                        'stop' => 300,
                                                        'profit' => 300,
                                                    ];
                                                    $prefix = 'tf[' . $tf . '][' . $i . ']';
                                                    ?>
                                                    <td data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="bars">
                                                        <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                                                               type="number" min="1" step="1"
                                                               name="<?= $prefix ?>[bars]"
                                                               value="<?= (int) $row['bars'] ?>">
                                                    </td>
                                                    <td class="text-center" data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="signal">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="<?= $prefix ?>[signal]" value="1"
                                                               data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>"
                                                               data-field="signal"
                                                            <?= !empty($row['signal']) ? 'checked' : '' ?>
                                                               title="сигнал">
                                                    </td>
                                                    <td class="text-center" data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="order">
                                                        <input class="form-check-input" type="checkbox"
                                                               name="<?= $prefix ?>[order]" value="1"
                                                               data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>"
                                                               data-field="order"
                                                            <?= !empty($row['order']) ? 'checked' : '' ?>
                                                               title="ордер">
                                                    </td>
                                                    <td data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="size">
                                                        <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                                                               type="text" inputmode="decimal"
                                                               name="<?= $prefix ?>[size]"
                                                               value="<?= htmlspecialchars((string) $row['size'], ENT_QUOTES, 'UTF-8') ?>">
                                                    </td>
                                                    <td data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="reserve">
                                                        <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                                                               type="number" step="any"
                                                               name="<?= $prefix ?>[reserve]"
                                                               value="<?= htmlspecialchars((string) $row['reserve'], ENT_QUOTES, 'UTF-8') ?>">
                                                    </td>
                                                    <td data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="stop">
                                                        <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                                                               type="number" step="any"
                                                               name="<?= $prefix ?>[stop]"
                                                               value="<?= htmlspecialchars((string) $row['stop'], ENT_QUOTES, 'UTF-8') ?>">
                                                    </td>
                                                    <td data-tf="<?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?>" data-col="profit">
                                                        <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                                                               type="number" step="any"
                                                               name="<?= $prefix ?>[profit]"
                                                               value="<?= htmlspecialchars((string) $row['profit'], ENT_QUOTES, 'UTF-8') ?>">
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item bg-black border-secondary">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed bg-dark text-light" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseLevels"
                            aria-expanded="false" aria-controls="collapseLevels">
                        Сетка уровней
                        <span class="badge text-bg-secondary ms-2 fw-normal">пробои цены</span>
                    </button>
                </h2>
                <div id="collapseLevels" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <p class="text-secondary small mb-0">
                                Пробой уровня по закрытию M1: сверху → LONG, снизу → SHORT.
                                Текущая цена <?= htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') ?>: <strong class="text-info"><?= $lastPriceLabel ?></strong>
                            </p>
                            <div class="d-flex gap-2 align-items-center">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="level_enabled" name="level_enabled" value="1"
                                        <?= !empty($levelGrid['enabled']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="level_enabled">включена</label>
                                </div>
                                <button type="submit" name="action" value="reset_levels" class="btn btn-sm btn-outline-warning"
                                        onclick="return confirm('Очистить все уровни?');">Очистить уровни</button>
                            </div>
                        </div>

                        <div class="card bg-black border-secondary mb-3">
                            <div class="card-body py-3">
                                <div class="row g-2 align-items-end">
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="level-base">базовая цена</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" step="any" id="level-base"
                                               value="<?= $lastPrice !== null ? htmlspecialchars(LevelGridConfig::formatPriceKey($lastPrice), ENT_QUOTES, 'UTF-8') : '' ?>">
                                    </div>
                                    <div class="col-6 col-md-2 col-xl-1">
                                        <label class="form-label small text-secondary mb-1" for="level-step">шаг</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" step="any" min="0.01" id="level-step" value="100">
                                    </div>
                                    <div class="col-6 col-md-2 col-xl-1">
                                        <label class="form-label small text-secondary mb-1" for="level-count-above">сверху</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" min="0" max="50" step="1" id="level-count-above" value="5">
                                    </div>
                                    <div class="col-6 col-md-2 col-xl-1">
                                        <label class="form-label small text-secondary mb-1" for="level-count-below">снизу</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" min="0" max="50" step="1" id="level-count-below" value="5">
                                    </div>
                                    <div class="col-12 col-md-4 col-xl-3 d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-info" id="level-generate">Сгенерировать</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="level-add-above">+ сверху</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="level-add-below">+ снизу</button>
                                    </div>
                                </div>
                                <div class="small text-secondary mt-2">Генерация заменяет текущие уровни. Не забудьте сохранить.</div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="card bg-black border-secondary h-100">
                                    <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                                        <span class="text-success">Уровни сверху <span class="text-secondary small">(пробой вверх → LONG)</span></span>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-dark table-bordered mb-0 align-middle level-grid" id="level-table-above">
                                                <thead>
                                                <tr>
                                                    <th class="text-center small">цена</th>
                                                    <th class="text-center small level-grid-toggle" data-level-side="above" data-field="signal" title="вкл/выкл все">сигнал</th>
                                                    <th class="text-center small level-grid-toggle" data-level-side="above" data-field="order" title="вкл/выкл все">ордер</th>
                                                    <th class="text-center small">размер</th>
                                                    <th class="text-center small">запас</th>
                                                    <th class="text-center small">стоп</th>
                                                    <th class="text-center small">профит</th>
                                                </tr>
                                                </thead>
                                                <tbody id="level-body-above">
                                                <?php $renderLevelRows($levelGrid['above'], 'above'); ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="card bg-black border-secondary h-100">
                                    <div class="card-header border-secondary">
                                        <span class="text-danger">Уровни снизу <span class="text-secondary small">(пробой вниз → SHORT)</span></span>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-sm table-dark table-bordered mb-0 align-middle level-grid" id="level-table-below">
                                                <thead>
                                                <tr>
                                                    <th class="text-center small">цена</th>
                                                    <th class="text-center small level-grid-toggle" data-level-side="below" data-field="signal" title="вкл/выкл все">сигнал</th>
                                                    <th class="text-center small level-grid-toggle" data-level-side="below" data-field="order" title="вкл/выкл все">ордер</th>
                                                    <th class="text-center small">размер</th>
                                                    <th class="text-center small">запас</th>
                                                    <th class="text-center small">стоп</th>
                                                    <th class="text-center small">профит</th>
                                                </tr>
                                                </thead>
                                                <tbody id="level-body-below">
                                                <?php $renderLevelRows($levelGrid['below'], 'below'); ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item bg-black border-secondary mt-3">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed bg-dark text-light" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseRange"
                            aria-expanded="false" aria-controls="collapseRange">
                        Выход из диапазона
                        <span class="badge text-bg-secondary ms-2 fw-normal">низ / верх / уведомления</span>
                    </button>
                </h2>
                <div id="collapseRange" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <p class="text-secondary small mb-0">
                                При закрытии M1 выше «верх» или ниже «низ» — по одному уведомлению в минуту,
                                пока цена вне диапазона, всего не больше указанного числа за выход.
                                Повтор серии — только после возврата внутрь.
                                Текущая цена: <strong class="text-info"><?= $lastPriceLabel ?></strong>
                            </p>
                            <div class="d-flex gap-2 align-items-center">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="range_enabled" name="range_enabled" value="1"
                                        <?= !empty($rangeAlert['enabled']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="range_enabled">включена</label>
                                </div>
                                <button type="submit" name="action" value="reset_range" class="btn btn-sm btn-outline-warning"
                                        onclick="return confirm('Сбросить параметры диапазона?');">Сбросить</button>
                            </div>
                        </div>

                        <div class="card bg-black border-secondary">
                            <div class="card-body py-3">
                                <div class="row g-3 align-items-end">
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="range_low">низ</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" step="any" min="0"
                                               id="range_low" name="range_low"
                                               value="<?= $rangeAlert['low'] !== null ? htmlspecialchars(RangeAlertConfig::formatPrice($rangeAlert['low']), ENT_QUOTES, 'UTF-8') : '' ?>"
                                               placeholder="например 62000">
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="range_high">верх</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" step="any" min="0"
                                               id="range_high" name="range_high"
                                               value="<?= $rangeAlert['high'] !== null ? htmlspecialchars(RangeAlertConfig::formatPrice($rangeAlert['high']), ENT_QUOTES, 'UTF-8') : '' ?>"
                                               placeholder="например 64000">
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="range_notify_count">уведомлений (раз в минуту)</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" min="1" max="20" step="1"
                                               id="range_notify_count" name="range_notify_count"
                                               value="<?= (int) $rangeAlert['notify_count'] ?>">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 d-flex flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="range-set-low-12h"
                                            <?= $low12Label === null ? 'disabled' : '' ?>
                                                data-value="<?= $low12Label ?? '' ?>"
                                                title="Подставить low за последние 12 часов">
                                            Low 12ч<?= $low12Label !== null ? ': ' . $low12Label : '' ?>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success" id="range-set-high-12h"
                                            <?= $high12Label === null ? 'disabled' : '' ?>
                                                data-value="<?= $high12Label ?? '' ?>"
                                                title="Подставить high за последние 12 часов">
                                            High 12ч<?= $high12Label !== null ? ': ' . $high12Label : '' ?>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="range-set-low-24h"
                                            <?= $low24Label === null ? 'disabled' : '' ?>
                                                data-value="<?= $low24Label ?? '' ?>"
                                                title="Подставить low за последние 24 часа">
                                            Low 24ч<?= $low24Label !== null ? ': ' . $low24Label : '' ?>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success" id="range-set-high-24h"
                                            <?= $high24Label === null ? 'disabled' : '' ?>
                                                data-value="<?= $high24Label ?? '' ?>"
                                                title="Подставить high за последние 24 часа">
                                            High 24ч<?= $high24Label !== null ? ': ' . $high24Label : '' ?>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="range-set-low-48h"
                                            <?= $low48Label === null ? 'disabled' : '' ?>
                                                data-value="<?= $low48Label ?? '' ?>"
                                                title="Подставить low за последние 48 часов">
                                            Low 48ч<?= $low48Label !== null ? ': ' . $low48Label : '' ?>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success" id="range-set-high-48h"
                                            <?= $high48Label === null ? 'disabled' : '' ?>
                                                data-value="<?= $high48Label ?? '' ?>"
                                                title="Подставить high за последние 48 часов">
                                            High 48ч<?= $high48Label !== null ? ': ' . $high48Label : '' ?>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-info" id="range-fill-from-price"
                                                data-price="<?= $lastPrice !== null ? htmlspecialchars(RangeAlertConfig::formatPrice($lastPrice), ENT_QUOTES, 'UTF-8') : '' ?>">
                                            ±1% от цены
                                        </button>
                                    </div>
                                </div>
                                <div class="small text-secondary mt-2">
                                    Выше верха → LONG; ниже низа → SHORT. Пример: N=3 → до трёх сообщений с интервалом ~1 мин (1/3, 2/3, 3/3), пока цена снаружи.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item bg-black border-secondary mt-3">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed bg-dark text-light" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseMaTouch"
                            aria-expanded="false" aria-controls="collapseMaTouch">
                        MA28
                        <span class="badge text-bg-secondary ms-2 fw-normal">касание + переходы</span>
                        <?php if (!empty($maTouch['test_mode'])): ?>
                            <span class="badge text-bg-warning ms-2 fw-normal">TEST</span>
                        <?php endif; ?>
                    </button>
                </h2>
                <div id="collapseMaTouch" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <p class="text-secondary small mb-0">
                                <strong>Касание:</strong> SMA(28) между low/high закрытой свечи → сигнал.<br>
                                <strong>Переход ↓:</strong> close с MA сверху вниз → N обновлений локального лоя → Buy по close − запас.<br>
                                <strong>Переход ↑:</strong> close с MA снизу вверх → N обновлений локального хая → Sell по close + запас.<br>
                                TP/SL в $ от цены входа. Боевые ордера: <code>trading_enabled=1</code>; в тесте ордера эмулируются, все события уходят в Telegram.
                            </p>
                            <button type="submit" name="action" value="reset_ma_touch" class="btn btn-sm btn-outline-warning"
                                    onclick="return confirm('Сбросить стратегию MA28?');">Сбросить</button>
                        </div>

                        <div class="card bg-black border-secondary mb-3">
                            <div class="card-body py-3">
                                <div class="small text-secondary mb-2">Таймфреймы</div>
                                <div class="row g-3">
                                    <?php foreach (MaTouchConfig::TIMEFRAMES as $tf): ?>
                                        <div class="col-6 col-md-4 col-xl-2">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       id="ma_touch_<?= $tf ?>"
                                                       name="ma_touch[<?= $tf ?>]" value="1"
                                                    <?= !empty($maTouch['timeframes'][$tf]) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ma_touch_<?= $tf ?>"><?= htmlspecialchars($tf, ENT_QUOTES, 'UTF-8') ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card bg-black border-secondary mb-3">
                            <div class="card-body py-3">
                                <div class="d-flex flex-wrap gap-3 align-items-center">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="ma_touch_enabled" name="ma_touch_enabled" value="1"
                                            <?= !empty($maTouch['touch_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ma_touch_enabled">касание MA</label>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="ma_cross_down" name="ma_cross_down" value="1"
                                            <?= !empty($maTouch['cross_down_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ma_cross_down">переход сверху ↓ вниз (Buy)</label>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="ma_cross_up" name="ma_cross_up" value="1"
                                            <?= !empty($maTouch['cross_up_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="ma_cross_up">переход снизу ↑ вверх (Sell)</label>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="ma_test_mode" name="ma_test_mode" value="1"
                                            <?= !empty($maTouch['test_mode']) ? 'checked' : '' ?>>
                                        <label class="form-check-label text-warning" for="ma_test_mode">тестовый режим</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card bg-black border-secondary">
                            <div class="card-body py-3">
                                <div class="row g-3 align-items-end">
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small text-secondary mb-1" for="ma_local_bars">N обновлений экстремума</label>
                                        <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary"
                                               id="ma_local_bars" name="ma_local_bars" min="1" max="50" step="1"
                                               value="<?= (int) ($maTouch['local_bars'] ?? 3) ?>">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small text-secondary mb-1" for="ma_buffer">Запас $</label>
                                        <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary"
                                               id="ma_buffer" name="ma_buffer" min="0" step="any"
                                               value="<?= htmlspecialchars((string) ($maTouch['buffer'] ?? 50), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small text-secondary mb-1" for="ma_tp_points">TP $ от входа</label>
                                        <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary"
                                               id="ma_tp_points" name="ma_tp_points" min="0.01" step="any"
                                               value="<?= htmlspecialchars((string) ($maTouch['tp_points'] ?? 300), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small text-secondary mb-1" for="ma_sl_points">SL $ от входа</label>
                                        <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary"
                                               id="ma_sl_points" name="ma_sl_points" min="0.01" step="any"
                                               value="<?= htmlspecialchars((string) ($maTouch['sl_points'] ?? 900), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small text-secondary mb-1" for="ma_order_size">Объём</label>
                                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                                               id="ma_order_size" name="ma_order_size"
                                               value="<?= htmlspecialchars((string) ($maTouch['order_size'] ?? '0.001'), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>
                                <div class="small text-secondary mt-2">
                                    Пример ↓: N=3 — три бара, каждый обновивший локальный low; Buy = close третьего − запас; TP = вход+TP$, SL = вход−SL$.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item bg-black border-secondary mt-3">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed bg-dark text-light" type="button"
                            data-bs-toggle="collapse" data-bs-target="#collapseDirectionGrid"
                            aria-expanded="false" aria-controls="collapseDirectionGrid">
                        Слежение за хаем/лоем
                        <span class="badge text-bg-secondary ms-2 fw-normal">сетка 3 лимита</span>
                        <?php if (!empty($directionGrid['test_mode'])): ?>
                            <span class="badge text-bg-warning ms-2 fw-normal">TEST</span>
                        <?php endif; ?>
                        <?php if (!empty($directionState['stopped'])): ?>
                            <span class="badge text-bg-danger ms-2 fw-normal">остановлена</span>
                        <?php elseif (!empty($directionState['filled_any'])): ?>
                            <span class="badge text-bg-info ms-2 fw-normal">ждём TP/SL</span>
                        <?php endif; ?>
                    </button>
                </h2>
                <div id="collapseDirectionGrid" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <p class="text-secondary small mb-0">
                                High → Buy-лимиты ниже хая; Low → Sell-лимиты выше лоя. Пока нет fill — сетка двигается за экстремумом раз в минуту.
                                После fill — ждём TP/SL, незаполненные не двигаем.
                                Боевой режим требует <code>trading_enabled=1</code>; в тестовом ордера эмулируются, все события уходят в Telegram.
                            </p>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="dg_enabled" name="dg_enabled" value="1"
                                        <?= !empty($directionGrid['enabled']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="dg_enabled">включена</label>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="dg_test_mode" name="dg_test_mode" value="1"
                                        <?= !empty($directionGrid['test_mode']) ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-warning" for="dg_test_mode">тестовый режим</label>
                                </div>
                                <button type="submit" name="action" value="reset_direction_grid" class="btn btn-sm btn-outline-warning"
                                        onclick="return confirm('Сбросить стратегию слежения?');">Сбросить</button>
                            </div>
                        </div>

                        <div class="card bg-black border-secondary mb-3">
                            <div class="card-body py-3">
                                <div class="row g-3 align-items-end">
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="dg_mode">направление</label>
                                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="dg_mode" name="dg_mode">
                                            <option value="high" <?= $directionGrid['mode'] === 'high' ? 'selected' : '' ?>>за хаем (Buy)</option>
                                            <option value="low" <?= $directionGrid['mode'] === 'low' ? 'selected' : '' ?>>за лоем (Sell)</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="dg_period_minutes">период</label>
                                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="dg_period_minutes" name="dg_period_minutes">
                                            <?php
                                            $periodLabels = [15 => '15м', 60 => '1ч', 240 => '4ч', 1440 => '24ч', 2880 => '48ч'];
                                            foreach ($periodLabels as $mins => $label):
                                            ?>
                                                <option value="<?= $mins ?>" <?= (int) $directionGrid['period_minutes'] === $mins ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="dg_profit">профит $</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" step="any" min="0.01" id="dg_profit" name="dg_profit"
                                               value="<?= htmlspecialchars((string) $directionGrid['profit'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="dg_stop">стоп $</label>
                                        <input class="form-control form-control-sm bg-dark text-light border-secondary"
                                               type="number" step="any" min="0.01" id="dg_stop" name="dg_stop"
                                               value="<?= htmlspecialchars((string) $directionGrid['stop'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-6 col-md-3 col-xl-2">
                                        <label class="form-label small text-secondary mb-1" for="dg_after_tp">после профита</label>
                                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="dg_after_tp" name="dg_after_tp">
                                            <option value="rebuild" <?= $directionGrid['after_tp'] === 'rebuild' ? 'selected' : '' ?>>новая сетка</option>
                                            <option value="stop" <?= $directionGrid['after_tp'] === 'stop' ? 'selected' : '' ?>>остановить</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="table-responsive mt-3">
                                    <table class="table table-sm table-dark table-bordered mb-0 align-middle">
                                        <thead>
                                        <tr>
                                            <th class="text-center small">#</th>
                                            <th class="text-center small">отступ $</th>
                                            <th class="text-center small">объём</th>
                                            <th class="text-center small">цена (превью)</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php for ($i = 0; $i < 3; $i++): ?>
                                            <?php
                                            $lvl = $directionGrid['levels'][$i];
                                            $preview = $dgPreviewPrices[$i] ?? null;
                                            ?>
                                            <tr>
                                                <td class="text-center text-secondary">L<?= $i + 1 ?></td>
                                                <td>
                                                    <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                                                           type="number" step="any" min="0.01"
                                                           name="dg_level[<?= $i ?>][offset]"
                                                           value="<?= htmlspecialchars((string) $lvl['offset'], ENT_QUOTES, 'UTF-8') ?>">
                                                </td>
                                                <td>
                                                    <input class="form-control form-control-sm bg-dark text-light border-secondary text-center"
                                                           type="text" inputmode="decimal"
                                                           name="dg_level[<?= $i ?>][size]"
                                                           value="<?= htmlspecialchars((string) $lvl['size'], ENT_QUOTES, 'UTF-8') ?>">
                                                </td>
                                                <td class="text-center small text-info">
                                                    <?= $preview !== null ? htmlspecialchars(DirectionGridConfig::formatPrice($preview), ENT_QUOTES, 'UTF-8') : '—' ?>
                                                </td>
                                            </tr>
                                        <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="small text-secondary mt-3">
                                    <?php if ($dgAnchor !== null): ?>
                                        Экстремум периода:
                                        <strong class="text-info"><?= htmlspecialchars(DirectionGridConfig::formatPrice($dgAnchor), ENT_QUOTES, 'UTF-8') ?></strong>
                                        · TP: <strong class="text-success"><?= htmlspecialchars(DirectionGridConfig::formatPrice((float) $dgPreviewTp), ENT_QUOTES, 'UTF-8') ?></strong>
                                        · SL: <strong class="text-danger"><?= htmlspecialchars(DirectionGridConfig::formatPrice((float) $dgPreviewSl), ENT_QUOTES, 'UTF-8') ?></strong>
                                        · цена: <strong><?= $lastPriceLabel ?></strong>
                                    <?php else: ?>
                                        Нет данных экстремума за период — загрузите свечи M1.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (() => {
        const COLLAPSE_KEY = 'tradesignals.signalGridCollapsed.v1';
        const ACCORDION_KEY = 'tradesignals.strategiesAccordion.v1';
        const COLLAPSE_COLS = ['bars', 'size', 'reserve', 'stop', 'profit'];
        const table = document.querySelector('.signal-grid');

        const readJson = (key, fallback) => {
            try {
                const raw = JSON.parse(localStorage.getItem(key) || 'null');
                return raw && typeof raw === 'object' ? raw : fallback;
            } catch (_error) {
                return fallback;
            }
        };
        const writeJson = (key, value) => {
            try {
                localStorage.setItem(key, JSON.stringify(value));
            } catch (_error) {
                // ignore
            }
        };

        // Запомнить состояние секций стратегий.
        const accordion = document.getElementById('strategiesAccordion');
        if (accordion) {
            const saved = readJson(ACCORDION_KEY, {
                collapseBars: true,
                collapseLevels: false,
                collapseRange: false,
                collapseMaTouch: false,
                collapseDirectionGrid: false,
            });
            ['collapseBars', 'collapseLevels', 'collapseRange', 'collapseMaTouch', 'collapseDirectionGrid'].forEach((id) => {
                const pane = document.getElementById(id);
                const btn = accordion.querySelector(`[data-bs-target="#${id}"]`);
                if (!pane || !btn) {
                    return;
                }
                // bars по умолчанию открыта, остальные — закрыты
                const shouldOpen = id === 'collapseBars'
                    ? saved[id] !== false
                    : saved[id] === true;
                pane.classList.toggle('show', shouldOpen);
                btn.classList.toggle('collapsed', !shouldOpen);
                btn.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });
            accordion.querySelectorAll('.accordion-collapse').forEach((pane) => {
                pane.addEventListener('shown.bs.collapse', () => {
                    const state = readJson(ACCORDION_KEY, {});
                    state[pane.id] = true;
                    writeJson(ACCORDION_KEY, state);
                });
                pane.addEventListener('hidden.bs.collapse', () => {
                    const state = readJson(ACCORDION_KEY, {});
                    state[pane.id] = false;
                    writeJson(ACCORDION_KEY, state);
                });
            });
        }

        if (table) {
            const applyTfCollapse = (tf, collapsed) => {
                table.classList.toggle(`tf-collapsed-${tf}`, collapsed);
                const header = table.querySelector(`.signal-grid-tf-toggle[data-tf="${tf}"]`);
                if (header) {
                    const expanded = Number(header.dataset.colspanExpanded || 7);
                    const collapsedCols = Number(header.dataset.colspanCollapsed || 2);
                    header.colSpan = collapsed ? collapsedCols : expanded;
                    header.classList.toggle('is-collapsed', collapsed);
                }
                COLLAPSE_COLS.forEach((col) => {
                    table.querySelectorAll(`[data-tf="${tf}"][data-col="${col}"]`).forEach((cell) => {
                        cell.hidden = collapsed;
                    });
                });
            };

            let collapsedMap = readJson(COLLAPSE_KEY, {});
            document.querySelectorAll('.signal-grid-tf-toggle').forEach((header) => {
                const tf = header.dataset.tf;
                applyTfCollapse(tf, !!collapsedMap[tf]);
                header.addEventListener('click', () => {
                    collapsedMap = readJson(COLLAPSE_KEY, {});
                    collapsedMap[tf] = !collapsedMap[tf];
                    writeJson(COLLAPSE_KEY, collapsedMap);
                    applyTfCollapse(tf, !!collapsedMap[tf]);
                });
            });

            document.querySelectorAll('.signal-grid-toggle').forEach((header) => {
                header.addEventListener('click', (event) => {
                    event.stopPropagation();
                    const tf = header.dataset.tf;
                    const field = header.dataset.field;
                    const boxes = Array.from(
                        document.querySelectorAll(
                            `.signal-grid input[type="checkbox"][data-tf="${tf}"][data-field="${field}"]`
                        )
                    );
                    if (!boxes.length) {
                        return;
                    }
                    const turnOn = boxes.some((box) => !box.checked);
                    boxes.forEach((box) => {
                        box.checked = turnOn;
                    });
                });
            });
        }

        const fmt = (value) => {
            const n = Number(value);
            if (!Number.isFinite(n)) {
                return '';
            }
            return String(Number(n.toFixed(8)));
        };

        const levelRowHtml = (side, index, row) => {
            const prefix = side === 'above' ? 'level_above' : 'level_below';
            const name = `${prefix}[${index}]`;
            const checked = (on) => (on ? 'checked' : '');
            return `<tr>
                <td><input class="form-control form-control-sm bg-dark text-light border-secondary text-center" type="number" step="any" min="0" name="${name}[price]" value="${row.price}"></td>
                <td class="text-center"><input class="form-check-input" type="checkbox" name="${name}[signal]" value="1" data-level-side="${side}" data-field="signal" ${checked(row.signal)}></td>
                <td class="text-center"><input class="form-check-input" type="checkbox" name="${name}[order]" value="1" data-level-side="${side}" data-field="order" ${checked(row.order)}></td>
                <td><input class="form-control form-control-sm bg-dark text-light border-secondary text-center" type="text" inputmode="decimal" name="${name}[size]" value="${row.size}"></td>
                <td><input class="form-control form-control-sm bg-dark text-light border-secondary text-center" type="number" step="any" name="${name}[reserve]" value="${row.reserve}"></td>
                <td><input class="form-control form-control-sm bg-dark text-light border-secondary text-center" type="number" step="any" name="${name}[stop]" value="${row.stop}"></td>
                <td><input class="form-control form-control-sm bg-dark text-light border-secondary text-center" type="number" step="any" name="${name}[profit]" value="${row.profit}"></td>
            </tr>`;
        };

        const defaultLevel = (price) => ({
            price: fmt(price),
            signal: true,
            order: false,
            size: '0.001',
            reserve: 10,
            stop: 300,
            profit: 300,
        });

        const fillBody = (side, rows) => {
            const body = document.getElementById(side === 'above' ? 'level-body-above' : 'level-body-below');
            if (!body) {
                return;
            }
            if (!rows.length) {
                body.innerHTML = '<tr class="level-grid-empty"><td colspan="7" class="text-secondary small text-center py-3">Нет уровней. Сгенерируйте или добавьте вручную.</td></tr>';
                return;
            }
            body.innerHTML = rows.map((row, index) => levelRowHtml(side, index, row)).join('');
        };

        const readBodyRows = (side) => {
            const body = document.getElementById(side === 'above' ? 'level-body-above' : 'level-body-below');
            if (!body) {
                return [];
            }
            return Array.from(body.querySelectorAll('tr')).map((tr) => {
                const inputs = tr.querySelectorAll('input');
                if (inputs.length < 7) {
                    return null;
                }
                return {
                    price: inputs[0].value,
                    signal: inputs[1].checked,
                    order: inputs[2].checked,
                    size: inputs[3].value || '0.001',
                    reserve: inputs[4].value || 10,
                    stop: inputs[5].value || 300,
                    profit: inputs[6].value || 300,
                };
            }).filter(Boolean);
        };

        document.getElementById('level-generate')?.addEventListener('click', () => {
            const base = Number(document.getElementById('level-base')?.value);
            const step = Number(document.getElementById('level-step')?.value);
            const countAbove = Math.max(0, Math.min(50, Number(document.getElementById('level-count-above')?.value || 0)));
            const countBelow = Math.max(0, Math.min(50, Number(document.getElementById('level-count-below')?.value || 0)));
            if (!Number.isFinite(base) || base <= 0 || !Number.isFinite(step) || step <= 0) {
                alert('Укажите корректную базовую цену и шаг.');
                return;
            }
            const above = [];
            for (let i = 1; i <= countAbove; i += 1) {
                above.push(defaultLevel(base + step * i));
            }
            const below = [];
            for (let i = 1; i <= countBelow; i += 1) {
                below.push(defaultLevel(base - step * i));
            }
            fillBody('above', above);
            fillBody('below', below);
        });

        document.getElementById('level-add-above')?.addEventListener('click', () => {
            const rows = readBodyRows('above');
            const base = Number(document.getElementById('level-base')?.value);
            const step = Number(document.getElementById('level-step')?.value) || 100;
            let next = Number.isFinite(base) ? base + step : step;
            if (rows.length) {
                const last = Number(rows[rows.length - 1].price);
                if (Number.isFinite(last)) {
                    next = last + step;
                }
            }
            rows.push(defaultLevel(next));
            fillBody('above', rows);
        });

        document.getElementById('level-add-below')?.addEventListener('click', () => {
            const rows = readBodyRows('below');
            const base = Number(document.getElementById('level-base')?.value);
            const step = Number(document.getElementById('level-step')?.value) || 100;
            let next = Number.isFinite(base) ? base - step : step;
            if (rows.length) {
                const last = Number(rows[rows.length - 1].price);
                if (Number.isFinite(last)) {
                    next = last - step;
                }
            }
            rows.push(defaultLevel(next));
            fillBody('below', rows);
        });

        document.querySelectorAll('.level-grid-toggle').forEach((header) => {
            header.addEventListener('click', () => {
                const side = header.dataset.levelSide;
                const field = header.dataset.field;
                const boxes = Array.from(
                    document.querySelectorAll(
                        `input[type="checkbox"][data-level-side="${side}"][data-field="${field}"]`
                    )
                );
                if (!boxes.length) {
                    return;
                }
                const turnOn = boxes.some((box) => !box.checked);
                boxes.forEach((box) => {
                    box.checked = turnOn;
                });
            });
        });

        const bindRangeQuickSet = (buttonId, inputId) => {
            document.getElementById(buttonId)?.addEventListener('click', () => {
                const value = document.getElementById(buttonId)?.dataset.value;
                const input = document.getElementById(inputId);
                if (!value || !input) {
                    return;
                }
                input.value = value;
            });
        };
        bindRangeQuickSet('range-set-low-12h', 'range_low');
        bindRangeQuickSet('range-set-high-12h', 'range_high');
        bindRangeQuickSet('range-set-low-24h', 'range_low');
        bindRangeQuickSet('range-set-high-24h', 'range_high');
        bindRangeQuickSet('range-set-low-48h', 'range_low');
        bindRangeQuickSet('range-set-high-48h', 'range_high');

        document.getElementById('range-fill-from-price')?.addEventListener('click', () => {
            const btn = document.getElementById('range-fill-from-price');
            const price = Number(btn?.dataset.price || document.getElementById('level-base')?.value);
            if (!Number.isFinite(price) || price <= 0) {
                alert('Нет текущей цены для подстановки.');
                return;
            }
            const lowEl = document.getElementById('range_low');
            const highEl = document.getElementById('range_high');
            if (lowEl) {
                lowEl.value = fmt(price * 0.99);
            }
            if (highEl) {
                highEl.value = fmt(price * 1.01);
            }
        });
    })();
</script>
</body>
</html>
