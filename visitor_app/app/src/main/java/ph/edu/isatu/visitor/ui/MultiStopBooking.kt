package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.data.AvailabilitySlotDto
import ph.edu.isatu.visitor.data.VisitStopRequest
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter

const val MULTI_STOP_MIN = 2
const val MULTI_STOP_MAX = 3

/** Server rule: leave this many minutes between stops to walk between offices. */
private const val STOP_GAP_MINUTES = 10L

data class StopDraft(
    val key: Int,
    val officeCode: String = "",
    val purpose: String = "",
    val subject: String = "",
    val details: String = "",
    val slot: AvailabilitySlotDto? = null,
)

/** Form state for a multi-stop visit; stops share the visit date chosen on the booking screen. */
class MultiStopDraft {
    private var nextKey = 0
    val stops: SnapshotStateList<StopDraft> = mutableStateListOf(StopDraft(nextKey++), StopDraft(nextKey++))

    fun add() {
        if (stops.size < MULTI_STOP_MAX) stops.add(StopDraft(nextKey++))
    }

    fun remove(key: Int) {
        if (stops.size > MULTI_STOP_MIN) stops.removeAll { it.key == key }
    }

    fun update(key: Int, change: (StopDraft) -> StopDraft) {
        val index = stops.indexOfFirst { it.key == key }
        if (index >= 0) stops[index] = change(stops[index])
    }

    /** A new date invalidates every chosen time. */
    fun clearSlots() {
        stops.indices.forEach { stops[it] = stops[it].copy(slot = null) }
    }

    /** Walk-in stops have no time; appointment stops each need a chosen slot. */
    fun isComplete(walkIn: Boolean): Boolean = stops.all {
        it.officeCode.isNotBlank() && it.purpose.isNotBlank() && it.subject.isNotBlank() && (walkIn || it.slot != null)
    }

    fun toRequests(walkIn: Boolean): List<VisitStopRequest> = stops.map { stop ->
        VisitStopRequest(
            officeCode = stop.officeCode,
            purpose = stop.purpose.trim(),
            subject = stop.subject.trim(),
            additionalDetails = stop.details.trim(),
            scheduledStartAt = if (walkIn) "" else stop.slot?.scheduledStartAt.orEmpty(),
            scheduledEndAt = if (walkIn) "" else stop.slot?.scheduledEndAt.orEmpty(),
        )
    }
}

private val serverTime = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")

private fun parseServerTime(value: String): LocalDateTime? = runCatching { LocalDateTime.parse(value, serverTime) }.getOrNull()

/** True when [slot] keeps the walking gap from every time already chosen for the other stops. */
private fun fitsAroundOtherStops(slot: AvailabilitySlotDto, others: List<AvailabilitySlotDto>): Boolean {
    val start = parseServerTime(slot.scheduledStartAt) ?: return false
    val end = parseServerTime(slot.scheduledEndAt) ?: return false
    return others.all { other ->
        val otherStart = parseServerTime(other.scheduledStartAt) ?: return@all true
        val otherEnd = parseServerTime(other.scheduledEndAt) ?: return@all true
        !start.isBefore(otherEnd.plusMinutes(STOP_GAP_MINUTES)) || !end.isAfter(otherStart.minusMinutes(STOP_GAP_MINUTES))
    }
}

/**
 * Items for the multi-office section of the booking screen. For a walk-in the visitor
 * only lists the offices in the order they will go; for appointments [date] is the
 * shared visit date and [onPickDate] opens the booking screen's date picker.
 */
fun LazyListScope.multiStopBookingItems(
    state: VisitorUiState,
    draft: MultiStopDraft,
    walkIn: Boolean,
    date: String,
    purposes: List<String>,
    onPickDate: () -> Unit,
    onSubmit: () -> Unit,
) {
    item {
        Card(
            colors = CardDefaults.cardColors(containerColor = BlueSurface),
            border = BorderStroke(1.dp, Color(0xFFBED6FA)),
            shape = RoundedCornerShape(12.dp),
        ) {
            Text(
                if (walkIn) {
                    "List the offices in the order you will visit them. One walk-in pass covering every office is created immediately. " +
                        "Present it with a valid ID at Security within one hour."
                } else {
                    "Visit two or three offices in one trip. Each office reviews its own stop, and you get one visitor pass for the whole visit."
                },
                modifier = Modifier.padding(15.dp),
                color = IsatuBlueDark,
            )
        }
    }
    if (!walkIn) {
        item {
            OutlinedButton(
                onClick = onPickDate,
                modifier = Modifier.fillMaxWidth().height(54.dp),
                shape = RoundedCornerShape(10.dp),
            ) {
                Icon(Icons.Rounded.CalendarMonth, contentDescription = null)
                Spacer(Modifier.size(8.dp))
                Text(if (date.isBlank()) "Choose visit date" else formatBookingDay(date))
            }
        }
    }
    draft.stops.forEachIndexed { index, stop ->
        item(key = "stop-${stop.key}") {
            StopEditor(
                number = index + 1,
                stop = stop,
                state = state,
                draft = draft,
                walkIn = walkIn,
                date = date,
                purposes = purposes,
            )
        }
    }
    if (draft.stops.size < MULTI_STOP_MAX) {
        item {
            TextButton(onClick = draft::add) {
                Icon(Icons.Rounded.Add, contentDescription = null)
                Spacer(Modifier.size(6.dp))
                Text("Add another office")
            }
        }
    }
    // Walk-ins follow the listed order; appointments follow their chosen times.
    val routeLines = if (walkIn) {
        draft.stops.filter { it.officeCode.isNotBlank() }.mapIndexed { index, stop -> "${index + 1}. ${officeName(state, stop.officeCode)}" }
    } else {
        draft.stops.mapNotNull { stop -> stop.slot?.let { stop to it } }
            .sortedBy { it.second.scheduledStartAt }
            .map { (stop, slot) -> "${formatTimeOfDay(slot.scheduledStartAt)} • ${officeName(state, stop.officeCode)}" }
    }
    if (routeLines.isNotEmpty()) {
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color(0xFFEAF8F0)),
                border = BorderStroke(1.dp, Color(0xFF9ED8B5)),
                shape = RoundedCornerShape(12.dp),
            ) {
                Column(Modifier.fillMaxWidth().padding(15.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text("Your route", fontWeight = FontWeight.SemiBold, color = Color(0xFF087443))
                    routeLines.forEach { Text(it, color = Ink) }
                }
            }
        }
    }
    item {
        PrimaryButton(
            text = if (walkIn) "Create walk-in pass" else "Submit visit request",
            onClick = onSubmit,
            modifier = Modifier.fillMaxWidth(),
            enabled = !state.busy && (walkIn || date.isNotBlank()) && draft.isComplete(walkIn),
        )
    }
}

private fun officeName(state: VisitorUiState, code: String): String =
    state.offices.firstOrNull { it.code == code }?.name ?: code

@Composable
private fun StopEditor(
    number: Int,
    stop: StopDraft,
    state: VisitorUiState,
    draft: MultiStopDraft,
    walkIn: Boolean,
    date: String,
    purposes: List<String>,
) {
    val takenOffices = draft.stops.filter { it.key != stop.key }.map { it.officeCode }.toSet()
    val otherSlots = draft.stops.filter { it.key != stop.key }.mapNotNull { it.slot }
    val availability = if (stop.officeCode.isNotBlank() && date.isNotBlank()) {
        state.stopAvailability[stopAvailabilityKey(stop.officeCode, date)]
    } else null
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(14.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Stop $number", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                if (draft.stops.size > MULTI_STOP_MIN) {
                    TextButton(onClick = { draft.remove(stop.key) }) {
                        Text("Remove", color = MaterialTheme.colorScheme.error)
                    }
                }
            }
            SelectionField(
                label = "Office",
                selectedText = state.offices.firstOrNull { it.code == stop.officeCode }?.name.orEmpty(),
                placeholder = "Select an office",
                options = state.offices.map { it.code to it.name },
                enabledOptions = state.offices.filter { it.acceptingVisitors && it.code !in takenOffices }.map { it.code }.toSet(),
                onSelect = { code -> draft.update(stop.key) { it.copy(officeCode = code, slot = null) } },
            )
            state.offices.firstOrNull { it.code == stop.officeCode }?.location?.takeIf { it.isNotBlank() }?.let { location ->
                Text(location, style = MaterialTheme.typography.bodySmall, color = MutedInk)
            }
            SelectionField(
                label = "Reason for visiting",
                selectedText = stop.purpose,
                placeholder = "Select a purpose",
                options = purposes.map { it to it },
                onSelect = { purpose -> draft.update(stop.key) { it.copy(purpose = purpose) } },
            )
            OutlinedTextField(
                value = stop.subject,
                onValueChange = { value -> draft.update(stop.key) { it.copy(subject = value.take(150)) } },
                label = { Text("Subject / concern") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                shape = RoundedCornerShape(10.dp),
            )
            OutlinedTextField(
                value = stop.details,
                onValueChange = { value -> draft.update(stop.key) { it.copy(details = value.take(3000)) } },
                label = { Text("Additional details (optional)") },
                modifier = Modifier.fillMaxWidth(),
                minLines = 2,
                shape = RoundedCornerShape(10.dp),
            )
            when {
                walkIn -> Unit
                stop.officeCode.isBlank() || date.isBlank() -> Text(
                    "Choose the visit date and office to see available times.",
                    color = MutedInk,
                    style = MaterialTheme.typography.bodySmall,
                )
                availability == null -> Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Schedule, contentDescription = null, tint = IsatuBlue)
                    Spacer(Modifier.size(8.dp))
                    Text("Checking available times…", color = IsatuBlueDark)
                }
                else -> {
                    val fitting = availability.slots.filter { fitsAroundOtherStops(it, otherSlots) }
                    if (fitting.isEmpty()) {
                        Text(
                            availability.message
                                ?: "No time fits around your other stops. Try another office or date.",
                            color = MaterialTheme.colorScheme.error,
                            style = MaterialTheme.typography.bodySmall,
                        )
                    } else {
                        Text(
                            "Times that leave $STOP_GAP_MINUTES minutes around your other stops",
                            color = MutedInk,
                            style = MaterialTheme.typography.bodySmall,
                        )
                        fitting.chunked(3).forEach { row ->
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                row.forEach { slot ->
                                    val selected = stop.slot?.scheduledStartAt == slot.scheduledStartAt
                                    Card(
                                        modifier = Modifier.weight(1f).selectable(selected) {
                                            draft.update(stop.key) { it.copy(slot = slot) }
                                        },
                                        colors = CardDefaults.cardColors(containerColor = if (selected) BlueSurface else Color.White),
                                        border = BorderStroke(1.dp, if (selected) IsatuBlue else BorderSoft),
                                        shape = RoundedCornerShape(10.dp),
                                    ) {
                                        Text(
                                            formatTimeOfDay(slot.scheduledStartAt),
                                            modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
                                            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                                            color = if (selected) IsatuBlueDark else Ink,
                                            textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                                        )
                                    }
                                }
                                repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun formatBookingDay(value: String): String = runCatching {
    java.time.LocalDate.parse(value).format(DateTimeFormatter.ofPattern("EEEE, MMM d, yyyy", java.util.Locale.US))
}.getOrDefault(value)
