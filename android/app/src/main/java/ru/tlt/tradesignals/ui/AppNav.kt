package ru.tlt.tradesignals.ui

import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Analytics
import androidx.compose.material.icons.filled.GridOn
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.ListAlt
import androidx.compose.material.icons.filled.ReceiptLong
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import ru.tlt.tradesignals.data.DirectionGridConfigDto
import ru.tlt.tradesignals.ui.screens.DashboardScreen
import ru.tlt.tradesignals.ui.screens.GridScreen
import ru.tlt.tradesignals.ui.screens.LoginScreen
import ru.tlt.tradesignals.ui.screens.LogsScreen
import ru.tlt.tradesignals.ui.screens.OrdersScreen
import ru.tlt.tradesignals.ui.screens.SignalsScreen

@Composable
fun AppNav(
    state: UiState,
    onLogin: (String, String, String) -> Unit,
    onLogout: () -> Unit,
    onRefresh: () -> Unit,
    onTogglePaused: () -> Unit,
    onToggleTrading: () -> Unit,
    onSaveGrid: () -> Unit,
    onUpdateDraft: (DirectionGridConfigDto) -> Unit,
    onLoadSection: (String) -> Unit,
) {
    if (state.booting) {
        Text("Загрузка…")
        return
    }
    if (!state.loggedIn) {
        LoginScreen(
            baseUrl = state.baseUrl,
            loading = state.loading,
            error = state.error,
            onLogin = onLogin,
        )
        return
    }

    val nav = rememberNavController()
    val backStack by nav.currentBackStackEntryAsState()
    val route = backStack?.destination?.route ?: "home"

    Scaffold(
        bottomBar = {
            NavigationBar {
                NavigationBarItem(
                    selected = route == "home",
                    onClick = { nav.navigate("home") { launchSingleTop = true } },
                    icon = { Icon(Icons.Default.Home, null) },
                    label = { Text("Главная") },
                )
                NavigationBarItem(
                    selected = route == "grid",
                    onClick = {
                        onLoadSection("strategies")
                        nav.navigate("grid") { launchSingleTop = true }
                    },
                    icon = { Icon(Icons.Default.GridOn, null) },
                    label = { Text("Сетка") },
                )
                NavigationBarItem(
                    selected = route == "orders",
                    onClick = {
                        onLoadSection("orders")
                        nav.navigate("orders") { launchSingleTop = true }
                    },
                    icon = { Icon(Icons.Default.ReceiptLong, null) },
                    label = { Text("Ордера") },
                )
                NavigationBarItem(
                    selected = route == "signals",
                    onClick = {
                        onLoadSection("signals")
                        nav.navigate("signals") { launchSingleTop = true }
                    },
                    icon = { Icon(Icons.Default.Analytics, null) },
                    label = { Text("Сигналы") },
                )
                NavigationBarItem(
                    selected = route == "logs",
                    onClick = {
                        onLoadSection("logs")
                        nav.navigate("logs") { launchSingleTop = true }
                    },
                    icon = { Icon(Icons.Default.ListAlt, null) },
                    label = { Text("Логи") },
                )
            }
        }
    ) { padding ->
        NavHost(
            navController = nav,
            startDestination = "home",
            modifier = Modifier.padding(padding),
        ) {
            composable("home") {
                DashboardScreen(
                    state = state,
                    onRefresh = onRefresh,
                    onLogout = onLogout,
                    onTogglePaused = onTogglePaused,
                    onToggleTrading = onToggleTrading,
                )
            }
            composable("grid") {
                GridScreen(
                    draft = state.gridDraft,
                    overlay = state.dashboard?.direction_grid?.overlay,
                    saving = state.saving,
                    message = state.message,
                    error = state.error,
                    onChange = onUpdateDraft,
                    onSave = onSaveGrid,
                )
            }
            composable("orders") {
                OrdersScreen(orders = state.orders, positions = state.positions)
            }
            composable("signals") {
                SignalsScreen(signals = state.signals)
            }
            composable("logs") {
                LogsScreen(logs = state.logs)
            }
        }
    }
}
