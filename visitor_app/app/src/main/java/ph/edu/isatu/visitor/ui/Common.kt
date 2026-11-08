package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import ph.edu.isatu.visitor.R
import ph.edu.isatu.visitor.data.AppointmentDto

/** The seal and the app's name. [onNavy] puts the seal on a white disc and the words in white. */
@Composable
fun PortalBrand(modifier: Modifier = Modifier, subtitle: String? = null, onNavy: Boolean = false) {
    val sealHome = LocalSealHome.current
    DisposableEffect(sealHome) {
        onDispose { sealHome?.bounds = null }
    }
    Row(
        modifier = modifier,
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Image(
            painter = painterResource(R.drawable.isatu_seal),
            contentDescription = "ISATU logo",
            modifier = Modifier
                .size(if (onNavy) 38.dp else 32.dp)
                // Until the opening animation's seal lands here, that one is the logo on screen.
                .alpha(if (sealHome?.away == true) 0f else 1f)
                .then(if (onNavy) Modifier.background(Color.White, CircleShape).padding(2.dp) else Modifier)
                .onGloballyPositioned { sealHome?.bounds = it.boundsInRoot() },
        )
        Column {
            Text(
                "ISATU Visits",
                style = MaterialTheme.typography.titleMedium,
                color = if (onNavy) Color.White else IsatuBlueDark,
            )
            subtitle?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = if (onNavy) OnNavyMuted else MutedInk)
            }
        }
    }
}

@Composable
fun PrimaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    icon: ImageVector? = null,
) {
    Button(
        onClick = onClick,
        modifier = modifier.height(52.dp),
        enabled = enabled,
        shape = RoundedCornerShape(14.dp),
        colors = ButtonDefaults.buttonColors(containerColor = IsatuBlue),
    ) {
        icon?.let {
            Icon(it, contentDescription = null, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
        }
        Text(text, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun LoadingBlock(label: String = "Loading…") {
    Column(
        modifier = Modifier.fillMaxWidth().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        CircularProgressIndicator(color = IsatuBlue)
        Text(label, color = MutedInk)
    }
}

@Composable
fun EmptyState(title: String, body: String) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(16.dp),
    ) {
        Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(body, color = MutedInk)
        }
    }
}

@Composable
fun StatusPill(status: String) {
    val (background, foreground) = when (status) {
        "approved", "checked_in" -> Color(0xFFDCFCE7) to IsatuGreen
        "pending_approval", "reschedule_proposed" -> Color(0xFFFFF4CC) to Color(0xFF9A6700)
        "rejected", "cancelled" -> Color(0xFFFEE2E2) to Color(0xFFB42318)
        else -> Color(0xFFE8EEF7) to Color(0xFF475569)
    }
    Box(
        modifier = Modifier.background(background, RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp),
    ) {
        Text(statusLabel(status), color = foreground, style = MaterialTheme.typography.labelLarge)
    }
}

/** "9:00 AM" from a server "yyyy-MM-dd HH:mm:ss" value. */
fun formatTimeOfDay(value: String): String = runCatching {
    val source = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
    val target = java.text.SimpleDateFormat("h:mm a", java.util.Locale.US)
    target.format(requireNotNull(source.parse(value)))
}.getOrDefault(value)

/** "9:00 AM – 9:30 AM (30 min)" for a slot on one day. */
fun formatTimeSpan(start: String, end: String): String {
    val source = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
    val minutes = runCatching {
        (requireNotNull(source.parse(end)).time - requireNotNull(source.parse(start)).time) / 60_000
    }.getOrNull()
    val range = "${formatTimeOfDay(start)} – ${formatTimeOfDay(end)}"
    return if (minutes != null && minutes > 0) "$range ($minutes min)" else range
}

internal val ManilaZone: java.time.ZoneId = java.time.ZoneId.of("Asia/Manila")

/** A server time ("yyyy-MM-dd HH:mm:ss", Philippine time) as epoch milliseconds. */
fun serverTimeMillis(value: String?): Long? = value?.takeIf { it.isNotBlank() }?.let {
    runCatching {
        java.time.LocalDateTime.parse(it.trim().replace(' ', 'T')).atZone(ManilaZone).toInstant().toEpochMilli()
    }.getOrNull()
}

/** A server time ("yyyy-MM-dd HH:mm:ss", Philippine time), or null when it can't be read. */
fun serverDateTime(value: String?): LocalDateTime? = value?.takeIf { it.isNotBlank() }?.let {
    runCatching { LocalDateTime.parse(it.trim().replace(' ', 'T')) }.getOrNull()
}

private val ClockFormat = DateTimeFormatter.ofPattern("h:mm a", Locale.US)

/** "2:00 PM". */
fun clockTime(time: LocalDateTime): String = time.format(ClockFormat)

/** "Today", "Tomorrow", or "Thu, Nov 5", counting days in the Philippines. */
fun formatDayLabel(date: LocalDate): String {
    val today = LocalDate.now(ManilaZone)
    return when (date) {
        today -> "Today"
        today.plusDays(1) -> "Tomorrow"
        else -> date.format(DateTimeFormatter.ofPattern("EEE, MMM d", Locale.US))
    }
}

/** "Today, 2:00 – 2:30 PM", "Tomorrow, 11:30 AM – 12:00 PM", or "Thu, Nov 5, 9:00 – 9:30 AM". */
fun formatVisitTime(start: String, end: String): String {
    val from = serverDateTime(start) ?: return formatDateTime(start)
    val until = serverDateTime(end)?.takeIf { it.toLocalDate() == from.toLocalDate() }
    val times = if (until == null) {
        clockTime(from)
    } else {
        val first = clockTime(from)
        val last = clockTime(until)
        // "2:00 – 2:30 PM" when both are in the afternoon (or both in the morning).
        if (first.takeLast(2) == last.takeLast(2)) "${first.dropLast(3)} – $last" else "$first – $last"
    }
    return "${formatDayLabel(from.toLocalDate())}, $times"
}

/** "Today, from 2:00 PM": when a visit to several offices starts. */
fun formatVisitStart(start: String): String {
    val from = serverDateTime(start) ?: return formatDateTime(start)
    return "${formatDayLabel(from.toLocalDate())}, from ${clockTime(from)}"
}

/** When a visit is: its time slot, or for a walk-in how long its pass lasts. */
fun visitTimeText(appointment: AppointmentDto): String =
    // A walk-in to a teacher has a slot, not an hour-long pass.
    if (appointment.visitType == "walk_in" && appointment.teacher == null) {
        "Walk-in · until ${formatTimeOfDay(appointment.scheduledEndAt)}"
    } else {
        formatVisitTime(appointment.scheduledStartAt, appointment.scheduledEndAt)
    }

/** "under a minute", "25 min", "2 h", or "1 h 41 min". */
fun formatDuration(millis: Long): String {
    val minutes = millis / 60_000
    return when {
        minutes < 1 -> "under a minute"
        minutes < 60 -> "$minutes min"
        minutes % 60 == 0L -> "${minutes / 60} h"
        else -> "${minutes / 60} h ${minutes % 60} min"
    }
}

/** How a finished visit ended, for the visitor. */
fun checkoutSummary(method: String?): Pair<String, String> = when (method) {
    "scan" -> "Checked out at the gate" to "Checked out"
    "guard" -> "Visit ended by Security" to "Ended"
    "left_campus" -> "Visit ended when you left the campus" to "Left campus"
    "end_of_day" -> "Visit closed at the end of the day" to "Closed"
    else -> "Visit ended" to "Ended"
}

/** Shown after a visit ends: when it started and ended, how long it took, and how it ended. */
@Composable
fun VisitSummaryCard(checkedInAt: String, completedAt: String?, checkoutMethod: String?) {
    val (title, endLabel) = checkoutSummary(checkoutMethod)
    val start = serverTimeMillis(checkedInAt)
    val end = serverTimeMillis(completedAt)
    Card(
        colors = CardDefaults.cardColors(containerColor = Color(0xFFE8F5E9)),
        border = BorderStroke(1.dp, Color(0xFFB7E1C1)),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, color = IsatuGreen)
            SummaryLine("Checked in", formatTimeOfDay(checkedInAt))
            completedAt?.let { SummaryLine(endLabel, formatTimeOfDay(it)) }
            if (start != null && end != null && end >= start) SummaryLine("Time on campus", formatDuration(end - start))
            Text("Thank you for visiting ISATU.", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun SummaryLine(label: String, value: String) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, color = MutedInk)
        Text(value, fontWeight = FontWeight.SemiBold)
    }
}

/** Label for a multi-stop visit's own status. */
fun visitStatusLabel(status: String): String = when (status) {
    "open" -> "Upcoming"
    "checked_in" -> "On campus"
    "completed" -> "Visit done"
    "closed" -> "Closed"
    else -> status.replace('_', ' ').replaceFirstChar { it.uppercase() }
}

fun statusLabel(status: String): String = when (status) {
    "pending_approval" -> "Pending approval"
    "reschedule_proposed" -> "New schedule offered"
    "checked_in" -> "Checked in"
    "window_closed", "completed" -> "Appointment done"
    "unanswered" -> "Response period ended"
    else -> status.replace('_', ' ').replaceFirstChar { it.uppercase() }
}

@Composable
fun DetailScaffold(
    title: String,
    onBack: () -> Unit,
    content: @Composable (Modifier) -> Unit,
) {
    Scaffold(
        containerColor = AppBackground,
        topBar = {
            NavyTopBar(
                title = { Text(title, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                onBack = onBack,
            )
        },
    ) { padding -> content(Modifier.padding(padding)) }
}

@Composable
fun MessageCard(message: String, error: Boolean, onDismiss: () -> Unit) {
    val background = if (error) Color(0xFFFFE8E6) else Color(0xFFE8F5E9)
    val foreground = if (error) Color(0xFF9B1C1C) else Color(0xFF166534)
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = background),
        shape = RoundedCornerShape(14.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(start = 16.dp, top = 10.dp, bottom = 10.dp, end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(message, color = foreground, modifier = Modifier.weight(1f))
            IconButton(onClick = onDismiss, modifier = Modifier.size(40.dp)) {
                Icon(Icons.Rounded.Close, contentDescription = "Dismiss", tint = foreground)
            }
        }
    }
}
