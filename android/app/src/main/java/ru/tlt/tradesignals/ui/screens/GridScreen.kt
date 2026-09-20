package ru.tlt.tradesignals.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import ru.tlt.tradesignals.data.DirectionGridConfigDto
import ru.tlt.tradesignals.data.LevelDto
import ru.tlt.tradesignals.data.OverlayDto

@Composable
fun GridScreen(
    draft: DirectionGridConfigDto?,
    overlay: OverlayDto?,
    saving: Boolean,
    message: String?,
    error: String?,
    onChange: (DirectionGridConfigDto) -> Unit,
    onSave: () -> Unit,
) {
    if (draft == null) {
        Text("Нет данных сетки", Modifier.padding(16.dp))
        return
    }

    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("Слежение за хаем/лоем", style = MaterialTheme.typography.titleLarge)

        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Включена")
            Switch(checked = draft.enabled, onCheckedChange = { onChange(draft.copy(enabled = it)) })
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Тестовый режим")
            Switch(checked = draft.test_mode, onCheckedChange = { onChange(draft.copy(test_mode = it)) })
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            Text("Уровни на H1 (веб)")
            Switch(checked = draft.chart_h1, onCheckedChange = { onChange(draft.copy(chart_h1 = it)) })
        }

        OutlinedTextField(
            value = draft.mode,
            onValueChange = { onChange(draft.copy(mode = if (it == "low") "low" else "high")) },
            label = { Text("mode: high | low") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        OutlinedTextField(
            value = draft.period_minutes.toString(),
            onValueChange = { v ->
                v.toIntOrNull()?.let { onChange(draft.copy(period_minutes = it)) }
            },
            label = { Text("Период, мин") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        OutlinedTextField(
            value = draft.profit.toString(),
            onValueChange = { v ->
                v.toDoubleOrNull()?.let { onChange(draft.copy(profit = it)) }
            },
            label = { Text("Профит $") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        OutlinedTextField(
            value = draft.stop.toString(),
            onValueChange = { v ->
                v.toDoubleOrNull()?.let { onChange(draft.copy(stop = it)) }
            },
            label = { Text("Стоп $") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )

        draft.levels.forEachIndexed { index, lvl ->
            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("L${index + 1}", style = MaterialTheme.typography.titleMedium)
                    OutlinedTextField(
                        value = lvl.offset.toString(),
                        onValueChange = { v ->
                            val offset = v.toDoubleOrNull() ?: return@OutlinedTextField
                            onChange(draft.copy(levels = draft.levels.updateAt(index) { it.copy(offset = offset) }))
                        },
                        label = { Text("Отступ $") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        modifier = Modifier.fillMaxWidth(),
                        singleLine = true,
                    )
                    OutlinedTextField(
                        value = lvl.size,
                        onValueChange = { size ->
                            onChange(draft.copy(levels = draft.levels.updateAt(index) { it.copy(size = size) }))
                        },
                        label = { Text("Объём") },
                        modifier = Modifier.fillMaxWidth(),
                        singleLine = true,
                    )
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Checkbox(
                            checked = lvl.sound,
                            onCheckedChange = { checked ->
                                onChange(draft.copy(levels = draft.levels.updateAt(index) { it.copy(sound = checked) }))
                            },
                        )
                        Text("Звук")
                        Checkbox(
                            checked = lvl.telegram,
                            onCheckedChange = { checked ->
                                onChange(draft.copy(levels = draft.levels.updateAt(index) { it.copy(telegram = checked) }))
                            },
                        )
                        Text("Telegram")
                    }
                    val preview = overlay?.levels?.getOrNull(index)?.price
                    if (preview != null) {
                        Text("Превью: $preview", color = MaterialTheme.colorScheme.primary)
                    }
                }
            }
        }

        if (!error.isNullOrBlank()) Text(error, color = MaterialTheme.colorScheme.error)
        if (!message.isNullOrBlank()) Text(message, color = MaterialTheme.colorScheme.primary)

        Button(onClick = onSave, enabled = !saving, modifier = Modifier.fillMaxWidth()) {
            Text(if (saving) "Сохранение…" else "Сохранить сетку")
        }
    }
}

private fun List<LevelDto>.updateAt(index: Int, block: (LevelDto) -> LevelDto): List<LevelDto> =
    mapIndexed { i, item -> if (i == index) block(item) else item }
