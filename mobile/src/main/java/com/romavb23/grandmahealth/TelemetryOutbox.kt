package com.romavb23.grandmahealth

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import com.google.android.gms.wearable.DataMap
import org.json.JSONObject
import java.util.UUID

internal class TelemetryOutbox private constructor(context: Context) :
    SQLiteOpenHelper(context, "telemetry_outbox.sqlite", null, 1) {

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("CREATE TABLE outbox (id TEXT PRIMARY KEY, body TEXT NOT NULL, source TEXT NOT NULL, received_at INTEGER NOT NULL, blocked INTEGER NOT NULL DEFAULT 0)")
        db.execSQL("CREATE INDEX outbox_pending ON outbox(blocked, received_at)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        error("Unsupported outbox migration: $oldVersion -> $newVersion")
    }

    fun enqueue(data: DataMap, heartbeat: Boolean, receivedAt: Long, status: String) {
        val sentAt = data.getLong(WATCH_KEY_SENT_AT, data.getLong(HEART_RATE_KEY_MEASURED_AT, 0L))
        if (sentAt <= 0 || sentAt > receivedAt + WatchFreshness.CLOCK_TOLERANCE_MS) return
        val measuredAt = data.getLong(HEART_RATE_KEY_MEASURED_AT, 0L)
        val bpm = data.getInt(HEART_RATE_KEY_BPM, -1)
        val validPulse = bpm in 1..1000 && measuredAt > 0 &&
            measuredAt <= sentAt + WatchFreshness.CLOCK_TOLERANCE_MS &&
            measuredAt <= receivedAt + WatchFreshness.CLOCK_TOLERANCE_MS
        if (!heartbeat && !validPulse) return
        val id = UUID.randomUUID().toString()
        val source = if (heartbeat) "heartbeat" else "measurement"
        val wearingState = data.getString("wearing_state") ?: "unknown"
        val wearingSince = data.getLong("wearing_since_ms", 0L)
        val validWearing = wearingState in setOf("on", "off") && wearingSince in 1L..sentAt
        val body = JSONObject().put("event_id", id).put("device_id", "grandma-watch")
            .put("source", source).put("received_at_ms", receivedAt).put("watch_sent_at_ms", sentAt)
            .put("bpm", if (validPulse) bpm else JSONObject.NULL)
            .put("measured_at_ms", if (validPulse) measuredAt else JSONObject.NULL)
            .put("battery_percent", data.getInt(HEART_RATE_KEY_BATTERY_PERCENT, -1)
                .takeIf { it in 0..100 } ?: JSONObject.NULL)
            .put("charging", data.getBoolean(HEART_RATE_KEY_CHARGING, false))
            .put("monitoring_status", status.takeIf { it in STATUSES } ?: "unknown")
            .put("wearing_state", if (validWearing) wearingState else "unknown")
            .put("wearing_since_ms", if (validWearing) wearingSince else JSONObject.NULL).toString()
        // SQLite transaction is complete before we announce a packet ready for upload.
        writableDatabase.insertOrThrow("outbox", null, ContentValues().apply {
            put("id", id); put("body", body); put("source", source); put("received_at", receivedAt)
        })
    }

    fun next(prioritizeLive: Boolean): Packet? {
        // One fresh heartbeat first, then FIFO history. Backlog cannot starve live contact.
        val order = if (prioritizeLive) {
            "CASE WHEN source='heartbeat' AND received_at>=${System.currentTimeMillis() - 120_000L} THEN -received_at ELSE received_at END ASC"
        } else "received_at ASC"
        readableDatabase.query("outbox", arrayOf("id", "body"), "blocked=0", null, null, null, order, "1").use {
            return if (it.moveToFirst()) Packet(it.getString(0), it.getString(1)) else null
        }
    }

    fun acknowledge(id: String) { writableDatabase.delete("outbox", "id=?", arrayOf(id)) }
    fun block(id: String) {
        writableDatabase.update("outbox", ContentValues().apply { put("blocked", 1) }, "id=?", arrayOf(id))
    }
    fun retryBlocked() { writableDatabase.execSQL("UPDATE outbox SET blocked=0") }
    fun count(blocked: Boolean): Int = readableDatabase.rawQuery(
        "SELECT count(*) FROM outbox WHERE blocked=?", arrayOf(if (blocked) "1" else "0"),
    ).use { it.moveToFirst(); it.getInt(0) }

    data class Packet(val id: String, val body: String)

    companion object {
        private val STATUSES = setOf("active", "starting", "stopped", "permission_lost", "unsupported", "error", "unknown")
        @Volatile private var instance: TelemetryOutbox? = null
        fun get(context: Context): TelemetryOutbox = instance ?: synchronized(this) {
            instance ?: TelemetryOutbox(context.applicationContext).also { instance = it }
        }
    }
}
