package ph.edu.isatu.visitor.ui

import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import ph.edu.isatu.visitor.data.TeacherDto

/*
 * What the teacher screens say. No Compose here, so it can be tested on its own. A visitor only
 * ever learns whether a teacher can be seen, or their next free time, and never why.
 */

private val SERVER_TIME: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss", Locale.US)
private val CLOCK: DateTimeFormatter = DateTimeFormatter.ofPattern("h:mm a", Locale.US)
private val WEEKDAY: DateTimeFormatter = DateTimeFormatter.ofPattern("EEE", Locale.US)
private val MONTH_DAY: DateTimeFormatter = DateTimeFormatter.ofPattern("MMM d", Locale.US)
private val MANILA: ZoneId = ZoneId.of("Asia/Manila")
private val TITLES = setOf("prof.", "dr.", "engr.", "mr.", "ms.", "mrs.")

/** How long a teacher has to answer a walk-in, for the ring on the waiting screen. */
const val WALK_IN_ANSWER_SECONDS = 600

fun manilaToday(): LocalDate = LocalDate.now(MANILA)

/** "ER" for "Prof. Elena Reyes": the title is not part of the initials. */
fun teacherInitials(name: String): String {
    val words = name.trim().split(Regex("\\s+")).filter { it.isNotBlank() && it.lowercase(Locale.US) !in TITLES }
    return when {
        words.isEmpty() -> "?"
        words.size == 1 -> words[0].take(1).uppercase(Locale.US)
        else -> (words.first().take(1) + words.last().take(1)).uppercase(Locale.US)
    }
}

/** "Today, 1:00 PM", "Tomorrow, 9:00 AM", "Mon, 9:00 AM", or "Nov 20, 9:00 AM". The time stays on one line. */
fun formatNextFree(value: String, today: LocalDate = manilaToday()): String {
    val at = runCatching { LocalDateTime.parse(value, SERVER_TIME) }.getOrNull() ?: return value
    val day = at.toLocalDate()
    val dayText = when {
        day == today -> "Today"
        day == today.plusDays(1) -> "Tomorrow"
        day.isAfter(today) && day.isBefore(today.plusDays(7)) -> day.format(WEEKDAY)
        else -> day.format(MONTH_DAY)
    }
    return "$dayText, ${at.format(CLOCK).replace(' ', ' ')}"
}

/** "Available now", "Next free: Today, 1:00 PM", or "Not available". */
fun teacherAvailability(teacher: TeacherDto, today: LocalDate = manilaToday()): String = when {
    teacher.availableNow -> "Available now"
    teacher.nextFreeAt != null -> "Next free: ${formatNextFree(teacher.nextFreeAt, today)}"
    else -> "Not available"
}

/** Why Walk-in is off for this teacher, or null when it's on. */
fun teacherWalkInNote(teacher: TeacherDto): String? = when {
    teacher.canWalkIn -> null
    !teacher.availableNow -> "Not available now, so walk-in is off."
    else -> "${teacher.shortName} takes appointments only."
}

/** What a visitor is told about a walk-in, before asking. */
fun teacherWalkInPromise(teacher: TeacherDto, fallbackOfficeName: String?, student: Boolean = false): String {
    if (student) {
        return "${teacher.shortName} is told right away. If there's no answer in 10 minutes, you can pick a time."
    }
    val after = if (fallbackOfficeName != null) "you can book a time or go to the $fallbackOfficeName" else "you can book a time"
    return "You get your pass only if ${teacher.shortName} accepts. If there's no answer in 10 minutes, $after."
}

/** Seconds until a walk-in request closes, for the ring. The server decides when it really closes. */
fun walkInSecondsLeft(answerByAt: String?, nowMillis: Long = System.currentTimeMillis()): Int {
    val at = answerByAt?.let { runCatching { LocalDateTime.parse(it, SERVER_TIME) }.getOrNull() } ?: return 0
    val left = (at.atZone(MANILA).toInstant().toEpochMilli() - nowMillis) / 1000
    return left.coerceIn(0, WALK_IN_ANSWER_SECONDS.toLong()).toInt()
}

/** "9:42". */
fun formatCountdown(seconds: Int): String {
    val safe = seconds.coerceAtLeast(0)
    return "${safe / 60}:${(safe % 60).toString().padStart(2, '0')}"
}

/** Teachers matching what was typed and the chosen course ("" means all courses). */
fun filterTeachers(teachers: List<TeacherDto>, query: String, course: String, department: String = ""): List<TeacherDto> {
    val words = query.trim().lowercase(Locale.US).split(Regex("\\s+")).filter { it.isNotBlank() }
    return teachers.filter { teacher ->
        val name = teacher.name.lowercase(Locale.US)
        (department.isBlank() || teacher.department == department) &&
            (course.isBlank() || teacher.course == course) && words.all { name.contains(it) }
    }
}
