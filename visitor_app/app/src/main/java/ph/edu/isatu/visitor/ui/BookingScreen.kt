package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.BorderStroke
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
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.SelectableDates
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.data.AvailabilitySlotDto
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

private val visitPurposes = listOf(
    "Academic Concern",
    "Office Transaction",
    "Technical Support",
    "Document Request",
    "Official Business",
    "Other",
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BookingScreen(state: VisitorUiState, viewModel: AppViewModel) {
    var visitType by remember { mutableStateOf("walk_in") }
    var officeCode by remember { mutableStateOf("") }
    var date by remember { mutableStateOf("") }
    var selectedSlot by remember { mutableStateOf<AvailabilitySlotDto?>(null) }
    var purpose by remember { mutableStateOf("") }
    var subject by remember { mutableStateOf("") }
    var details by remember { mutableStateOf("") }
    var showDatePicker by remember { mutableStateOf(false) }
    val multiStop = remember { MultiStopDraft() }
    var multipleOffices by remember { mutableStateOf(false) }

    // Rebooking after "None of these work": keep the request's details, pick a new time.
    LaunchedEffect(state.bookingPrefill) {
        state.bookingPrefill?.let { prefill ->
            visitType = "appointment"
            multipleOffices = false
            officeCode = prefill.officeCode
            purpose = prefill.purpose
            subject = prefill.subject
            details = prefill.details
            date = ""
            viewModel.consumeBookingPrefill()
        }
    }

    LaunchedEffect(visitType, officeCode, date) {
        selectedSlot = null
        viewModel.clearAvailability()
        if (visitType == "appointment" && officeCode.isNotBlank() && date.isNotBlank()) {
            viewModel.loadAvailability(officeCode, date)
        }
    }

    LaunchedEffect(date) { multiStop.clearSlots() }
    val multiStopOffices = multiStop.stops.map { it.officeCode }
    LaunchedEffect(visitType, multipleOffices, date, multiStopOffices) {
        if (visitType != "appointment" || !multipleOffices || date.isBlank()) return@LaunchedEffect
        multiStopOffices.filter { it.isNotBlank() }.distinct().forEach { code ->
            if (state.stopAvailability[stopAvailabilityKey(code, date)] == null) {
                viewModel.loadStopAvailability(code, date)
            }
        }
    }

    val selectedOffice = state.offices.firstOrNull { it.code == officeCode }
    val commonFieldsReady = officeCode.isNotBlank() && purpose.isNotBlank() && subject.isNotBlank()
    val canSubmit = commonFieldsReady && !state.busy &&
        (visitType == "walk_in" || selectedSlot != null)

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(horizontal = 22.dp, vertical = 20.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Column(
                modifier = Modifier.fillMaxWidth(),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(
                    "VISITOR REGISTRATION",
                    style = MaterialTheme.typography.headlineMedium,
                    color = IsatuBlueDark,
                    textAlign = TextAlign.Center,
                )
                Text("Choose how you plan to visit the campus.", color = MutedInk, textAlign = TextAlign.Center)
            }
        }

        item {
            Text("Visitor type", style = MaterialTheme.typography.titleMedium)
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    VisitTypeButton(
                        text = "Walk-in",
                        selected = visitType == "walk_in",
                        onClick = { visitType = "walk_in" },
                        modifier = Modifier.weight(1f),
                    )
                    VisitTypeButton(
                        text = "Appointment",
                        selected = visitType == "appointment",
                        onClick = { visitType = "appointment" },
                        modifier = Modifier.weight(1f),
                    )
                }
                Row(
                    modifier = Modifier.fillMaxWidth().toggleable(
                        value = multipleOffices,
                        role = Role.Switch,
                        onValueChange = { multipleOffices = it },
                    ).padding(vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text("Visiting more than one office?", fontWeight = FontWeight.SemiBold)
                        Text(
                            "Add up to three offices and use one pass for the whole trip.",
                            color = MutedInk,
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                    Switch(checked = multipleOffices, onCheckedChange = null)
                }
            }
        }

        if (multipleOffices) {
            val walkIn = visitType == "walk_in"
            multiStopBookingItems(
                state = state,
                draft = multiStop,
                walkIn = walkIn,
                date = date,
                purposes = visitPurposes,
                onPickDate = { showDatePicker = true },
                onSubmit = { viewModel.createVisit(visitType, multiStop.toRequests(walkIn)) },
            )
            item { Spacer(Modifier.height(12.dp)) }
            return@LazyColumn
        }

        item {
            SelectionField(
                label = "Destination",
                selectedText = selectedOffice?.name.orEmpty(),
                placeholder = "Select an office",
                options = state.offices.map { it.code to it.name },
                enabledOptions = state.offices.filter { it.acceptingVisitors }.map { it.code }.toSet(),
                onSelect = { officeCode = it },
            )
            selectedOffice?.location?.takeIf { it.isNotBlank() }?.let { location ->
                Text(location, style = MaterialTheme.typography.bodySmall, color = MutedInk, modifier = Modifier.padding(top = 6.dp))
            }
            selectedOffice?.takeIf { !it.acceptingVisitors }?.let {
                Text(
                    it.unavailableReason.ifBlank { "This office is not accepting visitors right now." },
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.padding(top = 6.dp),
                )
            }
        }

        item {
            SelectionField(
                label = "Reason for visiting",
                selectedText = purpose,
                placeholder = "Select a purpose",
                options = visitPurposes.map { it to it },
                onSelect = { purpose = it },
            )
        }

        item {
            OutlinedTextField(
                value = subject,
                onValueChange = { subject = it.take(150) },
                label = { Text("Subject / concern") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                shape = RoundedCornerShape(10.dp),
            )
        }

        item {
            OutlinedTextField(
                value = details,
                onValueChange = { details = it.take(3000) },
                label = { Text("Additional details (optional)") },
                modifier = Modifier.fillMaxWidth(),
                minLines = 3,
                shape = RoundedCornerShape(10.dp),
            )
        }

        if (visitType == "appointment") {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    border = BorderStroke(1.dp, BorderSoft),
                    shape = RoundedCornerShape(14.dp),
                ) {
                    Column(
                        Modifier.fillMaxWidth().padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        Text("Appointment schedule", style = MaterialTheme.typography.titleMedium)
                        Text(
                            if (officeCode.isBlank()) "Select a destination first." else "Choose a date to see that office's available times.",
                            color = MutedInk,
                            style = MaterialTheme.typography.bodySmall,
                        )
                        OutlinedButton(
                            onClick = { showDatePicker = true },
                            enabled = officeCode.isNotBlank(),
                            modifier = Modifier.fillMaxWidth().height(54.dp),
                            shape = RoundedCornerShape(10.dp),
                        ) {
                            Icon(Icons.Rounded.CalendarMonth, contentDescription = null)
                            Spacer(Modifier.size(8.dp))
                            Text(if (date.isBlank()) "Choose appointment date" else formatBookingDate(date))
                        }
                    }
                }
            }
            if (date.isNotBlank() && state.busy && state.availability == null) {
                item {
                    Card(
                        colors = CardDefaults.cardColors(containerColor = BlueSurface),
                        border = BorderStroke(1.dp, Color(0xFFBED6FA)),
                        shape = RoundedCornerShape(12.dp),
                    ) {
                        Row(Modifier.fillMaxWidth().padding(15.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Rounded.Schedule, contentDescription = null, tint = IsatuBlue)
                            Spacer(Modifier.size(10.dp))
                            Text("Checking available times…", color = IsatuBlueDark)
                        }
                    }
                }
            }
            state.availability?.let { availability ->
                if (availability.slots.isEmpty()) {
                    item { EmptyState("No available time", availability.message ?: "Try another date or office.") }
                } else {
                    item {
                        Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                            Text("Available times", style = MaterialTheme.typography.titleMedium)
                            Text("Philippine Time • select one slot", color = MutedInk, style = MaterialTheme.typography.bodySmall)
                        }
                    }
                    items(availability.slots.chunked(2), key = { row -> row.first().scheduledStartAt }) { slotRow ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            slotRow.forEach { slot ->
                                val selected = selectedSlot?.scheduledStartAt == slot.scheduledStartAt
                                Card(
                                    modifier = Modifier.weight(1f).selectable(selected) { selectedSlot = slot },
                                    colors = CardDefaults.cardColors(containerColor = if (selected) BlueSurface else Color.White),
                                    border = BorderStroke(1.dp, if (selected) IsatuBlue else BorderSoft),
                                    shape = RoundedCornerShape(12.dp),
                                ) {
                                    Column(Modifier.fillMaxWidth().padding(13.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                        Row(verticalAlignment = Alignment.CenterVertically) {
                                            RadioButton(selected = selected, onClick = { selectedSlot = slot })
                                            Text(
                                                formatTimeRange(slot.scheduledStartAt, slot.scheduledEndAt),
                                                fontWeight = FontWeight.SemiBold,
                                                modifier = Modifier.weight(1f),
                                            )
                                        }
                                        Text(
                                            if (slot.remainingCapacity == 1) "1 space left" else "${slot.remainingCapacity} spaces left",
                                            color = MutedInk,
                                            style = MaterialTheme.typography.bodySmall,
                                        )
                                    }
                                }
                            }
                            if (slotRow.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }
                    selectedSlot?.let { slot ->
                        item {
                            Card(
                                colors = CardDefaults.cardColors(containerColor = Color(0xFFEAF8F0)),
                                border = BorderStroke(1.dp, Color(0xFF9ED8B5)),
                                shape = RoundedCornerShape(12.dp),
                            ) {
                                Row(Modifier.fillMaxWidth().padding(15.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Rounded.Schedule, contentDescription = null, tint = Color(0xFF087443))
                                    Spacer(Modifier.size(10.dp))
                                    Column {
                                        Text("Selected appointment", fontWeight = FontWeight.SemiBold, color = Color(0xFF087443))
                                        Text("${formatBookingDate(date)} • ${formatTimeRange(slot.scheduledStartAt, slot.scheduledEndAt)}", color = Ink)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } else {
            item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = BlueSurface),
                    border = BorderStroke(1.dp, Color(0xFFBED6FA)),
                    shape = RoundedCornerShape(12.dp),
                ) {
                    Text(
                        "Your walk-in QR pass is created immediately. Present it with a valid ID at Security. The pass expires if it is not used within one hour.",
                        modifier = Modifier.padding(15.dp),
                        color = IsatuBlueDark,
                    )
                }
            }
        }

        item {
            PrimaryButton(
                text = if (visitType == "walk_in") "Create walk-in pass" else "Submit appointment request",
                onClick = {
                    viewModel.createAppointment(
                        visitType = visitType,
                        officeCode = officeCode,
                        purpose = purpose,
                        subject = subject,
                        details = details,
                        start = selectedSlot?.scheduledStartAt.orEmpty(),
                        end = selectedSlot?.scheduledEndAt.orEmpty(),
                    )
                },
                modifier = Modifier.fillMaxWidth(),
                enabled = canSubmit,
            )
        }
        item { Spacer(Modifier.height(12.dp)) }
    }

    if (showDatePicker) {
        val manilaZone = ZoneId.of("Asia/Manila")
        val today = remember { LocalDate.now(manilaZone) }
        val lastBookableDate = remember(today) { today.plusDays(180) }
        val allowedDates = remember(today, lastBookableDate) {
            object : SelectableDates {
                override fun isSelectableDate(utcTimeMillis: Long): Boolean {
                    val candidate = Instant.ofEpochMilli(utcTimeMillis).atZone(ZoneId.of("UTC")).toLocalDate()
                    return !candidate.isBefore(today) && !candidate.isAfter(lastBookableDate)
                }

                override fun isSelectableYear(year: Int): Boolean = year in today.year..lastBookableDate.year
            }
        }
        val initialDate = remember(date, today) {
            runCatching { date.takeIf(String::isNotBlank)?.let(LocalDate::parse) }.getOrNull() ?: today.plusDays(1)
        }
        val pickerState = androidx.compose.material3.rememberDatePickerState(
            initialSelectedDateMillis = initialDate.atStartOfDay(ZoneId.of("UTC")).toInstant().toEpochMilli(),
            selectableDates = allowedDates,
        )
        DatePickerDialog(
            onDismissRequest = { showDatePicker = false },
            confirmButton = {
                TextButton(
                    enabled = pickerState.selectedDateMillis != null,
                    onClick = {
                        pickerState.selectedDateMillis?.let {
                            date = Instant.ofEpochMilli(it).atZone(ZoneId.of("UTC")).toLocalDate().toString()
                        }
                        showDatePicker = false
                    },
                ) { Text("Use date") }
            },
            dismissButton = { TextButton(onClick = { showDatePicker = false }) { Text("Cancel") } },
        ) { DatePicker(pickerState) }
    }
}

@Composable
private fun VisitTypeButton(text: String, selected: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.height(52.dp),
        shape = RoundedCornerShape(10.dp),
        border = BorderStroke(1.dp, if (selected) IsatuBlue else BorderSoft),
    ) {
        RadioButton(selected = selected, onClick = null)
        Text(text, color = if (selected) IsatuBlueDark else Ink)
    }
}

@Composable
internal fun SelectionField(
    label: String,
    selectedText: String,
    placeholder: String,
    options: List<Pair<String, String>>,
    enabledOptions: Set<String> = options.map { it.first }.toSet(),
    onSelect: (String) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = MutedInk)
        Box(Modifier.fillMaxWidth()) {
            OutlinedButton(
                onClick = { expanded = true },
                modifier = Modifier.fillMaxWidth().height(54.dp),
                shape = RoundedCornerShape(10.dp),
                contentPadding = PaddingValues(horizontal = 14.dp),
            ) {
                Text(
                    selectedText.ifBlank { placeholder },
                    modifier = Modifier.weight(1f),
                    color = if (selectedText.isBlank()) MutedInk else Ink,
                    textAlign = TextAlign.Start,
                )
                Icon(Icons.Rounded.KeyboardArrowDown, contentDescription = "Open $label")
            }
            DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
                options.forEach { (value, text) ->
                    DropdownMenuItem(
                        text = {
                            Column {
                                Text(text)
                                if (value !in enabledOptions) {
                                    Text("Not accepting visitors", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                                }
                            }
                        },
                        enabled = value in enabledOptions,
                        onClick = {
                            onSelect(value)
                            expanded = false
                        },
                    )
                }
            }
        }
    }
}

private fun formatBookingDate(value: String): String = runCatching {
    LocalDate.parse(value).format(DateTimeFormatter.ofPattern("EEEE, MMM d, yyyy", Locale.US))
}.getOrDefault(value)

private fun formatTimeRange(start: String, end: String): String {
    fun time(value: String): String = runCatching {
        val input = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
        val output = java.text.SimpleDateFormat("h:mm a", java.util.Locale.US)
        output.format(requireNotNull(input.parse(value)))
    }.getOrDefault(value)
    return "${time(start)} – ${time(end)}"
}
