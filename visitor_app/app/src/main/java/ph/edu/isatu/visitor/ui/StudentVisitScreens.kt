package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Directions
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.navigation.OfficeDirections

/*
 * An ISATU student's accepted visit (SCHOOL_ACCOUNTS.md). There is no pass and no gate scan: the room
 * shows once the teacher accepts, the written directions lead there from the building door, and
 * "I'm here" tells the teacher they have arrived. Nothing about the student's location is sent.
 */

/** "IT Faculty Room, 2nd floor, CCI Building": where the visit is, once the teacher has accepted. */
internal fun studentVisitPlace(directions: OfficeDirections?): String {
    if (directions == null) return ""
    return listOf(directions.room, directions.floor, directions.building)
        .map { it.trim() }
        .filter { it.isNotBlank() }
        .joinToString(", ")
        .ifBlank { directions.location }
}

@Composable
internal fun StudentVisitContent(
    appointment: AppointmentDto,
    directions: OfficeDirections?,
    busy: Boolean,
    onDirections: () -> Unit,
    onImHere: () -> Unit,
    onCancel: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val teacherName = appointment.teacher?.shortName ?: appointment.office.name
    val here = appointment.arrivedAt != null
    val place = studentVisitPlace(directions)
    LazyColumn(
        modifier = modifier.fillMaxSize(),
        contentPadding = PaddingValues(18.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, BorderSoft),
                shape = RoundedCornerShape(22.dp),
            ) {
                Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Box(
                        Modifier
                            .background(if (here) BlueSurface else Color(0xFFDCFCE7), RoundedCornerShape(50))
                            .padding(horizontal = 10.dp, vertical = 5.dp),
                    ) {
                        Text(
                            if (here) "$teacherName knows you're here" else "Accepted",
                            color = if (here) IsatuBlueDark else IsatuGreen,
                            style = MaterialTheme.typography.labelLarge,
                        )
                    }
                    Text(appointment.teacher?.name ?: appointment.office.name, style = MaterialTheme.typography.titleLarge, color = Ink)
                    InfoLine(Icons.Rounded.Schedule, visitTimeText(appointment))
                    if (place.isNotBlank()) InfoLine(Icons.Rounded.LocationOn, place)
                    Text("${appointment.purpose}: ${appointment.subject}", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.padding(top = 4.dp)) {
                        OutlinedButton(
                            onClick = onDirections,
                            enabled = directions?.hasGuide == true,
                            modifier = Modifier.weight(1f).height(52.dp),
                            shape = RoundedCornerShape(12.dp),
                            border = BorderStroke(1.dp, Color(0xFF79747E)),
                            contentPadding = PaddingValues(horizontal = 8.dp),
                        ) {
                            Icon(Icons.Rounded.Directions, contentDescription = null, modifier = Modifier.size(20.dp))
                            Text("Directions", modifier = Modifier.padding(start = 6.dp), fontWeight = FontWeight.SemiBold, maxLines = 1)
                        }
                        if (!here) {
                            PrimaryButton("I'm here", onImHere, Modifier.weight(1f), !busy, Icons.Rounded.CheckCircle)
                        }
                    }
                    if (here) {
                        Text(
                            "Wait near the room. The visit closes when $teacherName marks it done.",
                            color = MutedInk,
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                }
            }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
                Icon(Icons.Rounded.Shield, contentDescription = null, tint = IsatuGreen, modifier = Modifier.size(18.dp))
                Text(
                    "No pass and no gate scan for students. Your location isn't shared with anyone.",
                    color = MutedInk,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
        }
        item {
            OutlinedButton(
                onClick = onCancel,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                enabled = !busy,
            ) { Text("Cancel visit", color = MaterialTheme.colorScheme.error) }
        }
    }
}

@Composable
private fun InfoLine(icon: ImageVector, text: String) {
    Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Icon(icon, contentDescription = null, tint = MutedInk, modifier = Modifier.padding(top = 1.dp).size(20.dp))
        Text(text, style = MaterialTheme.typography.bodyLarge)
    }
}
