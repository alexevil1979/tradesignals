<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Database\SettingsRepository;
use App\Strategy\SignalGridConfig;

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
        if ($action === 'reset') {
            $grid = SignalGridConfig::defaults();
            $settings->set(SignalGridConfig::SETTING_KEY, json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $flash = 'Таблица сигналов сброшена к значениям из Excel.';
        } else {
            $grid = SignalGridConfig::fromPost($_POST);
            $settings->set(SignalGridConfig::SETTING_KEY, json_encode($grid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $flash = 'Таблица сигналов сохранена.';
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

$csrfToken = htmlspecialchars($auth->csrfToken(), ENT_QUOTES, 'UTF-8');
$minBodyNote = htmlspecialchars(SignalGridConfig::MIN_BODY_NOTE, ENT_QUOTES, 'UTF-8');
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
            <a class="nav-link" href="/admin/chart.php">График</a>
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
            <p class="text-secondary mb-0">Таблица сигналов по таймфреймам (как в Excel). Уровень <strong>баров</strong> может быть от 1 и выше — сигнал при серии ≥ этого числа.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="submit" form="signal-grid-form" name="action" value="save" class="btn btn-success">Сохранить</button>
            <button type="submit" form="signal-grid-form" name="action" value="reset" class="btn btn-outline-warning"
                    onclick="return confirm('Сбросить таблицу к значениям из Excel?');">Сбросить</button>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" id="signal-grid-form">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

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
    </form>
</main>
<script>
    (() => {
        const COLLAPSE_KEY = 'tradesignals.signalGridCollapsed.v1';
        const COLLAPSE_COLS = ['bars', 'size', 'reserve', 'stop', 'profit'];
        const table = document.querySelector('.signal-grid');
        if (!table) {
            return;
        }

        const readCollapsed = () => {
            try {
                const raw = JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '{}');
                return raw && typeof raw === 'object' ? raw : {};
            } catch (_error) {
                return {};
            }
        };

        const writeCollapsed = (map) => {
            try {
                localStorage.setItem(COLLAPSE_KEY, JSON.stringify(map));
            } catch (_error) {
                // ignore
            }
        };

        const applyTfCollapse = (tf, collapsed) => {
            const className = `tf-collapsed-${tf}`;
            table.classList.toggle(className, collapsed);

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

        let collapsedMap = readCollapsed();
        document.querySelectorAll('.signal-grid-tf-toggle').forEach((header) => {
            const tf = header.dataset.tf;
            applyTfCollapse(tf, !!collapsedMap[tf]);

            header.addEventListener('click', () => {
                collapsedMap = readCollapsed();
                collapsedMap[tf] = !collapsedMap[tf];
                writeCollapsed(collapsedMap);
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
    })();
</script>
</body>
</html>
