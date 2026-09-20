package ru.tlt.tradesignals

import android.content.Context
import ru.tlt.tradesignals.data.AlertSoundPlayer
import ru.tlt.tradesignals.data.ApiClient
import ru.tlt.tradesignals.data.SessionStore

class AppContainer(context: Context) {
    val sessionStore = SessionStore(context.applicationContext)
    val api = ApiClient(sessionStore)
    val alertSound = AlertSoundPlayer(context.applicationContext)
}
