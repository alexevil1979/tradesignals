-- Схема MySQL 5.7.8+ для торгового бота Bybit.
-- База данных должна быть создана до импорта схемы.

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE candles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol VARCHAR(32) NOT NULL,
    interval_code VARCHAR(8) NOT NULL,
    open_time DATETIME NOT NULL,
    open_price DECIMAL(20,8) NOT NULL,
    high_price DECIMAL(20,8) NOT NULL,
    low_price DECIMAL(20,8) NOT NULL,
    close_price DECIMAL(20,8) NOT NULL,
    volume DECIMAL(28,8) NOT NULL DEFAULT 0,
    turnover DECIMAL(28,8) NOT NULL DEFAULT 0,
    is_confirmed TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_candle (symbol, interval_code, open_time),
    KEY idx_candles_lookup (symbol, interval_code, open_time DESC)
) ENGINE=InnoDB;

CREATE TABLE strategies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    rule_type ENUM('up_after_down','down_after_up','consecutive_up','consecutive_down') NOT NULL,
    min_count SMALLINT UNSIGNED NOT NULL,
    max_count SMALLINT UNSIGNED NOT NULL,
    volumes JSON NOT NULL,
    take_profit_percent DECIMAL(8,4) NOT NULL,
    stop_loss_percent DECIMAL(8,4) NOT NULL,
    interval_code VARCHAR(8) NOT NULL,
    close_on_reverse TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_strategy_counts CHECK (min_count > 0 AND max_count >= min_count)
) ENGINE=InnoDB;

CREATE TABLE signals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    strategy_id BIGINT UNSIGNED NULL,
    symbol VARCHAR(32) NOT NULL,
    side ENUM('Buy','Sell') NOT NULL,
    signal_type VARCHAR(64) NOT NULL,
    candle_count SMALLINT UNSIGNED NOT NULL,
    candle_open_time DATETIME NOT NULL,
    price DECIMAL(20,8) NOT NULL,
    payload JSON NULL,
    telegram_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_signals_strategy FOREIGN KEY (strategy_id) REFERENCES strategies(id) ON DELETE SET NULL,
    UNIQUE KEY uq_signal_candle (strategy_id, symbol, signal_type, candle_count, candle_open_time),
    KEY idx_signals_created (created_at DESC)
) ENGINE=InnoDB;

CREATE TABLE orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    strategy_id BIGINT UNSIGNED NULL,
    signal_id BIGINT UNSIGNED NULL,
    bybit_order_id VARCHAR(64) NULL UNIQUE,
    order_link_id VARCHAR(64) NOT NULL UNIQUE,
    symbol VARCHAR(32) NOT NULL,
    side ENUM('Buy','Sell') NOT NULL,
    order_type ENUM('Market','Limit') NOT NULL,
    status VARCHAR(32) NOT NULL,
    quantity DECIMAL(20,8) NOT NULL,
    price DECIMAL(20,8) NULL,
    average_price DECIMAL(20,8) NULL,
    take_profit DECIMAL(20,8) NULL,
    stop_loss DECIMAL(20,8) NULL,
    reduce_only TINYINT(1) NOT NULL DEFAULT 0,
    raw_response JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_strategy FOREIGN KEY (strategy_id) REFERENCES strategies(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_signal FOREIGN KEY (signal_id) REFERENCES signals(id) ON DELETE SET NULL,
    KEY idx_orders_status (status, created_at DESC)
) ENGINE=InnoDB;

CREATE TABLE positions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    strategy_id BIGINT UNSIGNED NULL,
    symbol VARCHAR(32) NOT NULL,
    side ENUM('Buy','Sell','None') NOT NULL DEFAULT 'None',
    quantity DECIMAL(20,8) NOT NULL DEFAULT 0,
    entry_price DECIMAL(20,8) NULL,
    mark_price DECIMAL(20,8) NULL,
    unrealised_pnl DECIMAL(20,8) NOT NULL DEFAULT 0,
    realised_pnl DECIMAL(20,8) NOT NULL DEFAULT 0,
    take_profit DECIMAL(20,8) NULL,
    stop_loss DECIMAL(20,8) NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 0,
    bybit_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_positions_strategy FOREIGN KEY (strategy_id) REFERENCES strategies(id) ON DELETE SET NULL,
    KEY idx_positions_open (symbol, is_open)
) ENGINE=InnoDB;

CREATE TABLE settings (
    setting_key VARCHAR(128) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level ENUM('info','warning','error') NOT NULL,
    channel VARCHAR(64) NOT NULL DEFAULT 'app',
    message TEXT NOT NULL,
    context JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_level_created (level, created_at DESC)
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('bot_paused', '1'),
    ('trading_enabled', '0'),
    ('candle_interval', '1'),
    ('polling_interval_seconds', '60'),
    ('bybit_testnet', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
