package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import kotlinx.coroutines.delay
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.CampusMapData
import ph.edu.isatu.visitor.data.QrPassDto
import ph.edu.isatu.visitor.data.TeacherDto
import ph.edu.isatu.visitor.data.TrackingData
import ph.edu.isatu.visitor.navigation.OfficeDirections
import ph.edu.isatu.visitor.navigation.toDirections
import ph.edu.isatu.visitor.service.VisitorTrackingService

@Composable
fun AppointmentDetailScreen(
    appointment: AppointmentDto,
    tracking: TrackingData?,
    busy: Boolean,
    viewModel: AppViewModel,
    campusMap: CampusMapData? = null,
    notice: String? = null,
    teacherChoices: TeacherDto? = null,
) {
    BackHandler(onBack = viewModel::closeAppointment)
    val context = LocalContext.current
    var showCancel by remember { mutableStateOf(false) }
    var showDeclineProposal by remember { mutableStateOf(false) }
    val pendingProposal = appointment.rescheduleProposal?.takeIf { it.status == "pending" }
    // With a single suggested time there is nothing to choose: accept is one tap.
    var selectedSlotId by remember(pendingProposal?.id) {
        mutableLongStateOf(pendingProposal?.slots?.singleOrNull()?.id ?: 0L)
    }
    var locationPermissionMissing by remember(appointment.id) { mutableStateOf(false) }
    var resumingShare by remember(appointment.id) { mutableStateOf(false) }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { permissions ->
        if (permissions[Manifest.permission.ACCESS_FINE_LOCATION] == true) {
            VisitorTrackingService.start(context, appointment.id)
            locationPermissionMissing = false
        } else {
            locationPermissionMissing = true
        }
    }
    val requestTrackingPermissions = {
        val permissions = buildList {
            add(Manifest.permission.ACCESS_FINE_LOCATION)
            add(Manifest.permission.ACCESS_COARSE_LOCATION)
            if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
        }.toTypedArray()
        permissionLauncher.launch(permissions)
    }

    LaunchedEffect(appointment.id, appointment.status) {
        // A walk-in waiting for a teacher: the answer shows here as soon as it comes.
        if (appointment.status == "pending_approval" && appointment.answerByAt != null) {
            while (true) {
                delay(3_000)
                viewModel.pollSelectedAppointment(appointment.id)
            }
        }
        if (appointment.status == "approved" && appointment.qrPass != null) {
            while (true) {
                delay(3_000)
                viewModel.pollSelectedAppointment(appointment.id)
            }
        }
        // A student's accepted visit: the teacher may mark it done or cancel it.
        if (appointment.status == "approved" && appointment.isStudentVisit) {
            while (true) {
                delay(10_000)
                viewModel.pollSelectedAppointment(appointment.id)
            }
        }

        if (appointment.status == "checked_in") {
            val preciseLocationGranted = ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.ACCESS_FINE_LOCATION,
            ) == PackageManager.PERMISSION_GRANTED
            if (preciseLocationGranted) {
                VisitorTrackingService.start(context, appointment.id)
                delay(1_500)
                viewModel.refreshTrackingSilently(appointment.id)
            } else {
                locationPermissionMissing = true
                val permissions = buildList {
                    add(Manifest.permission.ACCESS_FINE_LOCATION)
                    add(Manifest.permission.ACCESS_COARSE_LOCATION)
                    if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
                }.toTypedArray()
                permissionLauncher.launch(permissions)
            }
            while (true) {
                delay(10_000)
                viewModel.pollSelectedAppointment(appointment.id)
            }
        }
    }

    // The campus map: on opening, then every minute (an admin may move a pin). With a pass it is
    // loaded once ahead of the visit, for the directions and to keep them on the phone.
    val studentAccepted = appointment.isStudentVisit && appointment.status == "approved"
    LaunchedEffect(appointment.id, appointment.status == "checked_in", appointment.qrPass != null, studentAccepted) {
        if (appointment.status != "checked_in") {
            // A student has no pass, but once accepted the room and directions come with the map data.
            if (appointment.qrPass != null || studentAccepted) viewModel.loadCampusMap(appointment.id)
            return@LaunchedEffect
        }
        while (true) {
            viewModel.loadCampusMap(appointment.id)
            delay(60_000)
        }
    }
    LaunchedEffect(resumingShare) {
        if (resumingShare) {
            delay(2_000)
            viewModel.refreshTrackingSilently(appointment.id)
            delay(28_000)
            resumingShare = false
        }
    }

    if (appointment.status == "checked_in") {
        TrackingMapScreen(
            visit = CampusVisit(
                trackingAppointmentId = appointment.id,
                visitorName = appointment.visitor?.fullName.orEmpty().ifBlank { "Registered visitor" },
                reference = appointment.registrationCode,
                passPayload = appointment.qrPass?.let { pass -> pass.token.ifBlank { pass.payload } },
                checkedInAt = appointment.checkedInAt,
                appointment = appointment,
            ),
            campusMap = campusMap?.takeIf { it.appointmentId == appointment.id },
            sharing = sharingState(locationPermissionMissing, tracking, resumingShare),
            notice = notice,
            onDismissNotice = viewModel::clearBanner,
            onBack = viewModel::closeAppointment,
            onRequestLocationPermission = requestTrackingPermissions,
            onRefreshCampusMap = { viewModel.loadCampusMap(appointment.id) },
            onStopSharing = {
                resumingShare = false
                VisitorTrackingService.stop(context, appointment.id)
                viewModel.withdrawConsent(appointment.id)
            },
            onShareAgain = {
                viewModel.resumeSharing(appointment.id) {
                    VisitorTrackingService.start(context, appointment.id)
                    resumingShare = true
                }
            },
            onArrival = { stopId, method, distance, accuracy ->
                viewModel.reportArrival(appointment.id, stopId, method, distance, accuracy)
            },
            onAskForHelp = viewModel::askOfficeForHelp,
        )
        return
    }

    val directions = campusMap?.stops.orEmpty().firstOrNull { it.appointmentId == appointment.id }?.toDirections()
    var showDirections by remember(appointment.id) { mutableStateOf(false) }
    if (showDirections && directions != null) {
        DirectionsPreview(directions) { showDirections = false }
        return
    }

    val teacher = appointment.teacher
    if (teacher != null && appointment.status == "pending_approval" && appointment.answerByAt != null) {
        var secondsLeft by remember(appointment.id) { mutableIntStateOf(walkInSecondsLeft(appointment.answerByAt)) }
        LaunchedEffect(appointment.answerByAt) {
            while (true) {
                secondsLeft = walkInSecondsLeft(appointment.answerByAt)
                delay(1_000)
            }
        }
        DetailScaffold("Your request", viewModel::closeAppointment) { modifier ->
            WalkInWaitingContent(teacher.name, appointment.subject, secondsLeft, busy, { showCancel = true }, modifier)
        }
        if (showCancel) {
            CancelAppointmentDialog(
                stop = false,
                onDismiss = { showCancel = false },
                onConfirm = { reason ->
                    viewModel.cancelAppointment(appointment.id, reason)
                    showCancel = false
                },
            )
        }
        return
    }
    if (teacher != null && appointment.status in listOf("rejected", "unanswered", "cancelled")) {
        val choices = choicesFor(teacher.code, teacherChoices)
        LaunchedEffect(appointment.id) { viewModel.loadTeacherChoices(teacher.code) }
        DetailScaffold("Your request", viewModel::closeAppointment) { modifier ->
            val notNow = appointment.status == "rejected"
            TeacherAnswerContent(
                teacherName = teacher.name,
                pill = when (appointment.status) {
                    "rejected" -> if (appointment.visitType == "walk_in") "Can't see you right now" else "Declined"
                    "unanswered" -> "No answer"
                    else -> "Cancelled"
                },
                pillIsRed = notNow || appointment.status == "cancelled",
                message = teacherAnswerMessage(appointment),
                slots = choices?.nextFreeSlots.orEmpty(),
                fallback = appointment.fallbackOffice,
                busy = busy,
                onPickSlot = { slot -> viewModel.requestTeacherTime(appointment, slot) },
                onMoreTimes = { viewModel.rebookWithTeacher(appointment) },
                onFallback = { viewModel.goToFallbackOffice(appointment) },
                modifier = modifier,
            )
        }
        return
    }

    if (studentAccepted) {
        DetailScaffold("Your visit", viewModel::closeAppointment) { modifier ->
            StudentVisitContent(
                appointment = appointment,
                directions = directions,
                busy = busy,
                onDirections = { showDirections = true },
                onImHere = { viewModel.sayImHere(appointment) },
                onCancel = { showCancel = true },
                modifier = modifier,
            )
        }
        if (showCancel) {
            CancelAppointmentDialog(
                stop = false,
                onDismiss = { showCancel = false },
                onConfirm = { reason ->
                    viewModel.cancelAppointment(appointment.id, reason)
                    showCancel = false
                },
            )
        }
        return
    }

    val pass = appointment.qrPass
    val canCancel = appointment.status in listOf("pending_approval", "approved", "reschedule_proposed")
    DetailScaffold(
        if (pass != null) "Your pass" else "Visit details",
        viewModel::closeAppointment,
    ) { outerModifier ->
        LazyColumn(
            modifier = outerModifier.fillMaxSize(),
            contentPadding = PaddingValues(18.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            // With a pass the screen is for the gate: the QR code first, everything else once.
            // It needs no refresh button, since it checks for the check-in scan every 3 seconds.
            if (pass != null) {
                passItems(appointment, pass, busy, directions, { showDirections = true }, onCancel = if (canCancel) ({ showCancel = true }) else null)
                return@LazyColumn
            }
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(appointment.office.name, style = MaterialTheme.typography.headlineMedium)
                        appointment.registrationCode?.let { Text(it, color = MutedInk) }
                    }
                    IconButton(onClick = viewModel::refreshSelectedAppointment, enabled = !busy) {
                        Icon(Icons.Rounded.Refresh, "Refresh")
                    }
                }
            }
            item { StatusPill(appointment.status) }
            if (appointment.status == "completed" && appointment.checkedInAt != null && appointment.visit == null) {
                item {
                    VisitSummaryCard(appointment.checkedInAt, appointment.completedAt, appointment.checkoutMethod)
                }
            }
            appointment.visit?.let { visit ->
                item {
                    Card(
                        colors = CardDefaults.cardColors(containerColor = BlueSurface),
                        border = BorderStroke(1.dp, Color(0xFFBED6FA)),
                        shape = RoundedCornerShape(18.dp),
                    ) {
                        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("Stop ${visit.stopNumber} of ${visit.stopCount} in your visit", style = MaterialTheme.typography.titleMedium)
                            Text(
                                "One visitor pass covers every approved stop of visit ${visit.visitCode}.",
                                color = MutedInk,
                            )
                            PrimaryButton("Open visit pass", { viewModel.openVisit(visit.id) }, Modifier.fillMaxWidth(), !busy)
                        }
                    }
                }
            }
            if (appointment.status == "pending_approval") {
                item {
                    Card(
                        colors = CardDefaults.cardColors(containerColor = YellowSurface),
                        border = BorderStroke(1.dp, Color(0xFFF4D86C)),
                        shape = RoundedCornerShape(18.dp),
                    ) {
                        Row(Modifier.padding(18.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = Color(0xFF9A6700))
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Text("Request submitted", style = MaterialTheme.typography.titleMedium)
                                Text(
                                    teacher?.let { "You'll get a notification when ${it.shortName} answers." }
                                        ?: "The office is reviewing your request. Its decision will appear in Notifications.",
                                    color = MutedInk,
                                )
                            }
                        }
                    }
                }
            }
            item {
                DetailCard {
                    Text("Visit information", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
                    HorizontalDivider(color = BorderSoft)
                    DetailLine(
                        if (appointment.isStudentVisit) "Asked for" else "Visitor type",
                        if (appointment.isStudentVisit) {
                            if (appointment.visitType == "walk_in") "Now" else "A set time"
                        } else {
                            if (appointment.visitType == "walk_in") "Walk-in" else "Appointment"
                        },
                    )
                    DetailLine("Destination", appointment.office.name)
                    DetailLine("Schedule", formatDateTime(appointment.scheduledStartAt))
                    DetailLine("Until", formatDateTime(appointment.scheduledEndAt))
                    DetailLine("Purpose", appointment.purpose)
                    DetailLine("Subject", appointment.subject)
                    if (appointment.additionalDetails.isNotBlank()) DetailLine("Details", appointment.additionalDetails)
                }
            }
            if (appointment.rejectionReason.isNotBlank()) {
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFE8E6)), shape = RoundedCornerShape(16.dp)) {
                        Column(Modifier.padding(16.dp)) {
                            Text(if (teacher != null) "${teacher.shortName}'s response" else "Office response", fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.error)
                            Text(appointment.rejectionReason)
                        }
                    }
                }
            }
            pendingProposal?.let { proposal ->
                val singleSlot = proposal.slots.size == 1
                item {
                    Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFF8DB)), shape = RoundedCornerShape(18.dp)) {
                        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text(
                                (teacher?.shortName ?: "The office") + if (singleSlot) " suggested another time" else " suggested other times",
                                style = MaterialTheme.typography.titleLarge,
                            )
                            Text(proposal.reason, fontWeight = FontWeight.SemiBold)
                            if (proposal.message.isNotBlank()) Text(proposal.message, color = MutedInk)
                            proposal.responseDeadline?.let { Text("Respond by ${formatDateTime(it)}", color = MutedInk) }
                        }
                    }
                }
                items(proposal.slots, key = { it.id }) { slot ->
                    val selected = selectedSlotId == slot.id
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        colors = CardDefaults.cardColors(containerColor = if (selected) Color(0xFFDCEBFF) else Color.White),
                        border = BorderStroke(1.dp, if (selected) IsatuBlue else BorderSoft),
                    ) {
                        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            if (!singleSlot) RadioButton(selected, { selectedSlotId = slot.id })
                            Column(Modifier.padding(start = if (singleSlot) 6.dp else 0.dp)) {
                                Text(formatVisitDate(slot.scheduledStartAt), fontWeight = FontWeight.SemiBold)
                                Text(formatTimeSpan(slot.scheduledStartAt, slot.scheduledEndAt), color = MutedInk)
                            }
                        }
                    }
                }
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        PrimaryButton(
                            if (singleSlot) "Accept suggested time" else "Accept selected time",
                            { viewModel.acceptProposedTime(proposal.id, selectedSlotId) },
                            Modifier.fillMaxWidth(),
                            !busy && selectedSlotId > 0,
                        )
                        OutlinedButton(
                            onClick = { showDeclineProposal = true },
                            modifier = Modifier.fillMaxWidth().height(52.dp),
                            enabled = !busy,
                        ) { Text(if (singleSlot) "This time doesn't work" else "None of these work") }
                    }
                }
            }
            if (canCancel) {
                item {
                    OutlinedButton(
                        onClick = { showCancel = true },
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        enabled = !busy,
                    ) {
                        Text(
                            if (appointment.visit != null) "Cancel this stop" else "Cancel visit",
                            color = MaterialTheme.colorScheme.error,
                        )
                    }
                }
            }
            item { Spacer(Modifier.height(18.dp)) }
        }
    }

    if (showCancel) {
        CancelAppointmentDialog(
            stop = appointment.visit != null,
            onDismiss = { showCancel = false },
            onConfirm = { reason ->
                viewModel.cancelAppointment(appointment.id, reason)
                showCancel = false
            },
        )
    }

    if (showDeclineProposal && pendingProposal != null) {
        AlertDialog(
            onDismissRequest = { showDeclineProposal = false },
            title = { Text("Choose your own time instead?") },
            text = {
                Text(
                    "This declines the suggested time${if (pendingProposal.slots.size == 1) "" else "s"}. " +
                        "The booking form opens with ${appointment.office.name}, your purpose, and your subject " +
                        "already filled in, so you only need to pick a new time.",
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    showDeclineProposal = false
                    viewModel.declineAndRebook(pendingProposal.id, appointment)
                }) { Text("Pick a new time") }
            },
            dismissButton = { TextButton(onClick = { showDeclineProposal = false }) { Text("Go back") } },
        )
    }
}

/** The pass screen of a single visit: the QR code first, the visit in one line, the rest folded. */
internal fun LazyListScope.passItems(
    appointment: AppointmentDto,
    pass: QrPassDto,
    busy: Boolean,
    directions: OfficeDirections?,
    onHowToFind: () -> Unit,
    onCancel: (() -> Unit)?,
) {
    item {
        PassCard(
            payload = pass.token.ifBlank { pass.payload },
            visitorName = appointment.visitor?.fullName.orEmpty().ifBlank { "Registered visitor" },
            reference = appointment.registrationCode,
            pass = pass,
        )
    }
    item { PassSummaryCard(appointment.office.name, visitTimeText(appointment), directions?.where.orEmpty(), onHowToFind.takeIf { directions?.hasSteps == true || directions?.message?.isNotBlank() == true }) }
    item {
        PassDetails(
            lines = buildList {
                add("Purpose" to appointment.purpose)
                add("Subject" to appointment.subject)
                if (appointment.additionalDetails.isNotBlank()) add("Additional details" to appointment.additionalDetails)
                add("The guard can scan it" to formatVisitTime(pass.validFrom, pass.validUntil))
            },
            note = "Keep your pass private: don't share screenshots.",
        )
    }
    onCancel?.let { cancel -> item { QuietCancelButton("Cancel visit", !busy, cancel) } }
    item { Spacer(Modifier.height(18.dp)) }
}

private fun formatVisitDate(value: String): String = runCatching {
    val source = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
    val target = java.text.SimpleDateFormat("EEEE, MMM d, yyyy", java.util.Locale.US)
    target.format(requireNotNull(source.parse(value)))
}.getOrDefault(value)

@Composable
private fun DetailCard(content: @Composable ColumnScope.() -> Unit) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            content()
        }
    }
}

@Composable
private fun DetailLine(label: String, value: String) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        Text(value)
    }
}

/** Cancels a single visit, or one [stop] of a visit to several offices. */
@Composable
private fun CancelAppointmentDialog(stop: Boolean, onDismiss: () -> Unit, onConfirm: (String) -> Unit) {
    var reason by remember { mutableStateOf("") }
    val what = if (stop) "stop" else "visit"
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Cancel this $what?") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("The office will be notified and the slot will become available to another visitor.")
                OutlinedTextField(reason, { reason = it.take(500) }, label = { Text("Reason (optional)") }, minLines = 2)
            }
        },
        confirmButton = { TextButton(onClick = { onConfirm(reason) }) { Text("Cancel $what", color = MaterialTheme.colorScheme.error) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Keep $what") } },
    )
}

/** The next free times, once they're for the teacher on screen. */
private fun choicesFor(code: String, choices: TeacherDto?): TeacherDto? = choices?.takeIf { it.code == code }

private fun teacherAnswerMessage(appointment: AppointmentDto): String {
    val carryOn = "Pick another time. What you typed stays, so there's nothing to fill in again."
    return when (appointment.status) {
        "unanswered" -> "Your request closed with no answer. $carryOn"
        "cancelled" -> "This visit was cancelled, so its pass no longer works. $carryOn"
        else -> {
            val reason = appointment.rejectionReason.trim()
            // "Not now" sends a set sentence, which the pill above already says.
            if (appointment.visitType == "walk_in" || reason.isEmpty()) carryOn else "\"$reason\" $carryOn"
        }
    }
}
