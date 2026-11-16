package ph.edu.isatu.visitor.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.data.AppointmentDto
import java.time.LocalDateTime

/*
 * Profile → Delete my account: the card at the bottom of Profile, the sheet that asks for the
 * password, and the notice while the visitor is on campus. The server decides in the end
 * (account_delete.php); these only say beforehand what it will do.
 */

private val DangerBorder = Color(0xFFF3C7C3)
private val Scrim = Color(0x800F172A)

/** Inside the campus now: a single visit checked in at the gate, or a multi-stop visit in progress. */
internal fun onCampusNow(appointments: List<AppointmentDto>): Boolean =
    appointments.any { it.visit?.status == "checked_in" || (it.visit == null && it.status == "checked_in") }

/** What deleting the account cancels: the [visits] still to come, as the Visits list counts them, and their [offices]. */
internal data class UpcomingBookings(val visits: Int, val offices: Int)

private val cancelledOnDelete = setOf("pending_approval", "approved", "reschedule_proposed")

/** Waiting, approved or offered a new time, and not over yet. Each stop of a multi-stop visit tells its own office. */
internal fun upcomingBookings(appointments: List<AppointmentDto>, now: LocalDateTime = LocalDateTime.now(ManilaZone)): UpcomingBookings {
    val cancelled = appointments.filter { appointment ->
        appointment.status in cancelledOnDelete && serverDateTime(appointment.scheduledEndAt)?.isAfter(now) != false
    }
    return UpcomingBookings(
        visits = cancelled.map { it.visit?.let { visit -> "visit-${visit.id}" } ?: "appointment-${it.id}" }.toSet().size,
        offices = cancelled.map { it.office.code.ifBlank { it.office.name } }.toSet().size,
    )
}

/** The sheet's line about bookings, or none when nothing is booked. */
internal fun upcomingLine(upcoming: UpcomingBookings): String? {
    val told = if (upcoming.offices > 1) "the offices are told" else "the office is told"
    return when {
        upcoming.visits <= 0 -> null
        upcoming.visits == 1 -> "Your upcoming booking is cancelled, and $told."
        else -> "Your ${upcoming.visits} upcoming bookings are cancelled, and $told."
    }
}

/** The last card on Profile, in red and apart from the rest so it isn't tapped by mistake. */
@Composable
internal fun DeleteAccountCard(enabled: Boolean, onDelete: () -> Unit) {
    val red = MaterialTheme.colorScheme.error
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, DangerBorder),
        shape = RoundedCornerShape(18.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Delete my account", style = MaterialTheme.typography.titleMedium, color = red)
            Text(
                "Signs you out and deletes your account. Records of past visits are kept for 1 year for campus security, then deleted.",
                color = MutedInk,
                style = MaterialTheme.typography.bodyMedium,
            )
            OutlinedButton(
                onClick = onDelete,
                enabled = enabled,
                modifier = Modifier.fillMaxWidth().height(48.dp),
                border = BorderStroke(1.5.dp, if (enabled) red else BorderSoft),
                colors = ButtonDefaults.outlinedButtonColors(contentColor = red),
            ) {
                Text("Delete my account", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/**
 * Over the whole screen, like the help sheet: what deleting does, then the password. A wrong
 * password, or the server saying no, shows under the field and the sheet stays open.
 */
@Composable
internal fun BoxScope.DeleteAccountSheet(
    upcoming: UpcomingBookings,
    busy: Boolean,
    error: String?,
    onDelete: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    var password by rememberSaveable { mutableStateOf("") }
    var showPassword by rememberSaveable { mutableStateOf(false) }
    val red = MaterialTheme.colorScheme.error
    BackHandler(onBack = onDismiss)
    Box(
        Modifier.matchParentSize().background(Scrim)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, role = Role.Button, onClickLabel = "Close", onClick = onDismiss),
    )
    Surface(
        modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding(),
        shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp),
        color = Color.White,
    ) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).navigationBarsPadding()
                .padding(start = 20.dp, end = 20.dp, top = 22.dp, bottom = 12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Delete your account?", style = MaterialTheme.typography.titleLarge, color = Ink)
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                listOfNotNull(
                    "You're signed out, and your account, saved phones and notifications are deleted.",
                    upcomingLine(upcoming),
                    "Records of past visits stay for 1 year for campus security, then they're deleted.",
                    "This can't be undone. To visit again, create a new account.",
                ).forEach { SheetPoint(it) }
            }
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text("Enter your password to confirm", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold, color = Ink)
                PasswordField(
                    value = password,
                    onValueChange = { password = it },
                    visible = showPassword,
                    onToggle = { showPassword = !showPassword },
                    label = "Password",
                    imeAction = ImeAction.Done,
                    onImeAction = { onDelete(password) },
                    autofill = ContentType.Password,
                )
            }
            error?.let { Text(it, color = red, style = MaterialTheme.typography.bodyMedium) }
            Button(
                onClick = { onDelete(password) },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape = RoundedCornerShape(14.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = red,
                    contentColor = Color.White,
                    disabledContainerColor = red.copy(alpha = 0.6f),
                    disabledContentColor = Color.White,
                ),
            ) {
                if (busy) {
                    CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                    Spacer(Modifier.width(10.dp))
                    Text("Deleting…", fontWeight = FontWeight.SemiBold)
                } else {
                    Text("Delete account", fontWeight = FontWeight.SemiBold)
                }
            }
            TextButton(onClick = onDismiss, enabled = !busy, modifier = Modifier.align(Alignment.CenterHorizontally)) {
                Text("Cancel", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun SheetPoint(text: String) {
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.padding(top = 8.dp).size(6.dp).background(MutedInk, CircleShape))
        Text(text, color = Ink, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
    }
}

/** Delete tapped while checked in: the account can go once the visit ends, as the server would say. */
@Composable
internal fun BoxScope.OnCampusDeleteNotice(onDismiss: () -> Unit) {
    BackHandler(onBack = onDismiss)
    Box(
        Modifier.matchParentSize().background(Scrim)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, role = Role.Button, onClickLabel = "Close", onClick = onDismiss),
    )
    Surface(
        modifier = Modifier.align(Alignment.Center).padding(horizontal = 24.dp).fillMaxWidth(),
        shape = RoundedCornerShape(24.dp),
        color = Color.White,
    ) {
        Column(
            Modifier.padding(start = 22.dp, end = 12.dp, top = 22.dp, bottom = 8.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text("You're on campus right now", style = MaterialTheme.typography.titleLarge, color = Ink)
            Text(
                "Your account can be deleted after your visit ends. Have your pass scanned at the gate when you leave, then try again.",
                color = Ink,
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.padding(end = 10.dp),
            )
            TextButton(onClick = onDismiss, modifier = Modifier.align(Alignment.End)) {
                Text("OK", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}
