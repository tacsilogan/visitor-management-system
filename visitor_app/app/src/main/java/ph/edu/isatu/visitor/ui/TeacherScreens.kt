package ph.edu.isatu.visitor.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.data.AvailabilitySlotDto
import ph.edu.isatu.visitor.data.OfficeSummaryDto
import ph.edu.isatu.visitor.data.TeacherDto

/* The screens of a visit to a teacher (TEACHER_VISITS.md). */

private val AvailableGreen = IsatuGreen

@Composable
private fun TeacherAvatar(name: String) {
    Box(
        Modifier.size(44.dp).background(BlueSurface, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Text(teacherInitials(name), color = IsatuBlueDark, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.bodyMedium)
    }
}

/** Finding a teacher: by name, or by course. Only teachers who saved their hours are listed. */
@Composable
internal fun FindTeacherContent(
    teachers: List<TeacherDto>,
    loading: Boolean,
    onPick: (TeacherDto) -> Unit,
    onUseOffice: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    /** Students book teachers only, so they aren't offered an office instead. */
    allowOffice: Boolean = true,
) {
    BackHandler(onBack = onBack)
    var query by remember { mutableStateOf("") }
    var course by remember { mutableStateOf("") }
    var department by remember { mutableStateOf("") }
    // Every department's teachers: a student may be sent to a teacher they don't know yet.
    val departments = teachers.map { it.department }.filter { it.isNotBlank() }.distinct()
    val courses = teachers.filter { department.isBlank() || it.department == department }
        .map { it.course }.filter { it.isNotBlank() }.distinct()
    val matches = filterTeachers(teachers, query, course, department)
    val departmentNames = teachers.map { it.departmentName }.filter { it.isNotBlank() }.distinct()
    LazyColumn(
        modifier = modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 18.dp, end = 18.dp, top = 8.dp, bottom = 20.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back to the form")
                }
                Text("Find a teacher", style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
            }
        }
        item {
            OutlinedTextField(
                value = query,
                onValueChange = { query = it.take(60) },
                placeholder = { Text("Search by name") },
                leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(12.dp),
            )
        }
        if (departments.size > 1) {
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.horizontalScroll(rememberScrollState())) {
                    (listOf("") + departments).forEach { option ->
                        FilterChip(
                            selected = department == option,
                            onClick = {
                                department = option
                                course = ""
                            },
                            label = { Text(option.ifBlank { "All departments" }) },
                            colors = FilterChipDefaults.filterChipColors(selectedContainerColor = IsatuBlueDark, selectedLabelColor = Color.White, labelColor = Ink),
                            shape = RoundedCornerShape(50),
                        )
                    }
                }
            }
        }
        if (courses.size > 1 && (departments.size <= 1 || department.isNotBlank())) {
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.horizontalScroll(rememberScrollState())) {
                    (listOf("") + courses).forEach { option ->
                        FilterChip(
                            selected = course == option,
                            onClick = { course = option },
                            label = { Text(option.ifBlank { "All" }) },
                            colors = FilterChipDefaults.filterChipColors(selectedContainerColor = IsatuBlueDark, selectedLabelColor = Color.White, labelColor = Ink),
                            shape = RoundedCornerShape(50),
                        )
                    }
                }
            }
        }
        departmentNames.singleOrNull()?.let { name ->
            item { Text(name, style = MaterialTheme.typography.bodySmall, color = MutedInk) }
        }
        val showDepartment = departments.size > 1
        when {
            loading && teachers.isEmpty() -> item { Text("Loading teachers…", color = MutedInk) }
            matches.isEmpty() -> item {
                Text(
                    if (teachers.isEmpty()) "No teachers are taking visits yet." else "No teacher with that name.",
                    color = MutedInk,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(vertical = 14.dp),
                )
            }
            else -> item {
                Card(
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    border = BorderStroke(1.dp, BorderSoft),
                    shape = RoundedCornerShape(18.dp),
                ) {
                    matches.forEachIndexed { index, teacher ->
                        if (index > 0) HorizontalDivider(color = Color(0xFFEDF1F6))
                        TeacherRow(teacher, showDepartment) { onPick(teacher) }
                    }
                }
            }
        }
        if (allowOffice) {
            item {
                TextButton(onClick = onUseOffice, modifier = Modifier.fillMaxWidth()) {
                    Text("Can't find them? Book with an office", fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun TeacherRow(teacher: TeacherDto, showDepartment: Boolean = false, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        TeacherAvatar(teacher.name)
        Column(Modifier.weight(1f)) {
            Text(teacher.name, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
            TeacherStateLine(teacher, withCourse = true, withDepartment = showDepartment)
        }
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MutedInk)
    }
}

@Composable
private fun TeacherStateLine(teacher: TeacherDto, withCourse: Boolean, withDepartment: Boolean = false) {
    val free = teacher.availableNow
    // "IT, Available now", or "IT, CCI. Available now" when several departments are listed.
    val place = when {
        !withCourse || teacher.course.isBlank() -> ""
        withDepartment && teacher.department.isNotBlank() -> "${teacher.course}, ${teacher.department}. "
        else -> teacher.course + ", "
    }
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        Box(Modifier.padding(top = 5.dp).size(8.dp).background(if (free) AvailableGreen else Color(0xFFA7B1BF), CircleShape))
        Text(
            place + teacherAvailability(teacher),
            style = MaterialTheme.typography.bodySmall,
            color = if (free) AvailableGreen else MutedInk,
            fontWeight = if (free) FontWeight.SemiBold else FontWeight.Normal,
        )
    }
}

/** The empty field on the form, before a teacher is chosen. */
@Composable
internal fun FindTeacherButton(onClick: () -> Unit) {
    OutlinedButton(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth().height(54.dp),
        shape = RoundedCornerShape(12.dp),
    ) {
        Icon(Icons.Rounded.Search, contentDescription = null, tint = MutedInk)
        Spacer(Modifier.width(10.dp))
        Text("Find a teacher by name", color = MutedInk, modifier = Modifier.weight(1f))
    }
}

/** The chosen teacher on the form. */
@Composable
internal fun TeacherChoiceCard(teacher: TeacherDto, onChange: () -> Unit) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, IsatuBlue),
        shape = RoundedCornerShape(16.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(start = 14.dp, top = 12.dp, bottom = 12.dp, end = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            TeacherAvatar(teacher.name)
            Column(Modifier.weight(1f)) {
                Text(teacher.name, style = MaterialTheme.typography.titleMedium)
                Text(
                    listOf(teacher.course, teacher.department).filter { it.isNotBlank() }.joinToString(", "),
                    style = MaterialTheme.typography.bodySmall,
                    color = MutedInk,
                )
                TeacherStateLine(teacher, withCourse = false)
            }
            TextButton(onClick = onChange) { Text("Change", fontWeight = FontWeight.SemiBold) }
        }
    }
}

/** A walk-in has asked the teacher. There is no pass until they answer. */
@Composable
internal fun WalkInWaitingContent(
    teacherName: String,
    subject: String,
    secondsLeft: Int,
    busy: Boolean,
    onCancel: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier.fillMaxSize().padding(18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Card(
            colors = CardDefaults.cardColors(containerColor = Color.White),
            border = BorderStroke(1.dp, BorderSoft),
            shape = RoundedCornerShape(22.dp),
        ) {
            Column(
                Modifier.fillMaxWidth().padding(horizontal = 18.dp, vertical = 20.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Box(Modifier.background(Color(0xFFFFF4CC), RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp)) {
                    Text("Waiting for an answer", color = Color(0xFF9A6700), style = MaterialTheme.typography.labelLarge)
                }
                Text(teacherName, style = MaterialTheme.typography.titleLarge, textAlign = TextAlign.Center)
                if (subject.isNotBlank()) {
                    Text(subject, style = MaterialTheme.typography.bodyMedium, color = MutedInk, textAlign = TextAlign.Center)
                }
                Spacer(Modifier.height(6.dp))
                AnswerRing(secondsLeft)
            }
        }
        Text(
            "You have no pass yet. We'll notify you, so you can leave this screen.",
            style = MaterialTheme.typography.bodyMedium,
            color = MutedInk,
            textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth(),
        )
        TextButton(onClick = onCancel, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
            Text("Cancel request", color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun AnswerRing(secondsLeft: Int) {
    val fraction = (secondsLeft.toFloat() / WALK_IN_ANSWER_SECONDS).coerceIn(0f, 1f)
    Box(Modifier.size(148.dp), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            val stroke = 9.dp.toPx()
            val inset = stroke / 2
            val arc = Size(size.width - stroke, size.height - stroke)
            drawArc(Color(0xFFE8EEF7), 0f, 360f, false, Offset(inset, inset), arc, style = Stroke(stroke))
            drawArc(IsatuBlue, -90f, 360f * fraction, false, Offset(inset, inset), arc, style = Stroke(stroke, cap = StrokeCap.Round))
        }
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(formatCountdown(secondsLeft), style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
            Text("left to answer", style = MaterialTheme.typography.bodySmall, color = MutedInk)
        }
    }
}

/** The teacher can't see the visitor, or didn't answer. The visitor is never left with nothing to do. */
@Composable
internal fun TeacherAnswerContent(
    teacherName: String,
    pill: String,
    pillIsRed: Boolean,
    message: String,
    slots: List<AvailabilitySlotDto>,
    fallback: OfficeSummaryDto?,
    busy: Boolean,
    onPickSlot: (AvailabilitySlotDto) -> Unit,
    onMoreTimes: () -> Unit,
    onFallback: () -> Unit,
    modifier: Modifier = Modifier,
) {
    LazyColumn(
        modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, BorderSoft),
                shape = RoundedCornerShape(22.dp),
            ) {
                Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Box(
                        Modifier.background(if (pillIsRed) Color(0xFFFEE2E2) else Color(0xFFE8EEF7), RoundedCornerShape(50))
                            .padding(horizontal = 10.dp, vertical = 4.dp),
                    ) {
                        Text(pill, color = if (pillIsRed) Color(0xFFB42318) else Color(0xFF475569), style = MaterialTheme.typography.labelLarge)
                    }
                    Text(teacherName, style = MaterialTheme.typography.titleLarge)
                    Text(message, style = MaterialTheme.typography.bodyMedium, color = MutedInk)
                }
            }
        }
        if (slots.isNotEmpty()) {
            item { Text("Next free times", style = MaterialTheme.typography.titleMedium) }
            items(slots.chunked(2), key = { row -> row.first().scheduledStartAt }) { row ->
                Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    row.forEach { slot ->
                        Card(
                            modifier = Modifier.weight(1f).fillMaxHeight().clickable(enabled = !busy) { onPickSlot(slot) },
                            colors = CardDefaults.cardColors(containerColor = Color.White),
                            border = BorderStroke(1.dp, BorderSoft),
                            shape = RoundedCornerShape(12.dp),
                        ) {
                            Box(
                                Modifier.fillMaxSize().padding(vertical = 14.dp, horizontal = 4.dp),
                                contentAlignment = Alignment.Center,
                            ) {
                                Text(
                                    formatNextFree(slot.scheduledStartAt),
                                    fontWeight = FontWeight.SemiBold,
                                    style = MaterialTheme.typography.bodyMedium,
                                    textAlign = TextAlign.Center,
                                )
                            }
                        }
                    }
                    if (row.size == 1) Spacer(Modifier.weight(1f))
                }
            }
            item {
                TextButton(onClick = onMoreTimes, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                    Text("See more times", fontWeight = FontWeight.SemiBold)
                }
            }
        }
        fallback?.let { office ->
            item {
                OutlinedButton(
                    onClick = onFallback,
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = RoundedCornerShape(12.dp),
                    colors = ButtonDefaults.outlinedButtonColors(contentColor = IsatuBlue),
                ) { Text("Go to the ${office.name} instead", fontWeight = FontWeight.SemiBold) }
            }
        }
    }
}
