package ru.tlt.tradesignals

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.lifecycle.viewmodel.compose.viewModel
import ru.tlt.tradesignals.ui.AppNav
import ru.tlt.tradesignals.ui.AppViewModel
import ru.tlt.tradesignals.ui.AppViewModelFactory

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        val app = application as TradeSignalsApp
        setContent {
            MaterialTheme(
                colorScheme = darkColorScheme(
                    primary = Color(0xFF38BDF8),
                    secondary = Color(0xFFFBBF24),
                    background = Color(0xFF0D1117),
                    surface = Color(0xFF161B22),
                    error = Color(0xFFEF4444),
                )
            ) {
                Surface(Modifier.fillMaxSize()) {
                    val vm: AppViewModel = viewModel(
                        factory = AppViewModelFactory(app.container)
                    )
                    val state by vm.state.collectAsState()
                    AppNav(
                        state = state,
                        onLogin = vm::login,
                        onLogout = vm::logout,
                        onRefresh = vm::refresh,
                        onTogglePaused = vm::togglePaused,
                        onToggleTrading = vm::toggleTrading,
                        onSaveGrid = vm::saveDirectionGrid,
                        onUpdateDraft = vm::updateGridDraft,
                        onLoadSection = vm::loadSection,
                    )
                }
            }
        }
    }
}
