package ph.edu.isatu.visitor.navigation

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Looper
import android.os.SystemClock
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** The latest GPS reading, and the steady position [PositionFilter] made of the readings so far. */
data class LivePosition(val reading: Location, val steady: SteadyPosition?)

/**
 * Second-by-second position for the map screen only, so the arrow moves smoothly and
 * directions react quickly. It stays on the phone: Security still receives only the
 * background service's regular uploads. Call [start] and [stop] with the screen's lifecycle.
 *
 * Every reading goes through a [PositionFilter], so the arrow does not jump with GPS:
 * [position] carries both the reading as it came and the steady position.
 */
class LiveLocation(context: Context) {
    private val appContext = context.applicationContext
    private val client = LocationServices.getFusedLocationProviderClient(appContext)
    private val locationManager = appContext.getSystemService(LocationManager::class.java)
    private var callback: LocationCallback? = null
    private val filter = PositionFilter()

    private val _location = MutableStateFlow<Location?>(null)
    val location: StateFlow<Location?> = _location.asStateFlow()

    private val _position = MutableStateFlow<LivePosition?>(null)
    val position: StateFlow<LivePosition?> = _position.asStateFlow()

    /** Starts updates; false when precise location permission is missing. */
    fun start(): Boolean {
        if (callback != null) return true
        if (ContextCompat.checkSelfPermission(appContext, Manifest.permission.ACCESS_FINE_LOCATION) !=
            PackageManager.PERMISSION_GRANTED
        ) {
            return false
        }
        // Coming back to the screen after a while, the old position is no longer the visitor's.
        if (_location.value?.let { ageMillis(it) > SEED_MAX_AGE_MILLIS } == true) {
            filter.reset()
            _location.value = null
            _position.value = null
        }
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, INTERVAL_MILLIS)
            .setMinUpdateIntervalMillis(INTERVAL_MILLIS)
            .setWaitForAccurateLocation(false)
            .build()
        val updates = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                result.locations.forEach(::publish)
            }
        }
        callback = updates
        client.requestLocationUpdates(request, updates, Looper.getMainLooper())
        // Show a position from the last few seconds at once while the first fresh fix arrives.
        // An older one would put the arrow where the visitor no longer is.
        client.lastLocation.addOnSuccessListener { last ->
            if (last != null && _location.value == null && ageMillis(last) <= SEED_MAX_AGE_MILLIS) publish(last)
        }
        return true
    }

    private fun publish(location: Location) {
        val steady = filter.update(
            PositionReading(
                point = GeoPoint(location.latitude, location.longitude),
                accuracyMeters = if (location.hasAccuracy()) location.accuracy.toDouble() else null,
                elapsedMillis = readingTimeMillis(location.elapsedRealtimeNanos, SystemClock.elapsedRealtime()),
                speedMetersPerSecond = if (location.hasSpeed()) location.speed.toDouble() else null,
            ),
        )
        _location.value = location
        _position.value = LivePosition(location, steady)
    }

    fun stop() {
        callback?.let { client.removeLocationUpdates(it) }
        callback = null
    }

    /** Whether the phone's Location setting is on at all. */
    fun servicesEnabled(): Boolean = locationManager?.let(LocationManagerCompat::isLocationEnabled) ?: false

    private fun ageMillis(location: Location): Long =
        (SystemClock.elapsedRealtimeNanos() - location.elapsedRealtimeNanos) / 1_000_000

    private companion object {
        const val INTERVAL_MILLIS = 1_000L
        const val SEED_MAX_AGE_MILLIS = 10_000L
    }
}
