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
    /** "visitor" (from outside ISATU) or "student" (an ISATU student, who books teachers only). */
    val role: String = "visitor",
    /** A student's details, as their teachers see them. Null for a visitor. */
    val student: StudentProfileDto? = null,
) {
    /** A student has no pass, no gate scan and no tracking: they say "I'm here" instead. */
    val isStudent: Boolean get() = role == "student"
}

data class StudentProfileDto(
    val studentNumber: String = "",
    val courseCode: String = "",
    /** "IT", or what the student typed when their course wasn't listed. */
    val course: String = "",
    val department: String = "",
    val yearLevel: Int = 0,
    val section: String = "",
    /** "IT 3-B": how the student's class reads on a timetable. */
    val label: String = "",
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

/**
 * Starts an account. Nothing exists until the code emailed to [email] is entered
 * ([ConfirmSignupRequest]). A student must use their @students.isatu.edu.ph email.
 */
data class RegisterRequest(
    val email: String,
    val fullName: String,
    val contactNumber: String,
    val password: String,
    /** Visitors agree to be tracked on campus; students are never tracked. */
    val trackingConsent: Boolean,
    val consentVersion: String,
    /** "visitor" or "student". */
    val accountType: String = "visitor",
    val studentNumber: String? = null,
    /** A course from [SignupOptionsData], or null with [courseName] when it isn't listed. */
    val courseCode: String? = null,
    val courseName: String? = null,
    val yearLevel: Int? = null,
    val section: String? = null,
)

/** The code is on its way. [developmentCode] is only sent by a server in development mode. */
data class SignupCodeData(
    val verificationRequired: Boolean = true,
    val requestId: Long = 0,
    val email: String = "",
    val expiresAt: String = "",
    val resendAfterSeconds: Int = 60,
    val developmentCode: String? = null,
)

data class ConfirmSignupRequest(
    val requestId: Long,
    val code: String,
    val installationId: String,
    val deviceName: String,
)

data class ResendCodeRequest(val requestId: Long)

/** What the student sign-up form offers. */
data class SignupOptionsData(
    val studentEmailDomain: String = "students.isatu.edu.ph",
    val yearLevels: List<Int> = listOf(1, 2, 3, 4, 5),
    val departments: List<SignupDepartmentDto> = emptyList(),
)

data class SignupDepartmentDto(
    val code: String = "",
    val name: String = "",
    val courses: List<SignupCourseDto> = emptyList(),
)

data class SignupCourseDto(val code: String = "", val name: String = "")

data class PasswordResetRequest(val email: String)
/** "Forgot password?" sent its 6-digit code. The reply is the same whether or not the email has an account. */
data class PasswordResetData(
    val codeMinutes: Int = 15,
    val resendAfterSeconds: Int = 60,
    /** Only from a server on a developer's computer, so it can be tried without email. */
    val developmentResetToken: String? = null,
)
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

/**
 * A teacher a visitor can find. It says whether they can be seen now, or their next free time, and
 * never why. Their room only comes with an accepted pass.
 */
data class TeacherDto(
    val code: String = "",
    val name: String = "",
    val shortName: String = "",
    val course: String = "",
    val department: String = "",
    val departmentName: String = "",
    val availableNow: Boolean = false,
    val acceptsWalkIns: Boolean = true,
    val canWalkIn: Boolean = false,
    val nextFreeAt: String? = null,
    val slotDurationMinutes: Int = 30,
    val nextFreeSlots: List<AvailabilitySlotDto> = emptyList(),
    val fallbackOffice: OfficeSummaryDto? = null,
)

data class TeachersData(val teachers: List<TeacherDto> = emptyList(), val courses: List<String> = emptyList())
data class TeacherData(val teacher: TeacherDto = TeacherDto())

/** Who a visit is with, when it's a teacher and not an office. */
data class VisitTeacherDto(
    val code: String = "",
    val name: String = "",
    val shortName: String = "",
    val course: String = "",
    val department: String = "",
)

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
    /** "student" for an ISATU student's visit: no pass and no gate, "I'm here" instead. */
    val visitorKind: String = "visitor",
    /** When the visitor or student said they arrived. */
    val arrivedAt: String? = null,
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
    /** Set when this visit is with a teacher. */
    val teacher: VisitTeacherDto? = null,
    /** A walk-in request to a teacher closes at this time with no answer. */
    val answerByAt: String? = null,
    /** The teacher finished the meeting; the visitor is on their way out. */
    val meetingDoneAt: String? = null,
    /** Where the visitor can go when the teacher can't see them. */
    val fallbackOffice: OfficeSummaryDto? = null,
) {
    val isStudentVisit: Boolean get() = visitorKind == "student"
}

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
