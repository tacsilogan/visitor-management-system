package ph.edu.isatu.visitor.navigation

import ph.edu.isatu.visitor.data.CampusStopDto

/** One step from the building door to the office door. [photo] is empty when it has none. */
data class InsideStep(val text: String, val photo: String = "")

/**
 * How to get from the building door to an office (INDOOR_DIRECTIONS.md), and every sentence the
 * app shows or says about it, so the screens, the voice and the notifications agree. An office
 * with nothing written still works: GPS leads to its pin, and the visitor taps when they get there.
 */
data class OfficeDirections(
    val appointmentId: Long,
    val office: String,
    /** The Office Directory's location, used when no building is set. */
    val location: String = "",
    val building: String = "",
    val floor: String = "",
    val room: String = "",
    val message: String = "",
    val steps: List<InsideStep> = emptyList(),
) {
    val hasSteps: Boolean get() = steps.isNotEmpty()

    /** True when there is more to show than the office's name. */
    val hasGuide: Boolean get() = hasSteps || message.isNotBlank() || where.isNotBlank()

    /** Where GPS leads: the building when the office is inside one. */
    val place: String get() = building.ifBlank { office }

    /** "CCI Building, 2nd floor, Room 201". */
    val where: String
        get() = listOf(building, floor, room).filter { it.isNotBlank() }.joinToString(", ").ifBlank { location }

    /** The line under the office's name on the map while walking: "Tech Support, 2nd floor, Room 201". */
    val detailWhileWalking: String
        get() = if (building.isBlank()) location else listOf(office, floor, room).filter { it.isNotBlank() }.joinToString(", ")

    /** "Tech Support is on the 2nd floor". */
    val title: String get() = if (floor.isNotBlank()) "$office is on the $floor" else office

    /** "CCI Building, Room 201": what [title] leaves out. */
    val subtitle: String
        get() = listOf(building, room).filter { it.isNotBlank() }.joinToString(", ")
            .ifBlank { if (floor.isBlank()) location else "" }

    /** Asked near the building: with steps, the office is still further on. */
    val question: String get() = if (hasSteps) "Are you at the building now?" else arrivalQuestion

    val arrivalQuestion: String get() = "Are you at $office now?"

    /** "Heading to Tech Support, in the CCI Building, 2nd floor". */
    fun startLine(opening: String = "Heading to $office"): String = when {
        building.isBlank() -> opening
        floor.isBlank() -> "$opening, in the $building"
        else -> "$opening, in the $building, $floor"
    }

    /** "CCI Building is just ahead. Tech Support is on the 2nd floor, Room 201. Take the stairs..." */
    fun nearLine(): String = buildList {
        add("$place is just ahead.")
        val inside = listOf(floor.takeIf { it.isNotBlank() }?.let { "on the $it" }, room.takeIf { it.isNotBlank() })
            .filterNotNull().joinToString(", ")
        when {
            inside.isNotBlank() -> add("$office is ${if (floor.isBlank()) "in " else ""}$inside.")
            building.isBlank() && location.isNotBlank() -> add("It's at ${location.trimEnd('.')}.")
        }
        if (message.isNotBlank()) add(message.trim())
    }.joinToString(" ")

    /** The step's sentence; the last one also asks whether the visitor is there. */
    fun stepLine(index: Int): String {
        val step = steps.getOrNull(index) ?: return ""
        return if (index == steps.lastIndex) "${step.text} $arrivalQuestion" else step.text
    }

    fun arrivedLine(): String = "You have arrived at $office."

    fun helpSentLine(): String = "$office has been told. Please stay where you are."

    /** "Go back to the main door of CCI Building." */
    val leavingText: String get() = "Go back to the main door of $place."

    fun leavingLine(): String = "$leavingText Are you outside now?"

    /** Shown when the visitor reaches the building with the app in the background. */
    val nearNotificationTitle: String get() = "$place is just ahead"

    val nearNotificationText: String
        get() {
            val inside = listOf(floor.takeIf { it.isNotBlank() }?.let { "on the $it" }, room.takeIf { it.isNotBlank() })
                .filterNotNull().joinToString(", ")
            return when {
                inside.isNotBlank() -> "$office is ${if (floor.isBlank()) "in " else ""}$inside. Tap for directions."
                message.isNotBlank() -> "${message.trim()} Tap for directions."
                else -> "Tap for directions."
            }
        }

    /** The one reminder for a visitor who reached the building but never tapped. */
    val reminderTitle: String get() = "Have you reached $office?"

    val reminderText: String get() = "Tap Yes, I'm here so they know."

    fun reminderLine(): String = "$reminderTitle $reminderText"
}

fun CampusStopDto.toDirections() = OfficeDirections(
    appointmentId = appointmentId,
    office = label,
    location = location,
    building = building,
    floor = floor,
    room = room,
    message = visitorMessage,
    steps = directionSteps.map { InsideStep(it.text, it.photo) },
)

/**
 * Whether the visitor has to come out of a building before GPS can guide them again: they arrived
 * at [leftBuilding]'s office, and where they are going next is not in that same building.
 */
fun mustLeaveBuilding(leftBuilding: String, nextBuilding: String?): Boolean =
    leftBuilding.isNotBlank() && !leftBuilding.equals(nextBuilding.orEmpty(), ignoreCase = true)
