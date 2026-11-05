package ph.edu.isatu.visitor.navigation

import android.content.Context
import androidx.core.content.edit

/** Whether the campus visit screen is showing, so the background service stays quiet while it is. */
object VisitScreen {
    @Volatile
    var showing: Boolean = false

    /** How long after reaching a building the one reminder to tap "Yes, I'm here" comes. */
    const val ARRIVAL_REMINDER_MILLIS = 5 * 60_000L
}

/**
 * The visitor's choices for the campus map, plus where they checked in for each visit
 * (used to guide them back when no gate is set up on the map). Stays on the phone.
 */
class NavigationPreferences(context: Context) {
    private val preferences = context.getSharedPreferences("visitor_navigation", Context.MODE_PRIVATE)

    var voiceEnabled: Boolean
        get() = preferences.getBoolean(KEY_VOICE, true)
        set(value) = preferences.edit { putBoolean(KEY_VOICE, value) }

    var unit: DistanceUnit
        get() = if (preferences.getString(KEY_UNIT, null) == DistanceUnit.FEET.name) DistanceUnit.FEET else DistanceUnit.METERS
        set(value) = preferences.edit { putString(KEY_UNIT, value.name) }

    var keepScreenOn: Boolean
        get() = preferences.getBoolean(KEY_SCREEN_ON, true)
        set(value) = preferences.edit { putBoolean(KEY_SCREEN_ON, value) }

    fun entryPoint(appointmentId: Long): GeoPoint? {
        if (preferences.getLong(KEY_ENTRY_APPOINTMENT, 0L) != appointmentId) return null
        val latitude = preferences.getString(KEY_ENTRY_LATITUDE, null)?.toDoubleOrNull() ?: return null
        val longitude = preferences.getString(KEY_ENTRY_LONGITUDE, null)?.toDoubleOrNull() ?: return null
        return GeoPoint(latitude, longitude)
    }

    /** Only the latest visit's entry point is kept. */
    fun saveEntryPoint(appointmentId: Long, point: GeoPoint) = preferences.edit {
        putLong(KEY_ENTRY_APPOINTMENT, appointmentId)
        putString(KEY_ENTRY_LATITUDE, point.latitude.toString())
        putString(KEY_ENTRY_LONGITUDE, point.longitude.toString())
    }

    /** Which step the visitor is on inside the building, so reopening the app returns to it; -1 when none. */
    fun insideStep(stopId: Long): Int =
        if (preferences.getLong(KEY_INSIDE_STOP, 0L) == stopId) preferences.getInt(KEY_INSIDE_STEP, -1) else -1

    fun saveInsideStep(stopId: Long, step: Int) = preferences.edit {
        putLong(KEY_INSIDE_STOP, stopId)
        putInt(KEY_INSIDE_STEP, step)
    }

    /** The last stop whose building the visitor said they came out of, so they aren't asked again. */
    var outsideStop: Long
        get() = preferences.getLong(KEY_OUTSIDE_STOP, 0L)
        set(value) = preferences.edit { putLong(KEY_OUTSIDE_STOP, value) }

    /** When the visitor first reached this stop's building, in milliseconds; 0 until then. */
    fun reachedBuildingAt(stopId: Long): Long =
        if (preferences.getLong(KEY_REACHED_STOP, 0L) == stopId) preferences.getLong(KEY_REACHED_AT, 0L) else 0L

    /** Only the latest stop is kept. The notification and the reminder are each sent once per stop. */
    fun saveReachedBuilding(stopId: Long, atMillis: Long) {
        if (reachedBuildingAt(stopId) > 0L) return
        preferences.edit {
            putLong(KEY_REACHED_STOP, stopId)
            putLong(KEY_REACHED_AT, atMillis)
            putBoolean(KEY_NEAR_SENT, false)
            putBoolean(KEY_REMINDER_SENT, false)
        }
    }

    /** True the first time it is asked after reaching the building, then false. */
    fun takeNearNotification(): Boolean {
        if (preferences.getBoolean(KEY_NEAR_SENT, false)) return false
        preferences.edit { putBoolean(KEY_NEAR_SENT, true) }
        return true
    }

    /** The one reminder for a visitor who reached the building but never tapped. */
    fun takeArrivalReminder(): Boolean {
        if (preferences.getBoolean(KEY_REMINDER_SENT, false)) return false
        preferences.edit { putBoolean(KEY_REMINDER_SENT, true) }
        return true
    }

    private companion object {
        const val KEY_OUTSIDE_STOP = "outside_stop_id"
        const val KEY_INSIDE_STOP = "inside_stop_id"
        const val KEY_INSIDE_STEP = "inside_step"
        const val KEY_REACHED_STOP = "reached_stop_id"
        const val KEY_REACHED_AT = "reached_at"
        const val KEY_NEAR_SENT = "near_notification_sent"
        const val KEY_REMINDER_SENT = "arrival_reminder_sent"
        const val KEY_VOICE = "voice_enabled"
        const val KEY_UNIT = "distance_unit"
        const val KEY_SCREEN_ON = "keep_screen_on"
        const val KEY_ENTRY_APPOINTMENT = "entry_appointment_id"
        const val KEY_ENTRY_LATITUDE = "entry_latitude"
        const val KEY_ENTRY_LONGITUDE = "entry_longitude"
    }
}
