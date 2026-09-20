package ru.tlt.tradesignals

import android.app.Application

class TradeSignalsApp : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
    }
}
