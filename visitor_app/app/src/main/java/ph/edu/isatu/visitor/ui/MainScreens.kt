package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.MarkEmailRead
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.PendingActions
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Security
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import kotlinx.coroutines.delay
import ph.edu.isatu.visitor.BuildConfig
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.NotificationDto
import ph.edu.isatu.visitor.data.UserDto
import ph.edu.isatu.visitor.data.VisitSummaryDto
import java.text.SimpleDateFormat
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

private data class MainTab(val label: String, val icon: ImageVector)

private val tabs = listOf(
    MainTab("Home", Icons.Rounded.Home),
    MainTab("Visits", Icons.Rounded.CalendarMonth),
    MainTab("Book", Icons.Rounded.Add),
)

private enum class HeaderScreen { Notifications, Profile }

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MainShell(state: VisitorUiState, viewModel: AppViewModel) {
    var selectedTab by remember { mutableIntStateOf(0) }
    var headerScreen by remember { mutableStateOf<HeaderScreen?>(null) }
    var noticeOpen by remember { mutableStateOf(false) }
    // Office decisions and suggested times arrive as notifications. Check whenever the
    // app returns to the foreground and every 30 seconds while it stays open.
    val lifecycleOwner = LocalLifecycleOwner.current
    LaunchedEffect(lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (true) {
                viewModel.refreshNotifications()
                delay(30_000)
            }
        }
    }
    // "None of these work" hands the request's details to the booking form.
    LaunchedEffect(state.bookingPrefill) {
        if (state.bookingPrefill != null) {
            selectedTab = 2
            headerScreen = null
        }
    }
    state.selectedAppointment?.let { appointment ->
        AppointmentDetailScreen(appointment, state.tracking, state.busy, viewModel, state.campusMap, state.error ?: state.message, state.teacherChoices)
        return
    }
    state.selectedVisit?.let { visit ->
        VisitDetailScreen(visit, state.tracking, state.busy, viewModel, state.campusMap, state.error ?: state.message)
        return
    }
    if (noticeOpen) {
        TermsNoticeScreen("Back to profile") { noticeOpen = false }
        return
    }

    Scaffold(
        containerColor = AppBackground,
        topBar = {
            Column {
                TopAppBar(
                    title = { PortalBrand() },
                    actions = {
                        IconButton(onClick = {
                            headerScreen = HeaderScreen.Notifications
                            viewModel.refreshNotifications()
                        }) {
                            BadgedBox(
                                badge = {
                                    if (state.unreadCount > 0) {
                                        Badge(containerColor = Color(0xFFD92D20), contentColor = Color.White) {
                                            Text(state.unreadCount.coerceAtMost(99).toString())
                                        }
                                    }
                                },
                            ) {
                                Icon(Icons.Rounded.Notifications, contentDescription = "Notifications", tint = IsatuBlueDark)
                            }
                        }
                        IconButton(onClick = { headerScreen = HeaderScreen.Profile }) {
                            Icon(Icons.Rounded.Person, contentDescription = "Profile", tint = IsatuBlueDark)
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White),
                )
                if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = IsatuBlue)
            }
        },
        bottomBar = {
            NavigationBar(containerColor = Color.White, tonalElevation = 8.dp) {
                tabs.forEachIndexed { index, tab ->
                    NavigationBarItem(
                        selected = headerScreen == null && selectedTab == index,
                        onClick = {
                            selectedTab = index
                            headerScreen = null
                        },
                        icon = { Icon(tab.icon, contentDescription = tab.label) },
                        label = { Text(tab.label) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = IsatuBlue,
                            selectedTextColor = IsatuBlueDark,
                            indicatorColor = BlueSurface,
                            unselectedIconColor = MutedInk,
                            unselectedTextColor = MutedInk,
                        ),
                    )
                }
            }
        },
    ) { innerPadding ->
        Column(Modifier.fillMaxSize().padding(innerPadding)) {
            if (state.error != null || state.message != null) {
                Box(Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                    MessageCard(
                        message = state.error ?: state.message.orEmpty(),
                        error = state.error != null,
                        onDismiss = viewModel::clearBanner,
                    )
                }
            }
            when (headerScreen) {
                HeaderScreen.Notifications -> NotificationsScreen(state.notifications, state.unreadCount, viewModel)
                HeaderScreen.Profile -> ProfileScreen(state, viewModel, onOpenNotice = { noticeOpen = true })
                null -> when (selectedTab) {
                    0 -> HomeScreen(
                        user = state.user,
                        appointments = state.appointments,
                        onOpenAppointment = viewModel::openAppointment,
                        onOpenVisit = viewModel::openVisit,
                        onBook = { selectedTab = 2 },
                    )
                    1 -> AppointmentsScreen(state.appointments, viewModel::openAppointment, viewModel::openVisit, viewModel::refreshAll)
                    else -> BookingScreen(state, viewModel)
                }
            }
        }
    }
}

@Composable
internal fun HomeScreen(
    user: UserDto?,
    appointments: List<AppointmentDto>,
    onOpenAppointment: (Long) -> Unit,
    onOpenVisit: (Long) -> Unit,
    onBook: () -> Unit,
) {
    val context = LocalContext.current
    var notificationsAllowed by remember {
        mutableStateOf(
            Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
                ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED,
        )
    }
    val notificationPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted -> notificationsAllowed = granted }
    var showSteps by remember { mutableStateOf(false) }

    val upcoming = groupVisits(appointments).filter { it.isActive }.minByOrNull { it.startsAt }
    val firstName = user?.fullName?.trim()?.substringBefore(' ')?.ifBlank { "Visitor" } ?: "Visitor"
    val isStudent = user?.isStudent == true

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 18.dp, end = 18.dp, top = 18.dp, bottom = 28.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item { Greeting(firstName) }
        // The visit that matters now, with the one thing to do next. Booking is the Book tab.
        item {
            when (upcoming) {
                null -> NoVisitCard(onBook, isStudent)
                is VisitListItem.Single -> NextVisitCard(homeCard(upcoming)) { onOpenAppointment(upcoming.appointment.id) }
                is VisitListItem.MultiStop -> NextVisitCard(homeCard(upcoming)) { onOpenVisit(upcoming.summary.id) }
            }
        }
        if (!notificationsAllowed) {
            item {
                NotificationsPrompt {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
                    }
                }
            }
        }
        // Until a visitor's first booking the steps show in full; after that, behind a link.
        item {
            if (appointments.isEmpty()) {
                VisitStepsCard(isStudent)
            } else {
                TextButton(onClick = { showSteps = true }, modifier = Modifier.fillMaxWidth()) {
                    Icon(Icons.Rounded.Info, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.size(6.dp))
                    Text("How a visit works")
                }
            }
        }
    }
    if (showSteps) {
        AlertDialog(
            onDismissRequest = { showSteps = false },
            title = { Text("How a visit works") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(12.dp)) { VisitSteps(isStudent) } },
            confirmButton = { TextButton(onClick = { showSteps = false }) { Text("Got it") } },
        )
    }
}

@Composable
private fun Greeting(firstName: String) {
    val now = remember { LocalDateTime.now(ManilaZone) }
    val greeting = when (now.hour) {
        in 5..11 -> "Good morning"
        in 12..17 -> "Good afternoon"
        else -> "Good evening"
    }
    Column(Modifier.padding(horizontal = 2.dp)) {
        Text("$greeting, $firstName", style = MaterialTheme.typography.titleLarge, color = Ink)
        Text(
            now.format(DateTimeFormatter.ofPattern("EEEE, MMMM d", Locale.US)),
            style = MaterialTheme.typography.bodyMedium,
            color = MutedInk,
        )
    }
}

private enum class Tone(val background: Color, val content: Color) {
    Green(Color(0xFFDCFCE7), IsatuGreen),
    Yellow(Color(0xFFFFF4CC), Color(0xFF9A6700)),
    Blue(BlueSurface, IsatuBlueDark),
    Grey(Color(0xFFE8EEF7), Color(0xFF475569)),
}

/** What the Home card says about a visit: where it stands, when it is, and the one thing to do. */
private data class HomeCard(
    val state: String,
    val tone: Tone,
    val title: String,
    val timeIcon: ImageVector,
    val time: String,
    val note: String,
    val action: String? = null,
    val actionIcon: ImageVector? = null,
)

private fun homeCard(item: VisitListItem): HomeCard = when (item) {
    is VisitListItem.Single -> {
        val appointment = item.appointment
        val office = appointment.office.name
        val time = visitTimeText(appointment)
        val teacherName = appointment.teacher?.shortName
        when (appointment.status) {
            // The visit list does not carry the suggested times; the visit screen shows them.
            "reschedule_proposed" -> HomeCard(
                "New time suggested", Tone.Yellow, office, Icons.Rounded.Schedule, "Requested: $time",
                (teacherName ?: "The office") + " suggested another time. Accept it or pick a new one.", "Respond",
            )
            "checked_in" -> HomeCard(
                "On campus", Tone.Blue, office, Icons.Rounded.LocationOn,
                appointment.checkedInAt?.let { "Checked in at ${formatTimeOfDay(it)}" } ?: time,
                "Directions and your pass are on the map.", "Open map", Icons.Rounded.Map,
            )
            // A student has no pass: the room, the directions and "I'm here" are inside.
            "approved" -> if (appointment.isStudentVisit) {
                HomeCard(
                    if (appointment.arrivedAt != null) "You're here" else "Accepted", Tone.Green, office, Icons.Rounded.Schedule, time,
                    appointment.subject, if (appointment.arrivedAt != null) "Open visit" else "Directions and I'm here", Icons.Rounded.Map,
                )
            } else {
                HomeCard(
                    if (teacherName != null) "Accepted · pass ready" else "Approved · pass ready", Tone.Green, office, Icons.Rounded.Schedule, time, appointment.subject,
                    "Show pass", Icons.Rounded.QrCode2,
                )
            }
            else -> if (appointment.answerByAt != null) {
                HomeCard(
                    "Waiting for an answer", Tone.Yellow, office, Icons.Rounded.Schedule, "Request closes at ${formatTimeOfDay(appointment.answerByAt)}",
                    if (appointment.isStudentVisit) "You'll get a notification when ${teacherName ?: "they"} answers." else "You have no pass yet. You'll get a notification.",
                )
            } else {
                HomeCard(
                    "Waiting for approval", Tone.Grey, office, Icons.Rounded.Schedule, time,
                    teacherName?.let { "You'll get a notification when $it answers." } ?: "You'll get a notification when the office decides.",
                )
            }
        }
    }
    is VisitListItem.MultiStop -> {
        val stops = item.stops
        val title = "${stops.size} office stops"
        val first = stops.firstOrNull()
        val time = when {
            first == null -> ""
            first.visitType == "walk_in" -> visitTimeText(first)
            else -> formatVisitStart(first.scheduledStartAt)
        }
        when {
            stops.any { it.status == "reschedule_proposed" } -> HomeCard(
                "New time suggested", Tone.Yellow, title, Icons.Rounded.Schedule, time,
                "An office suggested another time for its stop.", "Respond",
            )
            item.summary.status == "checked_in" -> HomeCard(
                "On campus", Tone.Blue, title, Icons.Rounded.LocationOn,
                stops.firstNotNullOfOrNull { it.checkedInAt }?.let { "Checked in at ${formatTimeOfDay(it)}" } ?: time,
                "Directions and your pass are on the map.", "Open map", Icons.Rounded.Map,
            )
            // One pass covers the visit as soon as one office approves its stop.
            stops.any { it.status == "approved" } -> HomeCard(
                "Pass ready", Tone.Green, title, Icons.Rounded.Schedule, time,
                stops.joinToString(" → ") { it.office.name }, "Show pass", Icons.Rounded.QrCode2,
            )
            else -> HomeCard(
                "Waiting for approval", Tone.Grey, title, Icons.Rounded.Schedule, time,
                "Each office reviews its own stop. You'll get a notification.",
            )
        }
    }
}

@Composable
private fun NextVisitCard(card: HomeCard, onOpen: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onOpen),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.background(card.tone.background, RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp),
                ) {
                    Text(card.state, color = card.tone.content, style = MaterialTheme.typography.labelLarge)
                }
                Spacer(Modifier.weight(1f))
                Icon(Icons.Rounded.ChevronRight, contentDescription = "Open visit", tint = MutedInk)
            }
            Text(card.title, style = MaterialTheme.typography.titleLarge, color = Ink)
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(card.timeIcon, contentDescription = null, tint = MutedInk, modifier = Modifier.size(18.dp))
                Text(card.time)
            }
            if (card.note.isNotBlank()) {
                Text(
                    card.note,
                    color = MutedInk,
                    style = MaterialTheme.typography.bodyMedium,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            card.action?.let { action ->
                PrimaryButton(action, onOpen, Modifier.fillMaxWidth().padding(top = 4.dp), icon = card.actionIcon)
            }
        }
    }
}

@Composable
private fun NoVisitCard(onBook: () -> Unit, isStudent: Boolean = false) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("No upcoming visits", style = MaterialTheme.typography.titleLarge, color = Ink)
            Text(
                if (isStudent) "See a teacher now or at a set time." else "Book a walk-in or an appointment.",
                color = MutedInk,
                style = MaterialTheme.typography.bodyMedium,
            )
            PrimaryButton(if (isStudent) "Book a teacher" else "Book a visit", onBook, Modifier.fillMaxWidth().padding(top = 4.dp), icon = Icons.Rounded.Add)
        }
    }
}

@Composable
private fun NotificationsPrompt(onEnable: () -> Unit) {
    Card(
        colors = CardDefaults.cardColors(containerColor = YellowSurface),
        shape = RoundedCornerShape(18.dp),
        border = BorderStroke(1.dp, Color(0xFFF4D86C)),
    ) {
        Row(Modifier.padding(18.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Icon(Icons.Rounded.Notifications, contentDescription = null, tint = Color(0xFF9A6700))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Turn on appointment updates", style = MaterialTheme.typography.titleMedium)
                Text(
                    "Get notified when an office approves, declines, or suggests another schedule.",
                    color = MutedInk,
                )
                TextButton(onClick = onEnable) { Text("Enable notifications") }
            }
        }
    }
}

@Composable
private fun VisitStepsCard(isStudent: Boolean = false) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("How a visit works", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
            VisitSteps(isStudent)
        }
    }
}

@Composable
private fun VisitSteps(isStudent: Boolean = false) {
    if (isStudent) {
        // No pass, gate scan or tracking for an ISATU student.
        VisitStep(Icons.Rounded.PendingActions, "1", "Find a teacher", "Any teacher at ISATU, by name. Ask to see them now or pick a time.")
        VisitStep(Icons.Rounded.CheckCircle, "2", "Wait for the answer", "You'll get a notification when they accept.")
        VisitStep(Icons.Rounded.LocationOn, "3", "Go to their room", "The room and the directions show once they accept.")
        VisitStep(Icons.Rounded.Notifications, "4", "Tap I'm here", "They're told you've arrived. No pass and no gate scan.")
        return
    }
    VisitStep(Icons.Rounded.PendingActions, "1", "Register", "Choose walk-in or appointment and tell us where you are going.")
    VisitStep(Icons.Rounded.CheckCircle, "2", "Get your pass", "Walk-ins receive it now; appointments receive it after approval.")
    VisitStep(Icons.Rounded.QrCode2, "3", "Present your pass", "An approved visit receives a secure QR pass.")
    VisitStep(Icons.Rounded.LocationOn, "4", "Check in", "Location sharing starts only after Security check-in and your permission.")
}

@Composable
private fun VisitStep(icon: ImageVector, number: String, title: String, body: String) {
    Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.size(38.dp).clip(CircleShape).background(BlueSurface), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = "Step $number", tint = IsatuBlue, modifier = Modifier.size(21.dp))
        }
        Column(Modifier.weight(1f)) {
            Text("$number. $title", fontWeight = FontWeight.SemiBold)
            Text(body, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

/** An entry in the visits list: a single appointment, or every stop of a multi-stop visit. */
private sealed interface VisitListItem {
    val key: String
    val startsAt: String
    val isActive: Boolean

    data class Single(val appointment: AppointmentDto) : VisitListItem {
        override val key = "appointment-${appointment.id}"
        override val startsAt = appointment.scheduledStartAt
        override val isActive = appointment.status in activeAppointmentStatuses
    }

    data class MultiStop(val summary: VisitSummaryDto, val stops: List<AppointmentDto>) : VisitListItem {
        override val key = "visit-${summary.id}"
        override val startsAt = stops.minOfOrNull { it.scheduledStartAt }.orEmpty()
        override val isActive = summary.status in setOf("open", "checked_in")
    }
}

private val activeAppointmentStatuses = setOf("pending_approval", "approved", "reschedule_proposed", "checked_in")

/** Keeps the server's order while folding each visit's stops into one entry. */
private fun groupVisits(appointments: List<AppointmentDto>): List<VisitListItem> {
    val stopsByVisit = appointments.filter { it.visit != null }.groupBy { it.visit!!.id }
    val seen = mutableSetOf<Long>()
    return appointments.mapNotNull { appointment ->
        val visit = appointment.visit
        when {
            visit == null -> VisitListItem.Single(appointment)
            seen.add(visit.id) -> VisitListItem.MultiStop(
                visit,
                stopsByVisit[visit.id].orEmpty().sortedBy { it.scheduledStartAt },
            )
            else -> null
        }
    }
}

@Composable
private fun VisitListCard(item: VisitListItem, onOpenAppointment: (Long) -> Unit, onOpenVisit: (Long) -> Unit) {
    when (item) {
        is VisitListItem.Single -> AppointmentCard(item.appointment) { onOpenAppointment(item.appointment.id) }
        is VisitListItem.MultiStop -> MultiStopVisitCard(item) { onOpenVisit(item.summary.id) }
    }
}

@Composable
private fun MultiStopVisitCard(item: VisitListItem.MultiStop, onClick: () -> Unit) {
    val needsAction = item.stops.any { it.status == "reschedule_proposed" }
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, if (needsAction) Color(0xFFF4D86C) else BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(BlueSurface), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.LocationOn, contentDescription = null, tint = IsatuBlue)
                }
                Spacer(Modifier.size(12.dp))
                Column(Modifier.weight(1f)) {
                    Text("${item.stops.size} office stops", style = MaterialTheme.typography.titleMedium)
                    Text(
                        "${if (item.stops.firstOrNull()?.visitType == "walk_in") "Walk-in visit" else "Multi-stop visit"} • " +
                            formatDateTime(item.startsAt),
                        color = MutedInk,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
                Icon(Icons.Rounded.ChevronRight, contentDescription = "Open visit", tint = MutedInk)
            }
            Text(
                item.stops.joinToString(" → ") { it.office.name },
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    if (needsAction) "An office suggested a new time" else "Reference ${item.summary.visitCode}",
                    color = if (needsAction) Color(0xFF9A6700) else IsatuBlue,
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
                Box(
                    modifier = Modifier.background(BlueSurface, RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp),
                ) {
                    Text(visitStatusLabel(item.summary.status), color = IsatuBlueDark, style = MaterialTheme.typography.labelLarge)
                }
            }
        }
    }
}

@Composable
private fun AppointmentsScreen(
    appointments: List<AppointmentDto>,
    onOpen: (Long) -> Unit,
    onOpenVisit: (Long) -> Unit,
    onRefresh: () -> Unit,
) {
    var selectedFilter by remember { mutableStateOf("All") }
    val filters = listOf("All", "Active", "History")
    val visible = groupVisits(appointments).filter {
        when (selectedFilter) {
            "Active" -> it.isActive
            "History" -> !it.isActive
            else -> true
        }
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("My visits", style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
                    Text("Requests, visitor passes, and visit history", color = MutedInk)
                }
                IconButton(onClick = onRefresh) { Icon(Icons.Rounded.Refresh, "Refresh") }
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                filters.forEach { filter ->
                    if (selectedFilter == filter) {
                        FilledTonalButton(onClick = { selectedFilter = filter }) { Text(filter) }
                    } else {
                        OutlinedButton(onClick = { selectedFilter = filter }) { Text(filter) }
                    }
                }
            }
        }
        if (visible.isEmpty()) {
            item { EmptyState("Nothing here yet", "Visits matching this filter will appear here.") }
        } else {
            items(visible, key = { it.key }) { VisitListCard(it, onOpen, onOpenVisit) }
        }
    }
}

@Composable
fun AppointmentCard(appointment: AppointmentDto, onClick: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.padding(17.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(BlueSurface), contentAlignment = Alignment.Center) {
                    Icon(
                        if (appointment.qrPass != null) Icons.Rounded.QrCode2 else Icons.Rounded.CalendarMonth,
                        contentDescription = null,
                        tint = IsatuBlue,
                    )
                }
                Spacer(Modifier.size(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(appointment.office.name, style = MaterialTheme.typography.titleMedium)
                    Text(
                        "${if (appointment.visitType == "walk_in") "Walk-in" else "Appointment"} • ${formatDateTime(appointment.scheduledStartAt)}",
                        color = MutedInk,
                        style = MaterialTheme.typography.bodyMedium,
                    )
                }
                Icon(Icons.Rounded.ChevronRight, contentDescription = "Open visit", tint = MutedInk)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(appointment.subject, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                Spacer(Modifier.size(8.dp))
                StatusPill(appointment.status)
            }
            appointment.registrationCode?.let {
                Text("Reference $it", color = IsatuBlue, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun NotificationsScreen(
    notifications: List<NotificationDto>,
    unreadCount: Int,
    viewModel: AppViewModel,
) {
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Notifications", style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
                    Text(if (unreadCount == 0) "You are all caught up" else "$unreadCount unread notification(s)", color = MutedInk)
                }
                if (unreadCount > 0) TextButton(onClick = viewModel::markAllNotificationsRead) { Text("Mark all read") }
            }
        }
        if (notifications.isEmpty()) {
            item { EmptyState("No notifications yet", "Office decisions, schedule changes, and visit reminders will appear here.") }
        } else {
            items(notifications, key = { it.id }) { notification ->
                NotificationCard(notification) {
                    if (notification.readAt == null) viewModel.markNotificationRead(notification.id)
                    val visitId = (notification.data?.get("visit_id") as? Number)?.toLong()
                    when {
                        visitId != null -> viewModel.openVisit(visitId)
                        else -> notification.appointmentId?.let(viewModel::openAppointment)
                    }
                }
            }
        }
    }
}

@Composable
private fun NotificationCard(notification: NotificationDto, onClick: () -> Unit) {
    Card(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick),
        colors = CardDefaults.cardColors(containerColor = if (notification.readAt == null) BlueSurface else Color.White),
        border = BorderStroke(1.dp, if (notification.readAt == null) Color(0xFFB9D4FF) else BorderSoft),
        shape = RoundedCornerShape(16.dp),
    ) {
        Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(
                Modifier.size(40.dp).clip(CircleShape).background(if (notification.readAt == null) IsatuBlue else Color(0xFFE8EEF7)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Rounded.MarkEmailRead,
                    contentDescription = null,
                    tint = if (notification.readAt == null) Color.White else MutedInk,
                    modifier = Modifier.size(21.dp),
                )
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(notification.title, fontWeight = FontWeight.SemiBold)
                Text(notification.message, color = MutedInk)
                Text(formatDateTime(notification.createdAt), style = MaterialTheme.typography.bodySmall, color = MutedInk)
            }
            if (notification.readAt == null) {
                Box(Modifier.size(8.dp).clip(CircleShape).background(IsatuBlue))
            }
        }
    }
}

@Composable
private fun ProfileScreen(state: VisitorUiState, viewModel: AppViewModel, onOpenNotice: () -> Unit) {
    var showEdit by remember { mutableStateOf(false) }
    val user = state.user
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Text("My profile", style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
            Text("Personal details and privacy controls", color = MutedInk)
        }
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, BorderSoft),
                shape = RoundedCornerShape(20.dp),
            ) {
                Column(Modifier.fillMaxWidth().padding(22.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(
                        Modifier.size(78.dp).clip(CircleShape).background(BlueSurface),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            user?.fullName?.trim()?.firstOrNull()?.uppercase() ?: "V",
                            style = MaterialTheme.typography.headlineLarge,
                            color = IsatuBlue,
                        )
                    }
                    Spacer(Modifier.height(12.dp))
                    Text(user?.fullName.orEmpty(), style = MaterialTheme.typography.titleLarge)
                    Text(user?.email.orEmpty(), color = MutedInk)
                    Text(user?.contactNumber?.ifBlank { "No contact number" }.orEmpty(), color = MutedInk)
                    Spacer(Modifier.height(14.dp))
                    FilledTonalButton(onClick = { showEdit = true }) {
                        Icon(Icons.Rounded.Edit, contentDescription = null)
                        Spacer(Modifier.size(8.dp))
                        Text("Edit profile")
                    }
                }
            }
        }
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, BorderSoft),
                shape = RoundedCornerShape(18.dp),
            ) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Security, contentDescription = null, tint = IsatuBlue)
                        Spacer(Modifier.size(10.dp))
                        Text("Privacy and location", style = MaterialTheme.typography.titleMedium)
                    }
                    Text(
                        "Location is collected only after Security checks you in and while the tracking notification is visible. You can withdraw consent from the active visit.",
                        color = MutedInk,
                    )
                    NoticeLink(onOpenNotice)
                }
            }
        }
        item {
            OutlinedButton(
                onClick = viewModel::logout,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                enabled = !state.busy,
            ) {
                Icon(Icons.AutoMirrored.Rounded.Logout, contentDescription = null)
                Spacer(Modifier.size(8.dp))
                Text("Sign out")
            }
        }
        item {
            Text(
                "App version ${BuildConfig.VERSION_NAME}",
                modifier = Modifier.fillMaxWidth(),
                color = MutedInk,
                style = MaterialTheme.typography.bodySmall,
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
        }
    }
    if (showEdit && user != null) {
        EditProfileDialog(user.fullName, user.contactNumber, { showEdit = false }) { name, contact ->
            viewModel.updateProfile(name, contact)
            showEdit = false
        }
    }
}

@Composable
private fun EditProfileDialog(
    currentName: String,
    currentContact: String,
    onDismiss: () -> Unit,
    onSave: (String, String) -> Unit,
) {
    var name by remember { mutableStateOf(currentName) }
    var contact by remember { mutableStateOf(currentContact) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Edit profile") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(name, { name = it }, label = { Text("Full name") }, singleLine = true)
                OutlinedTextField(contact, { contact = it }, label = { Text("Contact number") }, singleLine = true)
            }
        },
        confirmButton = {
            TextButton(onClick = { onSave(name, contact) }, enabled = name.trim().length >= 2) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

fun formatDateTime(value: String): String {
    if (value.isBlank()) return "Schedule not available"
    return runCatching {
        val source = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
        val target = SimpleDateFormat("MMM d, yyyy • h:mm a", Locale.US)
        target.format(requireNotNull(source.parse(value)))
    }.getOrDefault(value)
}

/** The way back to the notice from Profile. */
@Composable
internal fun NoticeLink(onClick: () -> Unit) {
    TextButton(onClick = onClick, contentPadding = PaddingValues(0.dp)) {
        Text(TermsNotice.TITLE)
        Icon(Icons.Rounded.ChevronRight, contentDescription = null)
    }
}
