package ru.tlt.tradesignals.data

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

@Serializable
data class ApiError(val ok: Boolean = false, val error: String? = null)

@Serializable
data class LoginResponse(
    val ok: Boolean,
    val token: String? = null,
    val expires_at: String? = null,
    val user: UserDto? = null,
    val error: String? = null,
)

@Serializable
data class UserDto(val id: Long? = null, val username: String? = null)

@Serializable
data class DashboardResponse(
    val ok: Boolean,
    val user: UserDto? = null,
    val market: MarketDto? = null,
    val bot: BotDto? = null,
    val direction_grid: DirectionGridBundle? = null,
    val positions: List<PositionDto> = emptyList(),
    val orders: List<OrderDto> = emptyList(),
    val signals: List<SignalDto> = emptyList(),
    val server_time: String? = null,
    val error: String? = null,
)

@Serializable
data class MarketDto(
    val symbol: String? = null,
    val category: String? = null,
    val price: Double? = null,
    val testnet: Boolean? = null,
)

@Serializable
data class BotDto(
    val paused: Boolean = true,
    val trading_enabled: Boolean = false,
)

@Serializable
data class DirectionGridBundle(
    val config: DirectionGridConfigDto? = null,
    val state: DirectionStateDto? = null,
    val overlay: OverlayDto? = null,
)

@Serializable
data class DirectionGridConfigDto(
    val enabled: Boolean = false,
    val test_mode: Boolean = true,
    val chart_h1: Boolean = false,
    val mode: String = "high",
    val period_minutes: Int = 60,
    val profit: Double = 300.0,
    val stop: Double = 900.0,
    val after_tp: String = "rebuild",
    val levels: List<LevelDto> = emptyList(),
)

@Serializable
data class LevelDto(
    val offset: Double = 0.0,
    val size: String = "0.001",
    val sound: Boolean = false,
    val telegram: Boolean = true,
)

@Serializable
data class DirectionStateDto(
    val anchor: Double? = null,
    val filled_any: Boolean = false,
    val wait_close: Boolean = false,
    val stopped: Boolean = false,
    val force_rebuild: Boolean = false,
)

@Serializable
data class OverlayDto(
    val show_h1: Boolean = false,
    val mode: String = "high",
    val anchor: Double? = null,
    val levels: List<OverlayLevelDto> = emptyList(),
    val tp: Double? = null,
    val sl: Double? = null,
)

@Serializable
data class OverlayLevelDto(
    val index: Int = 0,
    val title: String? = null,
    val price: Double = 0.0,
)

@Serializable
data class PositionDto(
    val symbol: String? = null,
    val side: String? = null,
    val quantity: String? = null,
    val entry_price: String? = null,
    val mark_price: String? = null,
    val unrealised_pnl: String? = null,
    val take_profit: String? = null,
    val stop_loss: String? = null,
    val is_open: Int? = null,
)

@Serializable
data class OrderDto(
    val order_link_id: String? = null,
    val side: String? = null,
    val order_type: String? = null,
    val status: String? = null,
    val quantity: String? = null,
    val price: String? = null,
    val take_profit: String? = null,
    val stop_loss: String? = null,
    val created_at: String? = null,
)

@Serializable
data class SignalDto(
    val id: Long? = null,
    val side: String? = null,
    val signal_type: String? = null,
    val price: String? = null,
    val candle_open_time: String? = null,
    val telegram_sent_at: String? = null,
    val created_at: String? = null,
)

@Serializable
data class AlertResponse(
    val ok: Boolean,
    val triggered: Boolean = false,
    val price: Double? = null,
    val mode: String? = null,
    val triggered_levels: List<OverlayLevelDto> = emptyList(),
    val overlay: OverlayDto? = null,
    val error: String? = null,
)

@Serializable
data class StrategiesResponse(
    val ok: Boolean,
    val strategies: StrategiesBundle? = null,
    val error: String? = null,
)

@Serializable
data class StrategiesBundle(
    val direction_grid: DirectionGridConfigDto? = null,
    val ma_touch: JsonElement? = null,
    val price_channel: JsonElement? = null,
    val range_alert: JsonElement? = null,
)

@Serializable
data class BotUpdateResponse(
    val ok: Boolean,
    val bot: BotDto? = null,
    val error: String? = null,
)

@Serializable
data class LogsResponse(
    val ok: Boolean,
    val logs: List<LogDto> = emptyList(),
    val error: String? = null,
)

@Serializable
data class LogDto(
    val id: Long? = null,
    val level: String? = null,
    val channel: String? = null,
    val message: String? = null,
    val created_at: String? = null,
)

@Serializable
data class OrdersResponse(
    val ok: Boolean,
    val orders: List<OrderDto> = emptyList(),
    val positions: List<PositionDto> = emptyList(),
    val error: String? = null,
)

@Serializable
data class OkResponse(val ok: Boolean, val error: String? = null)
