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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import ru.tlt.tradesignals.data.LogDto

@Composable
fun LogsScreen(logs: List<LogDto>) {
    LazyColumn(
        Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        item { Text("Логи", style = MaterialTheme.typography.titleMedium) }
        if (logs.isEmpty()) {
            item { Text("Нет записей", color = MaterialTheme.colorScheme.onSurface.copy(0.6f)) }
        }
        items(logs) { log ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp)) {
                    val color = when (log.level) {
                        "error" -> MaterialTheme.colorScheme.error
                        "warning" -> Color(0xFFFBBF24)
                        else -> MaterialTheme.colorScheme.onSurface
                    }
                    Text("${log.level} · ${log.channel}", color = color)
                    Text(log.message ?: "")
                    Text(log.created_at ?: "", color = MaterialTheme.colorScheme.onSurface.copy(0.6f))
                }
            }
        }
    }
}
