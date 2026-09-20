package ru.tlt.tradesignals.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import ru.tlt.tradesignals.data.SignalDto

@Composable
fun SignalsScreen(signals: List<SignalDto>) {
    LazyColumn(
        Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        item { Text("Сигналы", style = MaterialTheme.typography.titleMedium) }
        if (signals.isEmpty()) {
            item { Text("Пока пусто", color = MaterialTheme.colorScheme.onSurface.copy(0.6f)) }
        }
        items(signals) { s ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp)) {
                    Text("${s.side} · ${s.signal_type}")
                    Text("price ${s.price}")
                    Text("candle ${s.candle_open_time}")
                    Text(
                        "TG: ${s.telegram_sent_at ?: "—"}",
                        color = MaterialTheme.colorScheme.onSurface.copy(0.6f),
                    )
                }
            }
        }
    }
}
