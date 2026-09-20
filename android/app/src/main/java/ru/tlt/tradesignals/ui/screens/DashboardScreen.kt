package ru.tlt.tradesignals.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import ru.tlt.tradesignals.ui.UiState
import java.util.Locale

@Composable
fun DashboardScreen(
    state: UiState,
    onRefresh: () -> Unit,
    onLogout: () -> Unit,
    onTogglePaused: () -> Unit,
    onToggleTrading: () -> Unit,
) {
    val dash = state.dashboard
    val price = dash?.market?.price
    val bot = dash?.bot
    val overlay = dash?.direction_grid?.overlay

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(
            Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column {
                Text(dash?.market?.symbol ?: "BTCUSDT", style = MaterialTheme.typography.titleLarge)
                Text(state.username ?: "", color = MaterialTheme.colorScheme.onSurface.copy(0.6f))
            }
            TextButton(onClick = onLogout) { Text("Выйти") }
        }

        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp)) {
                Text("Цена", style = MaterialTheme.typography.labelMedium)
                Text(
                    price?.let { String.format(Locale.US, "%,.2f", it) } ?: "—",
                    style = MaterialTheme.typography.headlineMedium,
                    color = MaterialTheme.colorScheme.primary,
                )
                if (state.alertTriggered) {
                    Spacer(Modifier.height(8.dp))
                    Text("⚠ Уровень сетки пробит — звук", color = Color(0xFFFBBF24))
                }
            }
        }

        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Бот", style = MaterialTheme.typography.titleMedium)
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text(if (bot?.paused == true) "На паузе" else "Работает")
                    Switch(checked = bot?.paused != true, onCheckedChange = { onTogglePaused() })
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                    Text("Торговля")
                    Switch(
                        checked = bot?.trading_enabled == true,
                        onCheckedChange = { onToggleTrading() },
                    )
                }
            }
        }

        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text("Сетка слежения", style = MaterialTheme.typography.titleMedium)
                val cfg = dash?.direction_grid?.config
                Text("Режим: ${cfg?.mode ?: "—"} · период ${cfg?.period_minutes ?: "—"}м")
                Text(
                    "Включена: ${if (cfg?.enabled == true) "да" else "нет"} · test: ${if (cfg?.test_mode == true) "да" else "нет"}"
                )
                Text("Anchor: ${overlay?.anchor ?: "—"}")
                overlay?.levels?.forEach { lvl ->
                    Text("${lvl.title ?: "L"}: ${lvl.price}")
                }
                Text("TP: ${overlay?.tp ?: "—"} · SL: ${overlay?.sl ?: "—"}")
            }
        }

        if (!state.error.isNullOrBlank()) {
            Text(state.error, color = MaterialTheme.colorScheme.error)
        }
        if (!state.message.isNullOrBlank()) {
            Text(state.message, color = MaterialTheme.colorScheme.primary)
        }

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Button(onClick = onRefresh) { Text(if (state.loading) "…" else "Обновить") }
            OutlinedButton(onClick = onRefresh) { Text("Sync") }
        }
    }
}
