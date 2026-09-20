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
import ru.tlt.tradesignals.data.OrderDto
import ru.tlt.tradesignals.data.PositionDto

@Composable
fun OrdersScreen(orders: List<OrderDto>, positions: List<PositionDto>) {
    LazyColumn(
        Modifier.fillMaxSize().padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        item { Text("Позиции", style = MaterialTheme.typography.titleMedium) }
        if (positions.isEmpty()) {
            item { Text("Нет позиций", color = MaterialTheme.colorScheme.onSurface.copy(0.6f)) }
        }
        items(positions) { p ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp)) {
                    Text("${p.side} · qty ${p.quantity}")
                    Text("entry ${p.entry_price} · mark ${p.mark_price}")
                    Text("uPnL ${p.unrealised_pnl}")
                    Text("TP ${p.take_profit} · SL ${p.stop_loss}")
                }
            }
        }
        item { Text("Ордера", style = MaterialTheme.typography.titleMedium) }
        if (orders.isEmpty()) {
            item { Text("Нет ордеров", color = MaterialTheme.colorScheme.onSurface.copy(0.6f)) }
        }
        items(orders) { o ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp)) {
                    Text("${o.side} ${o.order_type} · ${o.status}")
                    Text("qty ${o.quantity} @ ${o.price}")
                    Text(o.order_link_id ?: "")
                    Text(o.created_at ?: "", color = MaterialTheme.colorScheme.onSurface.copy(0.6f))
                }
            }
        }
    }
}
