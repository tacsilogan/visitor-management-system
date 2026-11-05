package ph.edu.isatu.visitor.data

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Query

interface VisitorApi {
    @POST("auth/register.php")
    suspend fun register(@Body request: RegisterRequest): Response<ApiEnvelope<RegisterData>>

    @POST("auth/login.php")
    suspend fun login(@Body request: LoginRequest): Response<ApiEnvelope<LoginData>>

    @POST("auth/logout.php")
    suspend fun logout(): Response<ApiEnvelope<Any>>

    @POST("auth/request_password_reset.php")
    suspend fun requestPasswordReset(@Body request: PasswordResetRequest): Response<ApiEnvelope<PasswordResetData>>

    @POST("auth/reset_password.php")
    suspend fun resetPassword(@Body request: ResetPasswordRequest): Response<ApiEnvelope<Any>>

    @GET("me.php")
    suspend fun me(): Response<ApiEnvelope<ProfileData>>

    @PATCH("me.php")
    suspend fun updateProfile(@Body request: ProfileUpdateRequest): Response<ApiEnvelope<ProfileData>>

    @GET("offices.php")
    suspend fun offices(): Response<ApiEnvelope<OfficesData>>

    @GET("availability.php")
    suspend fun availability(
        @Query("office_code") officeCode: String,
        @Query("date") date: String,
    ): Response<ApiEnvelope<AvailabilityData>>

    @GET("appointments.php")
    suspend fun appointments(
        @Query("status") status: String? = null,
        @Query("before_id") beforeId: Long? = null,
        @Query("limit") limit: Int = 50,
    ): Response<ApiEnvelope<AppointmentsData>>

    @POST("appointments.php")
    suspend fun createAppointment(@Body request: CreateAppointmentRequest): Response<ApiEnvelope<AppointmentData>>

    @GET("appointment.php")
    suspend fun appointment(@Query("id") id: Long): Response<ApiEnvelope<AppointmentData>>

    @POST("appointment_cancel.php")
    suspend fun cancelAppointment(@Body request: CancelAppointmentRequest): Response<ApiEnvelope<AppointmentActionData>>

    @POST("visits.php")
    suspend fun createVisit(@Body request: CreateVisitRequest): Response<ApiEnvelope<VisitData>>

    @GET("visit.php")
    suspend fun visit(@Query("id") id: Long): Response<ApiEnvelope<VisitData>>

    @POST("visit_cancel.php")
    suspend fun cancelVisit(@Body request: CancelVisitRequest): Response<ApiEnvelope<VisitActionData>>

    @POST("reschedule_response.php")
    suspend fun respondToReschedule(@Body request: RescheduleResponseRequest): Response<ApiEnvelope<AppointmentActionData>>

    @GET("notifications.php")
    suspend fun notifications(
        @Query("unread_only") unreadOnly: Boolean = false,
        @Query("before_id") beforeId: Long? = null,
        @Query("limit") limit: Int = 50,
    ): Response<ApiEnvelope<NotificationsData>>

    @POST("notification_read.php")
    suspend fun markNotificationRead(@Body request: NotificationReadRequest): Response<ApiEnvelope<UpdatedData>>

    @POST("devices.php")
    suspend fun registerDevice(@Body request: DeviceRegistrationRequest): Response<ApiEnvelope<DeviceData>>

    @HTTP(method = "DELETE", path = "devices.php", hasBody = true)
    suspend fun unregisterDevice(@Body request: DeviceUnregisterRequest): Response<ApiEnvelope<Any>>

    @GET("consent.php")
    suspend fun consent(@Query("appointment_id") appointmentId: Long): Response<ApiEnvelope<ConsentData>>

    @POST("consent.php")
    suspend fun updateConsent(@Body request: ConsentActionRequest): Response<ApiEnvelope<ConsentData>>

    @GET("tracking.php")
    suspend fun tracking(@Query("appointment_id") appointmentId: Long): Response<ApiEnvelope<TrackingData>>

    @POST("tracking.php")
    suspend fun updateTracking(@Body request: TrackingActionRequest): Response<ApiEnvelope<TrackingData>>

    @POST("locations.php")
    suspend fun uploadLocations(@Body request: LocationBatchRequest): Response<ApiEnvelope<LocationBatchData>>

    @GET("campus_map.php")
    suspend fun campusMap(@Query("appointment_id") appointmentId: Long): Response<ApiEnvelope<CampusMapData>>

    @POST("arrival.php")
    suspend fun reportArrival(@Body request: ArrivalRequest): Response<ApiEnvelope<ArrivalData>>

    @POST("direction_help.php")
    suspend fun requestDirectionHelp(@Body request: DirectionHelpRequest): Response<ApiEnvelope<DirectionHelpData>>
}
