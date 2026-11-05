package ph.edu.isatu.visitor.data

data class ApiMeta(
    val apiVersion: String = "",
    val requestId: String = "",
)

data class ApiEnvelope<T>(
    val success: Boolean = false,
    val data: T? = null,
    val message: String? = null,
    val errors: Map<String, Any?>? = null,
    val meta: ApiMeta? = null,
)

data class UserDto(
    val id: Long = 0,
    val email: String = "",
    val username: String = "",
    val fullName: String = "",
    val contactNumber: String = "",
    val emailVerified: Boolean = false,
    val role: String = "visitor",
)

data class LoginRequest(
    val identifier: String,
    val password: String,
    val installationId: String,
    val deviceName: String,
)

data class LoginData(
    val accessToken: String = "",
    val tokenType: String = "Bearer",
    val expiresAt: String = "",
    val user: UserDto = UserDto(),
)

data class RegisterRequest(
    val email: String,
    val fullName: String,
    val contactNumber: String,
    val password: String,
    val trackingConsent: Boolean,
    val consentVersion: String,
)

data class RegisterData(
    val user: UserDto = UserDto(),
    val emailVerificationRequired: Boolean = false,
)

data class PasswordResetRequest(val email: String)
data class PasswordResetData(val developmentResetToken: String? = null)
data class ResetPasswordRequest(val email: String, val token: String, val newPassword: String)
data class ProfileData(val user: UserDto = UserDto())
data class ProfileUpdateRequest(val fullName: String, val contactNumber: String)

data class OfficeRuleDto(
    val dayOfWeek: Int = 1,
    val startTime: String = "",
    val endTime: String = "",
    val capacity: Int? = null,
)

data class OfficeDto(
    val code: String = "",
    val name: String = "",
    /** Where the office is, e.g. "CCI Building, 2nd floor, Room 201". */
    val location: String = "",
    val acceptingVisitors: Boolean = false,
    val unavailableReason: String = "",
    val availableAgainAt: String? = null,
    val slotDurationMinutes: Int = 30,
    val maximumVisitorsPerSlot: Int = 1,
    val weeklyHours: List<OfficeRuleDto> = emptyList(),
)

data class OfficesData(
    val offices: List<OfficeDto> = emptyList(),
    val timezone: String = "Asia/Manila",
)

data class AvailabilitySlotDto(
    val scheduledStartAt: String = "",
    val scheduledEndAt: String = "",
    val remainingCapacity: Int = 0,
)

data class AvailabilityData(
    val officeCode: String = "",
    val officeName: String = "",
    val date: String = "",
    val timezone: String = "Asia/Manila",
    val slotDurationMinutes: Int = 30,
    val acceptingVisitors: Boolean = false,
    val message: String? = null,
    val slots: List<AvailabilitySlotDto> = emptyList(),
)

data class OfficeSummaryDto(val code: String = "", val name: String = "")

data class QrPassDto(
    val payload: String = "",
    val token: String = "",
    val validFrom: String = "",
    val validUntil: String = "",
    val currentlyValid: Boolean = false,
)

data class VisitorSummaryDto(
    val fullName: String = "",
    val email: String = "",
    val contactNumber: String = "",
)

data class RescheduleSlotDto(
    val id: Long = 0,
    val scheduledStartAt: String = "",
    val scheduledEndAt: String = "",
    val selected: Boolean = false,
)

data class RescheduleProposalDto(
    val id: Long = 0,
    val reason: String = "",
    val message: String = "",
    val status: String = "",
    val responseDeadline: String? = null,
    val respondedAt: String? = null,
    val createdAt: String = "",
    val slots: List<RescheduleSlotDto> = emptyList(),
)

/** The multi-stop visit an appointment belongs to; null for a single appointment. */
data class VisitSummaryDto(
    val id: Long = 0,
    val visitCode: String = "",
    val visitDate: String = "",
    val status: String = "open",
    val stopNumber: Int = 0,
    val stopCount: Int = 0,
)

data class AppointmentDto(
    val id: Long = 0,
    val registrationCode: String? = null,
    val visitType: String = "appointment",
    val office: OfficeSummaryDto = OfficeSummaryDto(),
    val purpose: String = "",
    val subject: String = "",
    val scheduledStartAt: String = "",
    val scheduledEndAt: String = "",
    val status: String = "pending_approval",
    val statusUpdatedAt: String? = null,
    val rejectionReason: String = "",
    val checkedInAt: String? = null,
    val completedAt: String? = null,
    /** How a finished visit ended: "scan", "guard", "left_campus", or "end_of_day". */
    val checkoutMethod: String? = null,
    val createdAt: String = "",
    val qrPass: QrPassDto? = null,
    val visit: VisitSummaryDto? = null,
    val visitor: VisitorSummaryDto? = null,
    val additionalDetails: String = "",
    val rescheduleProposal: RescheduleProposalDto? = null,
)

/**
 * One campus trip with two or three office stops on the same day. Each stop is approved
 * by its own office; the visit has a single QR pass and a single tracking session.
 */
data class VisitDto(
    val id: Long = 0,
    val visitCode: String = "",
    val visitDate: String = "",
    val status: String = "open",
    val checkedInAt: String? = null,
    val completedAt: String? = null,
    /** How a finished visit ended: "scan", "guard", "left_campus", or "end_of_day". */
    val checkoutMethod: String? = null,
    val trackingAppointmentId: Long? = null,
    val currentStopId: Long? = null,
    val qrPass: QrPassDto? = null,
    val stops: List<AppointmentDto> = emptyList(),
)

data class VisitData(val visit: VisitDto = VisitDto())

data class VisitStopRequest(
    val officeCode: String,
    val purpose: String,
    val subject: String,
    val additionalDetails: String,
    val scheduledStartAt: String,
    val scheduledEndAt: String,
)

data class CreateVisitRequest(
    /** "appointment" (scheduled stops) or "walk_in" (immediate pass, no times). */
    val visitType: String,
    val stops: List<VisitStopRequest>,
    val deviceName: String,
    val locationConsent: LocationConsentRequest,
)

data class CancelVisitRequest(val visitId: Long, val reason: String)
data class VisitActionData(val visitId: Long = 0, val status: String = "", val cancelledStops: Int = 0)

data class AppointmentsData(
    val appointments: List<AppointmentDto> = emptyList(),
    val pagination: PaginationDto = PaginationDto(),
)

data class AppointmentData(val appointment: AppointmentDto = AppointmentDto())
data class PaginationDto(val hasMore: Boolean = false, val nextBeforeId: Long? = null)

data class LocationConsentRequest(val granted: Boolean, val version: String)

data class CreateAppointmentRequest(
    val visitType: String,
    val officeCode: String,
    val purpose: String,
    val subject: String,
    val additionalDetails: String,
    val scheduledStartAt: String,
    val scheduledEndAt: String,
    val deviceName: String,
    val locationConsent: LocationConsentRequest,
)

data class CancelAppointmentRequest(val appointmentId: Long, val reason: String)
data class AppointmentActionData(val appointmentId: Long = 0, val status: String = "")
data class RescheduleResponseRequest(
    val proposalId: Long,
    val action: String,
    val selectedSlotId: Long? = null,
)

data class NotificationDto(
    val id: Long = 0,
    val appointmentId: Long? = null,
    val type: String = "",
    val title: String = "",
    val message: String = "",
    val data: Map<String, Any?>? = null,
    val readAt: String? = null,
    val createdAt: String = "",
)

data class NotificationsData(
    val notifications: List<NotificationDto> = emptyList(),
    val unreadCount: Int = 0,
    val pagination: PaginationDto = PaginationDto(),
)

data class NotificationReadRequest(val notificationId: Long? = null, val markAll: Boolean = false)
data class UpdatedData(val updated: Int = 0)

data class DeviceRegistrationRequest(
    val installationId: String,
    val fcmToken: String,
    val appVersion: String,
    val deviceModel: String,
)

data class DeviceUnregisterRequest(val installationId: String)
data class DeviceData(val deviceId: Long = 0, val queuedNotifications: Int = 0)

data class ConsentDto(
    val id: Long = 0,
    val version: String = "",
    val consentedAt: String? = null,
    val withdrawnAt: String? = null,
    val active: Boolean = false,
)

data class ConsentData(
    val consent: ConsentDto? = null,
    val consentId: Long? = null,
    val active: Boolean? = null,
    val withdrawnRecords: Int? = null,
)

data class ConsentActionRequest(
    val appointmentId: Long,
    val action: String,
    val consentVersion: String? = null,
)

data class TrackingPolicyDto(
    val uploadIntervalSeconds: Int = 15,
    val offlineUploadGraceHours: Int = 24,
    val maximumBatchPoints: Int = 100,
    val retentionDays: Int = 90,
)

data class TrackingSessionDto(
    val id: Long = 0,
    val clientSessionId: String = "",
    val installationId: String = "",
    val startedAt: String = "",
    val lastUploadAt: String? = null,
    val endedAt: String? = null,
    val endedReason: String? = null,
    val active: Boolean = false,
)

data class TrackingData(
    val appointmentId: Long = 0,
    val appointmentStatus: String = "",
    val trackingAllowed: Boolean = false,
    val session: TrackingSessionDto? = null,
    val policy: TrackingPolicyDto = TrackingPolicyDto(),
    val serverTime: String? = null,
)

data class TrackingActionRequest(
    val appointmentId: Long,
    val action: String,
    val clientSessionId: String? = null,
)

data class LocationPointRequest(
    val clientEventId: String,
    val latitude: Double,
    val longitude: Double,
    val accuracy: Double?,
    val capturedAt: String,
)

data class LocationBatchRequest(
    val trackingSessionId: Long,
    val points: List<LocationPointRequest>,
)

data class LocationBatchData(
    val trackingSessionId: Long = 0,
    val accepted: Int = 0,
    val duplicates: Int = 0,
    val serverTime: String = "",
)

data class CampusPointDto(val latitude: Double = 0.0, val longitude: Double = 0.0)

data class CampusGateDto(val name: String = "", val latitude: Double = 0.0, val longitude: Double = 0.0)

/** One step from the building door to the office door (Campus Map > Inside). */
data class DirectionStepDto(
    val text: String = "",
    /** Relative to the site, like uploads/directions/abc.jpg; empty when the step has no photo. */
    val photo: String = "",
)

/** One office stop on the campus map. Coordinates are null until an admin places the pin. */
data class CampusStopDto(
    val appointmentId: Long = 0,
    val officeCode: String = "",
    val label: String = "",
    /** Where the office is, from the Department Directory, e.g. "CCI building, room 201". */
    val location: String = "",
    val description: String = "",
    /** Where the office is inside its building, and how to get there from the door. */
    val building: String = "",
    val floor: String = "",
    val room: String = "",
    val visitorMessage: String = "",
    val directionSteps: List<DirectionStepDto> = emptyList(),
    val latitude: Double? = null,
    val longitude: Double? = null,
    val status: String = "",
    val visitType: String = "appointment",
    val scheduledStartAt: String? = null,
    val scheduledEndAt: String? = null,
    /** Set once the visitor's arrival at this office was confirmed (arrival.php). */
    val arrivedAt: String? = null,
    /** "gps" or "visitor". */
    val arrivalMethod: String? = null,
)

/**
 * A walking route an administrator recorded in Campus Map → Routes. [points] are
 * [latitude, longitude] pairs from the route's start (usually a gate) to the office.
 */
data class WalkingRouteDto(
    val id: Long = 0,
    val officeCode: String = "",
    val name: String = "",
    val startLabel: String = "",
    val distanceMeters: Double = 0.0,
    val points: List<List<Double>> = emptyList(),
)

/** The server's rules for confirming that a visitor left the campus. */
data class ExitPolicyDto(
    val minimumAccuracyMeters: Double = 50.0,
    val outsideConfirmationPoints: Int = 3,
    val outsideConfirmationSeconds: Int = 300,
    val boundaryBufferMeters: Double = 25.0,
)

/**
 * GET campus_map.php: the boundary, gates, and where the visitor is going. [destination]
 * is null once every office stop is done, when the visitor should head back to a gate.
 */
data class CampusMapData(
    val appointmentId: Long = 0,
    val campusConfigured: Boolean = false,
    val campusUpdatedAt: String? = null,
    val campusBoundary: List<CampusPointDto> = emptyList(),
    val gates: List<CampusGateDto> = emptyList(),
    val destination: CampusStopDto? = null,
    val stops: List<CampusStopDto> = emptyList(),
    /** Every recorded walking route; the app guides along them (WalkwayPlanner). */
    val walkingRoutes: List<WalkingRouteDto> = emptyList(),
    val exitPolicy: ExitPolicyDto = ExitPolicyDto(),
    /** GPS must place the visitor this close to the office pin to count as arrived. */
    val arrivalDistanceMeters: Double = 3.0,
    /** ...from a reading at least this accurate. */
    val arrivalMaxAccuracyMeters: Double = 8.0,
    val serverTime: String? = null,
)

/** POST arrival.php: the visitor reached an office stop. */
data class ArrivalRequest(
    val appointmentId: Long,
    /** "gps" or "visitor". */
    val method: String,
    val distanceMeters: Double?,
    val accuracyMeters: Double?,
)

data class ArrivalData(
    val appointmentId: Long = 0,
    val arrivedAt: String? = null,
    val arrivalMethod: String? = null,
    val alreadyRecorded: Boolean = false,
)

/** POST direction_help.php: "I can't find it". [stepNumber] counts from 1. */
data class DirectionHelpRequest(val appointmentId: Long, val stepNumber: Int?)

data class DirectionHelpData(
    val appointmentId: Long = 0,
    /** False when the office has no active staff account to tell. */
    val officeTold: Boolean = false,
    val alreadyTold: Boolean = false,
)
