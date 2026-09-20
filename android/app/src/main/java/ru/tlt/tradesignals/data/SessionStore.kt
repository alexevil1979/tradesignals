package ru.tlt.tradesignals.data

import android.content.Context
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore("tradesignals_session")

class SessionStore(private val context: Context) {
    private val keyBaseUrl = stringPreferencesKey("base_url")
    private val keyToken = stringPreferencesKey("token")
    private val keyUsername = stringPreferencesKey("username")

    val baseUrlFlow: Flow<String> = context.dataStore.data.map {
        it[keyBaseUrl]?.trimEnd('/') ?: "https://td.1tlt.ru"
    }

    val tokenFlow: Flow<String?> = context.dataStore.data.map { it[keyToken] }

    val usernameFlow: Flow<String?> = context.dataStore.data.map { it[keyUsername] }

    suspend fun baseUrl(): String = baseUrlFlow.first()

    suspend fun token(): String? = tokenFlow.first()

    suspend fun saveSession(baseUrl: String, token: String, username: String) {
        context.dataStore.edit {
            it[keyBaseUrl] = baseUrl.trimEnd('/')
            it[keyToken] = token
            it[keyUsername] = username
        }
    }

    suspend fun saveBaseUrl(baseUrl: String) {
        context.dataStore.edit { it[keyBaseUrl] = baseUrl.trimEnd('/') }
    }

    suspend fun clearToken() {
        context.dataStore.edit {
            it.remove(keyToken)
            it.remove(keyUsername)
        }
    }
}
