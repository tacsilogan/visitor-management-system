package ph.edu.isatu.visitor.data

import android.location.Location
import kotlinx.coroutines.flow.StateFlow
import ph.edu.isatu.visitor.BuildConfig
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

class VisitorRepository(
    private val apiClient: ApiClient,
    private val tokenStore: SecureTokenStore,
    val installationStore: InstallationStore,
    private val locationQueue: LocationQueueDao,
    private val campusMapCache: CampusMapCache,
    private val directionPhotos: DirectionPhotoStore,
) {
    val signedIn: StateFlow<Boolean> = tokenStore.signedIn
    val savedName: String? get() = tokenStore.savedName

    /** Starts an account and emails a code to it. Nothing is created until [confirmSignup]. */
    suspend fun register(request: RegisterRequest): SignupCodeData =
        apiClient.requireData(apiClient.api.register(request))

    suspend fun resendCode(requestId: Long): SignupCodeData =
        apiClient.requireData(apiClient.api.resendCode(ResendCodeRequest(requestId)))

    suspend fun signupOptions(): SignupOptionsData = apiClient.requireData(apiClient.api.signupOptions())

    /** The code from the email: the account is created and signed in, with the notice agreed to at sign-up. */
    suspend fun confirmSignup(requestId: Long, code: String): UserDto {
        val result = apiClient.requireData(
            apiClient.api.confirmSignup(
                ConfirmSignupRequest(requestId, code, installationStore.installationId, installationStore.deviceName),
            ),
        )
        saveNoticeAgreement(result.user.id)
        tokenStore.saveToken(result.accessToken)
        tokenStore.savedName = result.user.fullName
        return result.user
    }

    /** Has this account agreed to the current notice on this phone? The server sees it with their next visit. */
    fun agreedToNotice(userId: Long): Boolean =
        installationStore.agreedNotice(userId) == BuildConfig.CONSENT_VERSION

    fun saveNoticeAgreement(userId: Long) = installationStore.saveAgreedNotice(userId, BuildConfig.CONSENT_VERSION)

    suspend fun login(identifier: String, password: String): UserDto {
        val result = apiClient.requireData(
            apiClient.api.login(
                LoginRequest(identifier, password, installationStore.installationId, installationStore.deviceName),
            ),
        )
        tokenStore.saveToken(result.accessToken)
        tokenStore.savedName = result.user.fullName
        return result.user
    }

    suspend fun logout() {
        try {
            runCatching { apiClient.requireData(apiClient.api.unregisterDevice(DeviceUnregisterRequest(installationStore.installationId))) }
            runCatching { apiClient.requireData(apiClient.api.logout()) }
        } finally {
            tokenStore.clear()
            installationStore.clearActiveTracking()
            campusMapCache.clear()
            directionPhotos.clear()
        }
    }

    suspend fun requestPasswordReset(email: String): PasswordResetData =
        apiClient.requireData(apiClient.api.requestPasswordReset(PasswordResetRequest(email)))

    suspend fun resetPassword(email: String, token: String, password: String) {
        apiClient.requireData(apiClient.api.resetPassword(ResetPasswordRequest(email, token, password)))
    }

    suspend fun profile(): UserDto =
        apiClient.requireData(apiClient.api.me()).user.also { tokenStore.savedName = it.fullName }

    suspend fun updateProfile(fullName: String, contactNumber: String): UserDto =
        apiClient.requireData(apiClient.api.updateProfile(ProfileUpdateRequest(fullName, contactNumber))).user
            .also { tokenStore.savedName = it.fullName }

    suspend fun offices(): List<OfficeDto> = apiClient.requireData(apiClient.api.offices()).offices

    suspend fun teachers(): List<TeacherDto> = apiClient.requireData(apiClient.api.teachers()).teachers

    suspend fun teacher(code: String): TeacherDto = apiClient.requireData(apiClient.api.teacher(code)).teacher

    suspend fun availability(officeCode: String, date: String): AvailabilityData =
        apiClient.requireData(apiClient.api.availability(officeCode, date))

    suspend fun appointments(): List<AppointmentDto> =
        apiClient.requireData(apiClient.api.appointments()).appointments

    suspend fun appointment(id: Long): AppointmentDto =
        apiClient.requireData(apiClient.api.appointment(id)).appointment

    suspend fun createAppointment(request: CreateAppointmentRequest): AppointmentDto =
        apiClient.requireData(apiClient.api.createAppointment(request)).appointment

    suspend fun cancelAppointment(id: Long, reason: String) {
        apiClient.requireData(apiClient.api.cancelAppointment(CancelAppointmentRequest(id, reason)))
    }

    suspend fun createVisit(visitType: String, stops: List<VisitStopRequest>): VisitDto =
        apiClient.requireData(
            apiClient.api.createVisit(
                CreateVisitRequest(
                    visitType = visitType,
                    stops = stops,
                    deviceName = installationStore.deviceName,
                    locationConsent = LocationConsentRequest(true, BuildConfig.CONSENT_VERSION),
                ),
            ),
        ).visit

    suspend fun visit(id: Long): VisitDto = apiClient.requireData(apiClient.api.visit(id)).visit

    suspend fun cancelVisit(id: Long, reason: String) {
        apiClient.requireData(apiClient.api.cancelVisit(CancelVisitRequest(id, reason)))
    }

    suspend fun respondToReschedule(proposalId: Long, action: String, slotId: Long?): String? =
        apiClient.api.respondToReschedule(RescheduleResponseRequest(proposalId, action, slotId))
            .also { apiClient.requireData(it) }
            .body()?.message

    suspend fun notifications(): NotificationsData = apiClient.requireData(apiClient.api.notifications())

    suspend fun markNotificationRead(id: Long? = null, all: Boolean = false) {
        apiClient.requireData(apiClient.api.markNotificationRead(NotificationReadRequest(id, all)))
    }

    suspend fun registerDevice(fcmToken: String) {
        apiClient.requireData(
            apiClient.api.registerDevice(
                DeviceRegistrationRequest(
                    installationId = installationStore.installationId,
                    fcmToken = fcmToken,
                    appVersion = BuildConfig.VERSION_NAME,
                    deviceModel = installationStore.deviceName,
                ),
            ),
        )
    }

    suspend fun tracking(appointmentId: Long): TrackingData =
        apiClient.requireData(apiClient.api.tracking(appointmentId))

    suspend fun startTracking(appointmentId: Long): TrackingData {
        val result = apiClient.requireData(
            apiClient.api.updateTracking(
                TrackingActionRequest(appointmentId, "start", UUID.randomUUID().toString()),
            ),
        )
        result.session?.let { installationStore.saveActiveTracking(appointmentId, it.id) }
        return result
    }

    suspend fun stopTracking(appointmentId: Long) {
        runCatching {
            apiClient.requireData(apiClient.api.updateTracking(TrackingActionRequest(appointmentId, "stop")))
        }
        installationStore.clearActiveTracking()
    }

    suspend fun withdrawConsent(appointmentId: Long) {
        apiClient.requireData(apiClient.api.updateConsent(ConsentActionRequest(appointmentId, "withdraw")))
        installationStore.clearActiveTracking()
    }

    /** Gives location consent again after the visitor stopped sharing during a visit. */
    suspend fun grantConsent(appointmentId: Long) {
        apiClient.requireData(
            apiClient.api.updateConsent(ConsentActionRequest(appointmentId, "grant", BuildConfig.CONSENT_VERSION)),
        )
    }

    /** The campus map for a visit; the last copy is kept for moments without a connection. */
    suspend fun campusMap(appointmentId: Long): CampusMapData {
        val map = apiClient.requireData(apiClient.api.campusMap(appointmentId))
        campusMapCache.save(map)
        return map
    }

    fun cachedCampusMap(appointmentId: Long): CampusMapData? = campusMapCache.load(appointmentId)

    /** Saves the photos of this visit's directions on the phone, for places with poor signal. */
    suspend fun saveDirectionPhotos(map: CampusMapData) {
        directionPhotos.keepOnly(map.stops.flatMap { it.directionSteps }.map { it.photo }.filter { it.isNotBlank() }.toSet())
    }

    /** Tells the server (and so Security and the office) that the visitor reached a stop. */
    suspend fun reportArrival(request: ArrivalRequest): ArrivalData =
        apiClient.requireData(apiClient.api.reportArrival(request))

    /** "I can't find it": tells the office which step the visitor stopped at. */
    suspend fun requestDirectionHelp(stopAppointmentId: Long, stepNumber: Int?): DirectionHelpData =
        apiClient.requireData(apiClient.api.requestDirectionHelp(DirectionHelpRequest(stopAppointmentId, stepNumber)))

    suspend fun queueLocation(appointmentId: Long, sessionId: Long, location: Location) {
        locationQueue.insert(
            QueuedLocation(
                appointmentId = appointmentId,
                trackingSessionId = sessionId,
                clientEventId = UUID.randomUUID().toString(),
                latitude = location.latitude,
                longitude = location.longitude,
                accuracy = location.accuracy.takeIf { location.hasAccuracy() }?.toDouble(),
                capturedAt = locationTimestamp(location.time.takeIf { it > 0 } ?: System.currentTimeMillis()),
            ),
        )
    }

    suspend fun flushLocations(sessionId: Long, maximumBatchPoints: Int = 100): Int {
        var uploaded = 0
        while (true) {
            val queued = locationQueue.oldest(sessionId, maximumBatchPoints.coerceIn(1, 100))
            if (queued.isEmpty()) return uploaded
            apiClient.requireData(
                apiClient.api.uploadLocations(
                    LocationBatchRequest(
                        trackingSessionId = sessionId,
                        points = queued.map {
                            LocationPointRequest(
                                clientEventId = it.clientEventId,
                                latitude = it.latitude,
                                longitude = it.longitude,
                                accuracy = it.accuracy,
                                capturedAt = it.capturedAt,
                            )
                        },
                    ),
                ),
            )
            locationQueue.delete(queued.map { it.id })
            uploaded += queued.size
            if (queued.size < maximumBatchPoints.coerceIn(1, 100)) return uploaded
        }
    }

    suspend fun queuedLocationCount(sessionId: Long): Int = locationQueue.count(sessionId)

    private fun locationTimestamp(milliseconds: Long): String =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("Asia/Manila")
        }.format(Date(milliseconds))
}
