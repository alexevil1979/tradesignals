package ru.tlt.tradesignals.data

import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

class AlertSoundPlayer(private val context: Context) {
    private var tone: ToneGenerator? = null
    private var count = 0
    private var active = false
    private var lastBeepAt = 0L

    @Synchronized
    fun onTriggered(triggered: Boolean) {
        if (!triggered) {
            stop()
            return
        }
        if (!active) {
            active = true
            count = 0
        }
        val now = System.currentTimeMillis()
        if (now - lastBeepAt < 10_000 && count > 0) {
            return
        }
        if (count >= 20) {
            stop()
            return
        }
        beep()
        count += 1
        lastBeepAt = now
    }

    @Synchronized
    fun stop() {
        active = false
        count = 0
        try {
            tone?.release()
        } catch (_: Exception) {
        }
        tone = null
    }

    private fun beep() {
        try {
            if (tone == null) {
                tone = ToneGenerator(AudioManager.STREAM_ALARM, 60)
            }
            tone?.startTone(ToneGenerator.TONE_PROP_BEEP, 180)
        } catch (_: Exception) {
        }
        try {
            val vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                val vm = context.getSystemService(VibratorManager::class.java)
                vm?.defaultVibrator
            } else {
                @Suppress("DEPRECATION")
                context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
            }
            vibrator?.vibrate(VibrationEffect.createOneShot(80, VibrationEffect.DEFAULT_AMPLITUDE))
        } catch (_: Exception) {
        }
    }
}
