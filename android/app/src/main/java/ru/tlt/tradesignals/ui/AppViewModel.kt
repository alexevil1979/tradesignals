package ru.tlt.tradesignals.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import ru.tlt.tradesignals.AppContainer
import ru.tlt.tradesignals.data.DirectionGridConfigDto
import ru.tlt.tradesignals.data.DashboardResponse
import ru.tlt.tradesignals.data.LogDto
import ru.tlt.tradesignals.data.OrderDto
import ru.tlt.tradesignals.data.PositionDto
import ru.tlt.tradesignals.data.SignalDto

data class UiState(
    val booting: Boolean = true,
    val loggedIn: Boolean = false,
    val loading: Boolean = false,
    val error: String? = null,
    val baseUrl: String = "https://td.1tlt.ru",
    val username: String? = null,
    val dashboard: DashboardResponse? = null,
    val gridDraft: DirectionGridConfigDto? = null,
    val orders: List<OrderDto> = emptyList(),
    val positions: List<PositionDto> = emptyList(),
    val signals: List<SignalDto> = emptyList(),
    val logs: List<LogDto> = emptyList(),
    val alertTriggered: Boolean = false,
    val saving: Boolean = false,
    val message: String? = null,
)

class AppViewModel(private val container: AppContainer) : ViewModel() {
    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    private var pollJob: Job? = null

    init {
        viewModelScope.launch {
            val token = container.sessionStore.token()
            val base = container.sessionStore.baseUrl()
            _state.update {
                it.copy(
                    booting = false,
                    loggedIn = !token.isNullOrBlank(),
                    baseUrl = base,
                )
            }
            if (!token.isNullOrBlank()) {
                startPolling()
                refresh()
            }
        }
    }

    fun login(baseUrl: String, username: String, password: String) {
        viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null) }
            try {
                val cleanBase = baseUrl.trim().trimEnd('/')
                val res = container.api.login(cleanBase, username.trim(), password)
                val token = res.token ?: throw IllegalStateException(res.error ?: "Нет токена")
                container.sessionStore.saveSession(
                    cleanBase,
                    token,
                    res.user?.username ?: username.trim(),
                )
                _state.update {
                    it.copy(
                        loading = false,
                        loggedIn = true,
                        baseUrl = cleanBase,
                        username = res.user?.username ?: username.trim(),
                        error = null,
                    )
                }
                startPolling()
                refresh()
            } catch (e: Exception) {
                _state.update {
                    it.copy(loading = false, error = e.message ?: "Ошибка входа")
                }
            }
        }
    }

    fun logout() {
        viewModelScope.launch {
            pollJob?.cancel()
            container.alertSound.stop()
            runCatching { container.api.logout() }
            _state.update {
                UiState(booting = false, loggedIn = false, baseUrl = it.baseUrl)
            }
        }
    }

    fun refresh() {
        viewModelScope.launch {
            if (!_state.value.loggedIn) return@launch
            _state.update { it.copy(loading = true, error = null) }
            try {
                val dash = container.api.dashboard()
                val alert = runCatching { container.api.alert() }.getOrNull()
                container.alertSound.onTriggered(alert?.triggered == true)
                _state.update {
                    it.copy(
                        loading = false,
                        dashboard = dash,
                        gridDraft = dash.direction_grid?.config ?: it.gridDraft,
                        orders = dash.orders,
                        positions = dash.positions,
                        signals = dash.signals,
                        username = dash.user?.username ?: it.username,
                        alertTriggered = alert?.triggered == true,
                        error = null,
                    )
                }
            } catch (e: Exception) {
                _state.update {
                    it.copy(loading = false, error = e.message ?: "Ошибка загрузки")
                }
            }
        }
    }

    fun loadSection(section: String) {
        viewModelScope.launch {
            try {
                when (section) {
                    "orders" -> {
                        val res = container.api.orders()
                        _state.update {
                            it.copy(orders = res.orders, positions = res.positions)
                        }
                    }
                    "logs" -> {
                        val res = container.api.logs()
                        _state.update { it.copy(logs = res.logs) }
                    }
                    "strategies" -> {
                        val res = container.api.strategies()
                        _state.update {
                            it.copy(gridDraft = res.strategies?.direction_grid ?: it.gridDraft)
                        }
                    }
                    "signals" -> {
                        val res = container.api.signals()
                        _state.update { it.copy(signals = res.signals) }
                    }
                }
            } catch (e: Exception) {
                _state.update { it.copy(error = e.message) }
            }
        }
    }

    fun togglePaused() {
        val current = _state.value.dashboard?.bot?.paused ?: return
        viewModelScope.launch {
            try {
                val res = container.api.setBot(paused = !current)
                _state.update {
                    it.copy(
                        dashboard = it.dashboard?.copy(bot = res.bot ?: it.dashboard.bot),
                        message = if (res.bot?.paused == true) "Бот на паузе" else "Бот запущен",
                    )
                }
            } catch (e: Exception) {
                _state.update { it.copy(error = e.message) }
            }
        }
    }

    fun toggleTrading() {
        val current = _state.value.dashboard?.bot?.trading_enabled ?: return
        viewModelScope.launch {
            try {
                val res = container.api.setBot(tradingEnabled = !current)
                _state.update {
                    it.copy(
                        dashboard = it.dashboard?.copy(bot = res.bot ?: it.dashboard.bot),
                        message = if (res.bot?.trading_enabled == true) "Торговля включена" else "Торговля выключена",
                    )
                }
            } catch (e: Exception) {
                _state.update { it.copy(error = e.message) }
            }
        }
    }

    fun updateGridDraft(config: DirectionGridConfigDto) {
        _state.update { it.copy(gridDraft = config) }
    }

    fun saveDirectionGrid() {
        val draft = _state.value.gridDraft ?: return
        viewModelScope.launch {
            _state.update { it.copy(saving = true, error = null) }
            try {
                container.api.saveDirectionGrid(draft)
                refresh()
                _state.update { it.copy(saving = false, message = "Сетка сохранена") }
            } catch (e: Exception) {
                _state.update { it.copy(saving = false, error = e.message) }
            }
        }
    }

    private fun startPolling() {
        pollJob?.cancel()
        pollJob = viewModelScope.launch {
            while (isActive) {
                delay(5_000)
                if (!_state.value.loggedIn) continue
                runCatching {
                    val dash = container.api.dashboard()
                    val alert = container.api.alert()
                    container.alertSound.onTriggered(alert.triggered)
                    _state.update {
                        it.copy(
                            dashboard = dash,
                            orders = dash.orders,
                            positions = dash.positions,
                            signals = dash.signals,
                            alertTriggered = alert.triggered,
                            gridDraft = it.gridDraft ?: dash.direction_grid?.config,
                        )
                    }
                }
            }
        }
    }

    override fun onCleared() {
        pollJob?.cancel()
        container.alertSound.stop()
        super.onCleared()
    }
}

class AppViewModelFactory(private val container: AppContainer) : ViewModelProvider.Factory {
    @Suppress("UNCHECKED_CAST")
    override fun <T : ViewModel> create(modelClass: Class<T>): T {
        return AppViewModel(container) as T
    }
}
