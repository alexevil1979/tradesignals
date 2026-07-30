-- Примените только к базе, созданной до добавления candle_open_time.
ALTER TABLE signals
    ADD COLUMN candle_open_time DATETIME NOT NULL AFTER candle_count,
    ADD UNIQUE KEY uq_signal_candle (strategy_id, symbol, signal_type, candle_count, candle_open_time);
