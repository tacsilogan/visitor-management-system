package ph.edu.isatu.visitor.service

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.Build
import android.os.IBinder
import android.os.Looper
import android.os.SystemClock
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import ph.edu.isatu.visitor.MainActivity
import ph.edu.isatu.visitor.R
import ph.edu.isatu.visitor.VisitorApplication
import ph.edu.isatu.visitor.data.ApiException
import ph.edu.isatu.visitor.data.CampusStopDto
import ph.edu.isatu.visitor.data.VisitorRepository
import ph.edu.isatu.visitor.navigation.GeoMath
import ph.edu.isatu.visitor.navigation.GeoPoint
import ph.edu.isatu.visitor.navigation.NavigationPreferences
import ph.edu.isatu.visitor.navigation.PositionFilter
import ph.edu.isatu.visitor.navigation.PositionReading
import ph.edu.isatu.visitor.navigation.VisitScreen
import ph.edu.isatu.visitor.navigation.toDirections
import ph.edu.isatu.visitor.navigation.readingTimeMillis

class VisitorTrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val uploadMutex = Mutex()
    private lateinit var repository: VisitorRepository
    private val locationClient by lazy { LocationServices.getFusedLocationProviderClient(this) }
    private var appointmentId: Long = 0
    private var sessionId: Long = 0
    private var maximumBatchPoints: Int = 100
    private var locationCallback: LocationCallback? = null
    private var anchorLocation: Location? = null
    private var monitorJob: Job? = null
    private val filter = PositionFilter()
    private var uploadIntervalMillis = 15_000L
    private var queuedAtMillis: Long? = null
    private lateinit var navigationPreferences: NavigationPreferences
    /** Where the visitor is going next, from the campus map; null once every stop is done. */
    @Volatile
    private var destination: CampusStopDto? = null

    override fun onCreate() {
        super.onCreate()
        repository = (application as VisitorApplication).repository
        navigationPreferences = NavigationPreferences(this)
        createChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            val requestedId = intent.getLongExtra(EXTRA_APPOINTMENT_ID, appointmentId)
            scope.launch {
                if (requestedId > 0) repository.stopTracking(requestedId)
                stopServiceNow()
            }
            return START_NOT_STICKY
        }

        appointmentId = intent?.getLongExtra(EXTRA_APPOINTMENT_ID, 0L)
            ?.takeIf { it > 0 }
            ?: repository.installationStore.activeAppointmentId()
            ?: 0L
        if (appointmentId <= 0 || !hasLocationPermission()) {
            stopSelf()
            return START_NOT_STICKY
        }

        startAsForeground("Preparing secure location sharing…")
        scope.launch { startRemoteSession() }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        locationCallback?.let { locationClient.removeLocationUpdates(it) }
        monitorJob?.cancel()
        scope.cancel()
        super.onDestroy()
    }

    private suspend fun startRemoteSession() {
        if (locationCallback != null) return
        try {
            val tracking = repository.startTracking(appointmentId)
            val session = tracking.session ?: throw IllegalStateException("The server did not create a tracking session.")
            sessionId = session.id
            maximumBatchPoints = tracking.policy.maximumBatchPoints.coerceIn(1, 100)
            destination = repository.cachedCampusMap(appointmentId)?.destination
            requestLocationUpdates(tracking.policy.uploadIntervalSeconds.coerceIn(5, 300))
            monitorServerState()
            updateNotification("Sharing location for your checked-in visit")
            flushQueued()
        } catch (error: Exception) {
            updateNotification(error.message ?: "Location sharing could not start")
            delay(3500)
            stopServiceNow()
        }
    }

    private fun requestLocationUpdates(intervalSeconds: Int) {
        if (!hasLocationPermission()) {
            stopServiceNow()
            return
        }
        val callback = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                result.locations.forEach(::handleLocation)
            }
        }
        locationCallback = callback
        val interval = intervalSeconds * 1000L
        uploadIntervalMillis = interval
        // Readings are taken more often than they are uploaded, so the filter can tell a GPS
        // jump from a walk. Security still receives one position per upload interval.
        val sampling = minOf(interval, SAMPLE_INTERVAL_MILLIS)
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, sampling)
            .setMinUpdateIntervalMillis(sampling)
            .setWaitForAccurateLocation(false)
            .build()
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED) {
            locationClient.requestLocationUpdates(request, callback, Looper.getMainLooper())
        }
    }

    /**
     * Every reading steadies the position ([PositionFilter] leaves out GPS jumps and weak
     * readings); once per upload interval the steady position is queued for Security.
     */
    private fun handleLocation(location: Location) {
        if (sessionId <= 0) return
        val elapsedMillis = readingTimeMillis(location.elapsedRealtimeNanos, SystemClock.elapsedRealtime())
        val steady = filter.update(
            PositionReading(
                point = GeoPoint(location.latitude, location.longitude),
                accuracyMeters = if (location.hasAccuracy()) location.accuracy.toDouble() else null,
                elapsedMillis = elapsedMillis,
                speedMetersPerSecond = if (location.hasSpeed()) location.speed.toDouble() else null,
            ),
        ) ?: return
        checkBuildingReached(steady.point, steady.accuracyMeters)
        val queuedAt = queuedAtMillis
        if (queuedAt != null && elapsedMillis - queuedAt < uploadIntervalMillis - UPLOAD_SLACK_MILLIS) return
        queuedAtMillis = elapsedMillis
        val reported = stabilize(
            Location(location).apply {
                latitude = steady.point.latitude
                longitude = steady.point.longitude
                accuracy = steady.accuracyMeters.toFloat()
            },
        )
        scope.launch {
            repository.queueLocation(appointmentId, sessionId, reported)
            flushQueued()
        }
    }

    /**
     * With the app in the background, tells the visitor once when they reach the building, and
     * reminds them once if they never tap "Yes, I'm here" (INDOOR_DIRECTIONS.md). On the campus
     * visit screen the question is already in front of them, so nothing is shown.
     */
    private fun checkBuildingReached(point: GeoPoint, accuracyMeters: Double) {
        val stop = destination ?: return
        if (stop.arrivedAt != null) return
        val latitude = stop.latitude ?: return
        val longitude = stop.longitude ?: return
        val now = System.currentTimeMillis()
        var reachedAt = navigationPreferences.reachedBuildingAt(stop.appointmentId)
        if (reachedAt == 0L) {
            if (accuracyMeters > BUILDING_MAX_ACCURACY_METERS ||
                GeoMath.distanceMeters(point, GeoPoint(latitude, longitude)) > BUILDING_REACHED_METERS
            ) {
                return
            }
            navigationPreferences.saveReachedBuilding(stop.appointmentId, now)
            reachedAt = now
            // The map on screen has already asked.
            if (VisitScreen.showing) navigationPreferences.takeNearNotification()
        }
        if (VisitScreen.showing) return
        val directions = stop.toDirections()
        if (navigationPreferences.takeNearNotification()) {
            showAlert(NEAR_NOTIFICATION_ID, directions.nearNotificationTitle, directions.nearNotificationText)
        }
        if (now - reachedAt >= VisitScreen.ARRIVAL_REMINDER_MILLIS) {
            // The map's latest copy says whether the tap has been made since.
            val latest = repository.cachedCampusMap(appointmentId)?.stops?.firstOrNull { it.appointmentId == stop.appointmentId }
            if (latest?.arrivedAt == null && navigationPreferences.takeArrivalReminder()) {
                showAlert(REMINDER_NOTIFICATION_ID, directions.reminderTitle, directions.reminderText)
            }
        }
    }

    private fun showAlert(id: Int, title: String, text: String) {
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel(ALERT_CHANNEL_ID, getString(R.string.notifications_channel_name), NotificationManager.IMPORTANCE_DEFAULT),
        )
        val open = PendingIntent.getActivity(
            this,
            id,
            Intent(this, MainActivity::class.java)
                .setFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(EXTRA_APPOINTMENT_ID, appointmentId),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        manager.notify(
            id,
            NotificationCompat.Builder(this, ALERT_CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_notification)
                .setContentTitle(title)
                .setContentText(text)
                .setStyle(NotificationCompat.BigTextStyle().bigText(text))
                .setAutoCancel(true)
                .setContentIntent(open)
                .build(),
        )
    }

    /**
     * GPS readings wander by several metres even when the phone is still. Keep reporting the
     * last accepted position (with a fresh timestamp, so Security still sees the visitor as live)
     * until the phone has moved further than the reading's own accuracy radius.
     */
    private fun stabilize(location: Location): Location {
        val previous = anchorLocation
        val accuracy = if (location.hasAccuracy()) location.accuracy else Float.MAX_VALUE
        val previousAccuracy = if (previous?.hasAccuracy() == true) previous.accuracy else Float.MAX_VALUE
        val moved = previous != null &&
            accuracy <= MAX_MOVEMENT_ACCURACY_METERS &&
            previous.distanceTo(location) > maxOf(MIN_MOVEMENT_METERS, accuracy)
        val sharper = accuracy < previousAccuracy / 2
        if (previous == null || moved || sharper) {
            anchorLocation = Location(location)
            return location
        }
        return Location(location).apply {
            latitude = previous.latitude
            longitude = previous.longitude
            if (previous.hasAccuracy()) this.accuracy = previous.accuracy
        }
    }

    private suspend fun flushQueued() = uploadMutex.withLock {
        try {
            repository.flushLocations(sessionId, maximumBatchPoints)
            val remaining = repository.queuedLocationCount(sessionId)
            updateNotification(
                if (remaining == 0) "Location is being shared securely"
                else "Location is saved; $remaining update(s) waiting for internet",
            )
        } catch (error: ApiException) {
            if (error.statusCode in listOf(401, 403, 404, 409)) {
                updateNotification(error.message)
                stopServiceNow()
            } else {
                LocationUploadWorker.enqueue(this, sessionId)
                showOfflineCount()
            }
        } catch (_: Exception) {
            LocationUploadWorker.enqueue(this, sessionId)
            showOfflineCount()
        }
    }

    private suspend fun showOfflineCount() {
        val count = repository.queuedLocationCount(sessionId)
        updateNotification("Offline — $count location update(s) saved on this phone")
    }

    private fun monitorServerState() {
        monitorJob?.cancel()
        monitorJob = scope.launch {
            while (true) {
                delay(30_000)
                val state = runCatching { repository.tracking(appointmentId) }.getOrNull() ?: continue
                if (!state.trackingAllowed || state.session?.active != true) {
                    stopServiceNow()
                    break
                }
                // The campus visit screen keeps the map up to date while it shows.
                val map = if (VisitScreen.showing) {
                    repository.cachedCampusMap(appointmentId)
                } else {
                    runCatching { repository.campusMap(appointmentId) }.getOrNull()
                }
                if (map != null) destination = map.destination
            }
        }
    }

    private fun startAsForeground(text: String) {
        ServiceCompat.startForeground(
            this,
            NOTIFICATION_ID,
            notification(text),
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0,
        )
    }

    private fun updateNotification(text: String) {
        getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification(text))
    }

    private fun notification(text: String): Notification {
        val openIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java).putExtra("appointment_id", appointmentId),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val stopIntent = PendingIntent.getService(
            this,
            1,
            Intent(this, VisitorTrackingService::class.java)
                .setAction(ACTION_STOP)
                .putExtra(EXTRA_APPOINTMENT_ID, appointmentId),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("ISATU visit tracking active")
            .setContentText(text)
            .setContentIntent(openIntent)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .addAction(0, "Stop sharing", stopIntent)
            .build()
    }

    private fun createChannel() {
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(R.string.tracking_channel_name),
            NotificationManager.IMPORTANCE_LOW,
        ).apply {
            description = "Shown only while a checked-in visitor is sharing location."
            setShowBadge(false)
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
    }

    private fun hasLocationPermission(): Boolean =
        ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

    private fun stopServiceNow() {
        locationCallback?.let { locationClient.removeLocationUpdates(it) }
        locationCallback = null
        repository.installationStore.clearActiveTracking()
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    companion object {
        const val ACTION_START = "ph.edu.isatu.visitor.START_TRACKING"
        const val ACTION_STOP = "ph.edu.isatu.visitor.STOP_TRACKING"
        const val EXTRA_APPOINTMENT_ID = "appointment_id"
        private const val CHANNEL_ID = "active_visit_tracking"
        /** The same channel as the visitor's other updates (VisitorMessagingService). */
        private const val ALERT_CHANNEL_ID = "appointment_updates"
        private const val NOTIFICATION_ID = 4101
        private const val NEAR_NOTIFICATION_ID = 4102
        private const val REMINDER_NOTIFICATION_ID = 4103
        /** The building counts as reached this close to its pin, from a reading at least this accurate. */
        private const val BUILDING_REACHED_METERS = 30.0
        private const val BUILDING_MAX_ACCURACY_METERS = 20.0
        private const val MIN_MOVEMENT_METERS = 10f
        private const val MAX_MOVEMENT_ACCURACY_METERS = 50f
        /** How often a reading is taken for the filter, at most the upload interval. */
        private const val SAMPLE_INTERVAL_MILLIS = 2_000L
        private const val UPLOAD_SLACK_MILLIS = 250L

        fun start(context: Context, appointmentId: Long) {
            val intent = Intent(context, VisitorTrackingService::class.java)
                .setAction(ACTION_START)
                .putExtra(EXTRA_APPOINTMENT_ID, appointmentId)
            ContextCompat.startForegroundService(context, intent)
        }

        fun stop(context: Context, appointmentId: Long) {
            context.startService(
                Intent(context, VisitorTrackingService::class.java)
                    .setAction(ACTION_STOP)
                    .putExtra(EXTRA_APPOINTMENT_ID, appointmentId),
            )
        }
    }
}
