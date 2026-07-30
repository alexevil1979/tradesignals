-- Не запускайте в production без проверки параметров риска.
INSERT INTO strategies (
    name, rule_type, min_count, max_count, volumes,
    take_profit_percent, stop_loss_percent, interval_code, close_on_reverse, is_active
) VALUES (
    'Локальная тестовая стратегия',
    'consecutive_up',
    2,
    3,
    '["0.001", "0.001"]',
    0.50,
    0.25,
    '1',
    1,
    1
);

UPDATE settings SET setting_value = '0' WHERE setting_key = 'bot_paused';
