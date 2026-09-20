package ru.tlt.tradesignals.data

import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.logging.HttpLoggingInterceptor
import java.util.concurrent.TimeUnit

class ApiClient(private val sessionStore: SessionStore) {
    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        encodeDefaults = true
    }

    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .addInterceptor(
            HttpLoggingInterceptor().apply {
                level = HttpLoggingInterceptor.Level.BASIC
            }
        )
        .build()

    private val mediaJson = "application/json; charset=utf-8".toMediaType()

    suspend fun login(baseUrl: String, username: String, password: String): LoginResponse =
        withContext(Dispatchers.IO) {
            val body = buildJsonObject {
                put("username", username)
                put("password", password)
                put("device_name", "Android ${Build.MODEL}")
            }
            postUnauth("$baseUrl/api/mobile/login.php", body.toString(), LoginResponse.serializer())
        }

    suspend fun logout() {
        runCatching { post("/api/mobile/logout.php", "{}") }
        sessionStore.clearToken()
    }

    suspend fun dashboard(): DashboardResponse =
        get("/api/mobile/dashboard.php", DashboardResponse.serializer())

    suspend fun alert(): AlertResponse =
        get("/api/mobile/alert.php", AlertResponse.serializer())

    suspend fun strategies(): StrategiesResponse =
        get("/api/mobile/strategies.php", StrategiesResponse.serializer())

    suspend fun orders(): OrdersResponse =
        get("/api/mobile/orders.php", OrdersResponse.serializer())

    suspend fun logs(limit: Int = 80): LogsResponse =
        get("/api/mobile/logs.php?limit=$limit", LogsResponse.serializer())

    suspend fun signals(limit: Int = 50): DashboardResponse {
        // reuse Signal list via dedicated endpoint mapped loosely
        val raw = getRaw("/api/mobile/signals.php?limit=$limit")
        val parsed = json.decodeFromString(SignalsEnvelope.serializer(), raw)
        return DashboardResponse(ok = parsed.ok, signals = parsed.signals, error = parsed.error)
    }

    suspend fun setBot(paused: Boolean? = null, tradingEnabled: Boolean? = null): BotUpdateResponse {
        val body = buildJsonObject {
            if (paused != null) put("paused", paused)
            if (tradingEnabled != null) put("trading_enabled", tradingEnabled)
        }
        return post("/api/mobile/bot.php", body.toString(), BotUpdateResponse.serializer())
    }

    suspend fun saveDirectionGrid(config: DirectionGridConfigDto): OkResponse {
        val body = json.encodeToString(DirectionGridConfigDto.serializer(), config)
        return post("/api/mobile/direction_grid.php", body, OkResponse.serializer())
    }

    private suspend fun <T> get(
        path: String,
        deserializer: kotlinx.serialization.DeserializationStrategy<T>,
    ): T = withContext(Dispatchers.IO) {
        json.decodeFromString(deserializer, getRaw(path))
    }

    private suspend fun getRaw(path: String): String = withContext(Dispatchers.IO) {
        val base = sessionStore.baseUrl()
        val token = sessionStore.token() ?: throw IllegalStateException("Нет токена")
        val request = Request.Builder()
            .url(base + path)
            .header("Authorization", "Bearer $token")
            .header("Accept", "application/json")
            .get()
            .build()
        client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                val err = runCatching { json.decodeFromString(ApiError.serializer(), text) }.getOrNull()
                throw IllegalStateException(err?.error ?: "HTTP ${response.code}")
            }
            text
        }
    }

    private suspend fun <T> post(
        path: String,
        body: String,
        deserializer: kotlinx.serialization.DeserializationStrategy<T>,
    ): T = withContext(Dispatchers.IO) {
        val base = sessionStore.baseUrl()
        val token = sessionStore.token() ?: throw IllegalStateException("Нет токена")
        val request = Request.Builder()
            .url(base + path)
            .header("Authorization", "Bearer $token")
            .header("Accept", "application/json")
            .post(body.toRequestBody(mediaJson))
            .build()
        client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                val err = runCatching { json.decodeFromString(ApiError.serializer(), text) }.getOrNull()
                throw IllegalStateException(err?.error ?: "HTTP ${response.code}")
            }
            json.decodeFromString(deserializer, text)
        }
    }

    private fun <T> postUnauth(
        url: String,
        body: String,
        deserializer: kotlinx.serialization.DeserializationStrategy<T>,
    ): T {
        val request = Request.Builder()
            .url(url)
            .header("Accept", "application/json")
            .post(body.toRequestBody(mediaJson))
            .build()
        client.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            val parsed = json.decodeFromString(deserializer, text)
            if (!response.isSuccessful) {
                val err = runCatching { json.decodeFromString(ApiError.serializer(), text) }.getOrNull()
                throw IllegalStateException(err?.error ?: "HTTP ${response.code}")
            }
            return parsed
        }
    }
}

@kotlinx.serialization.Serializable
private data class SignalsEnvelope(
    val ok: Boolean,
    val signals: List<SignalDto> = emptyList(),
    val error: String? = null,
)
