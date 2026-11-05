package ph.edu.isatu.visitor.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.async
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import ph.edu.isatu.visitor.BuildConfig
import ph.edu.isatu.visitor.VisitorApplication
import ph.edu.isatu.visitor.data.ApiException
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.ArrivalRequest
import ph.edu.isatu.visitor.data.AvailabilityData
import ph.edu.isatu.visitor.data.CampusMapData
import ph.edu.isatu.visitor.data.CreateAppointmentRequest
import ph.edu.isatu.visitor.data.LocationConsentRequest
import ph.edu.isatu.visitor.data.NotificationDto
import ph.edu.isatu.visitor.data.OfficeDto
import ph.edu.isatu.visitor.data.TrackingData
import ph.edu.isatu.visitor.data.UserDto
import ph.edu.isatu.visitor.data.VisitDto
import ph.edu.isatu.visitor.data.VisitStopRequest
import ph.edu.isatu.visitor.data.VisitorRepository

/** Details carried into the booking form when the visitor rebooks a declined proposal. */
data class BookingPrefill(
    val officeCode: String,
    val purpose: String,
    val subject: String,
    val details: String,
)

data class VisitorUiState(
    val signedIn: Boolean = false,
    val initializing: Boolean = true,
    val busy: Boolean = false,
    val user: UserDto? = null,
    val offices: List<OfficeDto> = emptyList(),
    val appointments: List<AppointmentDto> = emptyList(),
    val notifications: List<NotificationDto> = emptyList(),
    val unreadCount: Int = 0,
    val selectedAppointment: AppointmentDto? = null,
    val selectedVisit: VisitDto? = null,
    val availability: AvailabilityData? = null,
    /** Availability for multi-stop booking, keyed by [stopAvailabilityKey]. */
    val stopAvailability: Map<String, AvailabilityData> = emptyMap(),
    val bookingPrefill: BookingPrefill? = null,
    val tracking: TrackingData? = null,
    /** The campus map for the visit on screen (boundary, gates, destination). */
    val campusMap: CampusMapData? = null,
    val error: String? = null,
    val message: String? = null,
)

fun stopAvailabilityKey(officeCode: String, date: String): String = "$officeCode|$date"

class AppViewModel(application: Application) : AndroidViewModel(application) {
    private val repository: VisitorRepository = (application as VisitorApplication).repository
    private val _state = MutableStateFlow(VisitorUiState())
    val state: StateFlow<VisitorUiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            repository.signedIn.collectLatest { signedIn ->
                if (signedIn) {
                    _state.update { it.copy(signedIn = true, initializing = true) }
                    refreshAll(initial = true)
                    syncFirebaseToken()
                } else {
                    _state.value = VisitorUiState(signedIn = false, initializing = false)
                }
            }
        }
    }

    fun login(email: String, password: String) = launchTask {
        repository.login(email.trim(), password)
    }

    fun register(email: String, name: String, contact: String, password: String) = launchTask {
        repository.register(email.trim(), name.trim(), contact.trim(), password)
        repository.login(email.trim(), password)
    }

    fun requestPasswordReset(email: String) = launchTask {
        val result = repository.requestPasswordReset(email.trim())
        val localToken = result.developmentResetToken
        _state.update {
            it.copy(
                message = localToken?.let { token -> "Local test reset token: $token" }
                    ?: "If the account exists, reset instructions were sent.",
            )
        }
    }

    fun resetPassword(email: String, token: String, password: String) = launchTask {
        repository.resetPassword(email.trim(), token.trim(), password)
        _state.update { it.copy(message = "Password updated. Sign in with your new password.") }
    }

    fun refreshAll(initial: Boolean = false) {
        viewModelScope.launch {
            if (!initial) _state.update { it.copy(busy = true, error = null) }
            try {
                val profile = async { repository.profile() }
                val offices = async { repository.offices() }
                val appointments = async { repository.appointments() }
                val notifications = async { repository.notifications() }
                val profileData = profile.await()
                val officeData = offices.await()
                val appointmentData = appointments.await()
                val notificationData = notifications.await()
                _state.update {
                    it.copy(
                        signedIn = true,
                        initializing = false,
                        busy = false,
                        user = profileData,
                        offices = officeData,
                        appointments = appointmentData,
                        notifications = notificationData.notifications,
                        unreadCount = notificationData.unreadCount,
                        error = null,
                    )
                }
            } catch (error: Exception) {
                if (error is ApiException && error.statusCode == 401) return@launch
                _state.update {
                    it.copy(initializing = false, busy = false, error = readableError(error))
                }
            }
        }
    }

    fun loadAvailability(officeCode: String, date: String) = launchTask {
        val result = repository.availability(officeCode, date)
        _state.update { it.copy(availability = result) }
    }

    fun clearAvailability() = _state.update { it.copy(availability = null) }

    fun createAppointment(
        visitType: String,
        officeCode: String,
        purpose: String,
        subject: String,
        details: String,
        start: String,
        end: String,
    ) = launchTask {
        val appointment = repository.createAppointment(
            CreateAppointmentRequest(
                visitType = visitType,
                officeCode = officeCode,
                purpose = purpose.trim(),
                subject = subject.trim(),
                additionalDetails = details.trim(),
                scheduledStartAt = start,
                scheduledEndAt = end,
                deviceName = repository.installationStore.deviceName,
                locationConsent = LocationConsentRequest(true, BuildConfig.CONSENT_VERSION),
            ),
        )
        _state.update {
            it.copy(
                selectedAppointment = appointment,
                availability = null,
                message = if (visitType == "walk_in") {
                    "Walk-in pass ready. Present it to Security with a valid ID."
                } else {
                    "Appointment request submitted for office review."
                },
            )
        }
        refreshAppointments()
    }

    fun openAppointment(id: Long) = launchTask {
        val appointment = repository.appointment(id)
        val visit = appointment.visit
        // A checked-in stop is part of an active campus visit; show the visit's map.
        if (visit != null && appointment.status == "checked_in") {
            loadVisit(visit.id)
            return@launchTask
        }
        val tracking = if (appointment.status == "checked_in") {
            runCatching { repository.tracking(id) }.getOrNull()
        } else null
        _state.update { it.copy(selectedAppointment = appointment, tracking = tracking) }
    }

    fun closeAppointment() = _state.update {
        val inVisit = it.selectedVisit != null
        it.copy(
            selectedAppointment = null,
            tracking = if (inVisit) it.tracking else null,
            campusMap = if (inVisit) it.campusMap else null,
        )
    }

    fun openVisit(id: Long) = launchTask { loadVisit(id) }

    fun closeVisit() = _state.update {
        it.copy(selectedVisit = null, selectedAppointment = null, tracking = null, campusMap = null)
    }

    /**
     * Loads the campus map without a spinner. Without a connection, the last copy saved
     * on the phone for this visit is used, so the map keeps its boundary and pins.
     */
    fun loadCampusMap(appointmentId: Long) {
        viewModelScope.launch {
            val map = runCatching { repository.campusMap(appointmentId) }.getOrNull()
                ?: repository.cachedCampusMap(appointmentId)
                ?: return@launch
            _state.update { it.copy(campusMap = map) }
            // Directions must work where the signal doesn't, so their photos are saved now.
            runCatching { repository.saveDirectionPhotos(map) }
        }
    }

    /** "I can't find it": tells the office which step the visitor stopped at. */
    suspend fun askOfficeForHelp(stopAppointmentId: Long, stepNumber: Int?): HelpResult = try {
        if (repository.requestDirectionHelp(stopAppointmentId, stepNumber).officeTold) HelpResult.TOLD else HelpResult.NO_STAFF
    } catch (cancelled: CancellationException) {
        throw cancelled
    } catch (_: Exception) {
        HelpResult.FAILED
    }

    private val arrivalReports = mutableMapOf<Long, Job>()

    /**
     * Reports an arrival at office stop [stopAppointmentId]. Without a connection it tries
     * again every 30 seconds (for about 10 minutes); a refusal from the server is final.
     * The campus map is then reloaded so the arrival shows as recorded.
     */
    fun reportArrival(campusMapAppointmentId: Long, stopAppointmentId: Long, method: String, distanceMeters: Double?, accuracyMeters: Double?) {
        if (arrivalReports[stopAppointmentId]?.isActive == true) return
        arrivalReports[stopAppointmentId] = viewModelScope.launch {
            val request = ArrivalRequest(stopAppointmentId, method, distanceMeters, accuracyMeters)
            repeat(ARRIVAL_REPORT_ATTEMPTS) {
                try {
                    repository.reportArrival(request)
                    loadCampusMap(campusMapAppointmentId)
                    return@launch
                } catch (error: ApiException) {
                    if (error.statusCode in 400..499) return@launch
                } catch (cancelled: CancellationException) {
                    throw cancelled
                } catch (_: Exception) {
                    // No connection: keep the arrival and try again.
                }
                delay(ARRIVAL_RETRY_MILLIS)
            }
        }
    }

    /** Gives location consent again; [onConsentGranted] then restarts sharing on the phone. */
    fun resumeSharing(appointmentId: Long, onConsentGranted: () -> Unit) = launchTask(showBusy = false) {
        repository.grantConsent(appointmentId)
        onConsentGranted()
        _state.update { it.copy(message = "Location sharing is on again.") }
    }

    fun pollSelectedVisit(id: Long) {
        viewModelScope.launch {
            if (_state.value.selectedVisit?.id != id) return@launch
            val visit = runCatching { repository.visit(id) }.getOrNull() ?: return@launch
            val tracking = visitTracking(visit)
            if (_state.value.selectedVisit?.id != id) return@launch
            _state.update { it.copy(selectedVisit = visit, tracking = tracking) }
        }
    }

    private suspend fun loadVisit(id: Long) {
        val visit = repository.visit(id)
        val tracking = visitTracking(visit)
        _state.update { it.copy(selectedVisit = visit, selectedAppointment = null, tracking = tracking) }
    }

    private suspend fun visitTracking(visit: VisitDto): TrackingData? {
        val trackingId = visit.trackingAppointmentId
        return if (visit.status == "checked_in" && trackingId != null) {
            runCatching { repository.tracking(trackingId) }.getOrNull()
        } else null
    }

    fun loadStopAvailability(officeCode: String, date: String) = launchTask(showBusy = false) {
        val result = repository.availability(officeCode, date)
        _state.update { it.copy(stopAvailability = it.stopAvailability + (stopAvailabilityKey(officeCode, date) to result)) }
    }

    fun createVisit(visitType: String, stops: List<VisitStopRequest>) = launchTask {
        val visit = repository.createVisit(visitType, stops)
        _state.update {
            it.copy(
                selectedVisit = visit,
                stopAvailability = emptyMap(),
                message = if (visitType == "walk_in") {
                    "Walk-in pass ready for every office. Present it to Security with a valid ID."
                } else {
                    "Visit submitted. Each office reviews its own stop; your pass appears after the first approval."
                },
            )
        }
        refreshAppointments()
    }

    fun cancelVisit(id: Long, reason: String) = launchTask {
        repository.cancelVisit(id, reason.trim())
        _state.update { it.copy(message = "Visit cancelled.", selectedVisit = null, selectedAppointment = null) }
        refreshAppointments()
    }

    fun refreshSelectedAppointment() {
        _state.value.selectedAppointment?.id?.let(::openAppointment)
    }

    fun pollSelectedAppointment(id: Long) {
        viewModelScope.launch {
            if (_state.value.selectedAppointment?.id != id) return@launch
            val appointment = runCatching { repository.appointment(id) }.getOrNull() ?: return@launch
            if (_state.value.selectedAppointment?.id != id) return@launch
            val tracking = if (appointment.status == "checked_in") {
                runCatching { repository.tracking(id) }.getOrNull()
            } else null
            _state.update { it.copy(selectedAppointment = appointment, tracking = tracking) }
        }
    }

    fun refreshTrackingSilently(id: Long) {
        viewModelScope.launch {
            val tracking = runCatching { repository.tracking(id) }.getOrNull() ?: return@launch
            if (_state.value.selectedAppointment?.id == id) {
                _state.update { it.copy(tracking = tracking) }
            }
        }
    }

    fun cancelAppointment(id: Long, reason: String) = launchTask {
        repository.cancelAppointment(id, reason.trim())
        _state.update { it.copy(message = "Appointment cancelled.", selectedAppointment = null) }
        refreshAppointments()
    }

    fun acceptProposedTime(proposalId: Long, slotId: Long) = launchTask {
        val message = repository.respondToReschedule(proposalId, "accept", slotId)
        _state.update {
            it.copy(message = message ?: "Schedule accepted. Your visitor pass is ready.", selectedAppointment = null)
        }
        refreshAppointments()
        _state.value.selectedVisit?.id?.let(::pollSelectedVisit)
    }

    /**
     * Declines every proposed time and opens the booking form with this request's office,
     * purpose, and subject already filled in, so the visitor only picks a new time.
     */
    fun declineAndRebook(proposalId: Long, appointment: AppointmentDto) = launchTask {
        repository.respondToReschedule(proposalId, "decline", null)
        _state.update {
            it.copy(
                message = "Proposed times declined. Pick a new time below.",
                selectedAppointment = null,
                selectedVisit = null,
                tracking = null,
                bookingPrefill = BookingPrefill(
                    officeCode = appointment.office.code,
                    purpose = appointment.purpose,
                    subject = appointment.subject,
                    details = appointment.additionalDetails,
                ),
            )
        }
        refreshAppointments()
    }

    fun consumeBookingPrefill() = _state.update { it.copy(bookingPrefill = null) }

    fun refreshTracking(id: Long) = launchTask {
        _state.update { it.copy(tracking = repository.tracking(id)) }
    }

    fun withdrawConsent(id: Long) = launchTask {
        repository.withdrawConsent(id)
        _state.update { it.copy(message = "Location consent withdrawn and sharing stopped.") }
        val visitId = _state.value.selectedVisit?.id
        if (visitId != null) loadVisit(visitId) else openAppointment(id)
    }

    /**
     * Quietly reloads notifications. When a new one has arrived, the visit list is reloaded
     * too, because a notification means an office or Security changed a visit.
     */
    fun refreshNotifications() {
        viewModelScope.launch {
            if (!_state.value.signedIn) return@launch
            val data = runCatching { repository.notifications() }.getOrNull() ?: return@launch
            val previousNewest = _state.value.notifications.maxOfOrNull { it.id } ?: 0L
            _state.update { it.copy(notifications = data.notifications, unreadCount = data.unreadCount) }
            if ((data.notifications.maxOfOrNull { it.id } ?: 0L) > previousNewest) refreshAppointments()
        }
    }

    fun markNotificationRead(id: Long) = launchTask(showBusy = false) {
        repository.markNotificationRead(id)
        _state.update { current ->
            current.copy(
                notifications = current.notifications.map {
                    if (it.id == id) it.copy(readAt = "read") else it
                },
                unreadCount = (current.unreadCount - 1).coerceAtLeast(0),
            )
        }
    }

    fun markAllNotificationsRead() = launchTask(showBusy = false) {
        repository.markNotificationRead(all = true)
        _state.update { current ->
            current.copy(
                notifications = current.notifications.map { it.copy(readAt = it.readAt ?: "read") },
                unreadCount = 0,
            )
        }
    }

    fun updateProfile(name: String, contact: String) = launchTask {
        val user = repository.updateProfile(name.trim(), contact.trim())
        _state.update { it.copy(user = user, message = "Profile updated.") }
    }

    fun logout() = launchTask {
        repository.logout()
    }

    fun clearBanner() = _state.update { it.copy(error = null, message = null) }

    private fun refreshAppointments() {
        viewModelScope.launch {
            runCatching { repository.appointments() }.onSuccess { appointments ->
                _state.update { it.copy(appointments = appointments) }
            }
        }
    }

    private fun syncFirebaseToken() {
        val application = getApplication<Application>()
        if (FirebaseApp.getApps(application).isEmpty()) return
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            viewModelScope.launch { runCatching { repository.registerDevice(token) } }
        }
    }

    private fun launchTask(showBusy: Boolean = true, block: suspend () -> Unit) {
        viewModelScope.launch {
            if (showBusy) _state.update { it.copy(busy = true, error = null, message = null) }
            try {
                block()
                if (showBusy) _state.update { it.copy(busy = false) }
            } catch (error: Exception) {
                _state.update { it.copy(busy = false, error = readableError(error)) }
            }
        }
    }

    private fun readableError(error: Exception): String {
        val apiError = error as? ApiException
        return apiError?.fieldErrors?.values?.firstOrNull { it.isNotBlank() }
            ?: apiError?.message
            ?: error.message
            ?: "Something went wrong. Check your connection and try again."
    }

    private companion object {
        const val ARRIVAL_REPORT_ATTEMPTS = 20
        const val ARRIVAL_RETRY_MILLIS = 30_000L
    }

    class Factory(private val application: Application) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T = AppViewModel(application) as T
    }
}
