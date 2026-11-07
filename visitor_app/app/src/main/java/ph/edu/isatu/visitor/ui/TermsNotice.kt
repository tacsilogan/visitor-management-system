package ph.edu.isatu.visitor.ui

/**
 * The Terms and Privacy Notice, as visitors read it. Change BuildConfig.CONSENT_VERSION
 * (app/build.gradle.kts) when it changes in an important way: everyone is then asked to agree again.
 *
 * It describes what the system really does, so it has to follow these settings on the server:
 * pass valid from 30 minutes before the visit, location sent every 15 seconds
 * (location_upload_interval_seconds), location kept 90 days (location_retention_days), 25 m
 * margin at the campus boundary and about 5 minutes outside to end a visit (presence_service.php),
 * sign-in attempts kept 90 days (AUTH_ATTEMPT_RETENTION_DAYS).
 *
 * In the text, **this** is bold, and [[this]] is a blank waiting for ISATU's answer, which shows
 * highlighted so nobody takes it for the approved wording.
 */
object TermsNotice {
    const val TITLE = "Terms and Privacy Notice"
    /** Ends a visitor's agreement, so the one box names location sharing too. */
    const val LOCATION_PART = ", including sharing my location during my visits."

    val BLOCKS: List<NoticeBlock> = listOf(
        NoticeBlock.Fine("ISATU Visitor app. Version 3, effective [[date]]."),
        NoticeBlock.Summary(
            "In short",
            listOf(
                "ISATU uses your details to handle your visits and keep the campus safe.",
                "Your location is shared only from when a guard checks you in until your visit ends, and you can stop sharing at any time.",
                "ISATU doesn't sell your information or use it for advertising.",
                "You can ask to see, correct, or delete your information.",
            ),
        ),

        NoticeBlock.Part("Terms of use"),
        NoticeBlock.Heading("Your account"),
        NoticeBlock.Paragraph(
            "Use your real name and correct contact details, and keep your password private. " +
                "[[If you are under 18, a parent or guardian must agree for you. ISATU to confirm.]]",
        ),
        NoticeBlock.Heading("Your pass"),
        NoticeBlock.Paragraph(
            "Your pass is for you alone. Show it to the guard with a valid ID. It works from 30 minutes " +
                "before your visit until the visit's scheduled end.",
        ),
        NoticeBlock.Heading("Your visit"),
        NoticeBlock.Paragraph(
            "An appointment needs the approval of the office or teacher you are visiting, who may decline it " +
                "or suggest another time. A walk-in depends on the office accepting visitors at that time. A " +
                "walk-in to a teacher also needs the teacher to accept it, so you get your pass only then. On campus, follow " +
                "university rules and the instructions of guards and staff, and have your pass scanned at " +
                "the gate when you leave.",
        ),
        NoticeBlock.Heading("Directions"),
        NoticeBlock.Paragraph(
            "The map, voice directions, and steps inside buildings are a guide, and GPS can be off. Watch " +
                "where you are going, and ask a guard or staff member if you are unsure.",
        ),
        NoticeBlock.Heading("Misuse"),
        NoticeBlock.Paragraph(
            "ISATU may refuse you entry or suspend your account if you give false details, share or copy a " +
                "pass, or misuse the app.",
        ),
        NoticeBlock.Heading("Changes"),
        NoticeBlock.Paragraph(
            "If this notice changes in an important way, the app will ask you to read and agree again. " +
                "Philippine law applies to these terms.",
        ),

        NoticeBlock.Part("Privacy notice"),
        NoticeBlock.Paragraph(
            "Iloilo Science and Technology University (ISATU) is responsible for your information under the " +
                "Data Privacy Act of 2012 (Republic Act 10173).",
        ),
        NoticeBlock.Heading("What we collect"),
        NoticeBlock.Point(
            "**Account:** your name, email, contact number, and password. The password is stored in a " +
                "scrambled form that nobody can read.",
        ),
        NoticeBlock.Point(
            "**Visits:** the office or teacher, date and time, purpose, subject, and any details you add; your check-in " +
                "and check-out times; and when you say you have arrived or need help.",
        ),
        NoticeBlock.Point("**Location:** see the next section."),
        NoticeBlock.Point(
            "**Your phone:** its maker and model, the app version, an ID for this copy of the app, a " +
                "notification token, and its internet (IP) address.",
        ),
        NoticeBlock.Heading("Your location"),
        NoticeBlock.Point(
            "Sharing starts only after a guard scans your pass, and a notification stays on your phone while it is on.",
        ),
        NoticeBlock.Point(
            "While sharing, the app sends your position, its accuracy, and the time about every 15 seconds. " +
                "If you lose signal, it sends saved positions when it reconnects.",
        ),
        NoticeBlock.Point(
            "It stops when your visit ends: when you are checked out at the gate, when you have left campus, " +
                "or at the end of the day.",
        ),
        NoticeBlock.Point(
            "Outside the campus boundary, your position isn't saved or shown. We only note the time you " +
                "stepped out, and if you stay out for about 5 minutes, your visit ends. Positions within " +
                "25 meters of the boundary count as inside, because GPS can drift.",
        ),
        NoticeBlock.Point("The moment-to-moment position that guides you on the map stays on your phone."),
        NoticeBlock.Point(
            "You can tap **Stop sharing my location** at any time. Your visit and directions keep working.",
        ),
        NoticeBlock.Heading("Why we use it"),
        NoticeBlock.Point("To run your account and visits: requests, passes, check-in, and check-out."),
        NoticeBlock.Point("To guide you on campus and tell the office or teacher when you arrive or need help."),
        NoticeBlock.Point("To keep the campus safe: guards can see where visitors are and how long they have stayed."),
        NoticeBlock.Point("To send you visit updates as notifications, and password recovery codes by email."),
        NoticeBlock.Point("To keep records, prepare visitor reports, and look into misuse."),
        NoticeBlock.Heading("ISATU students"),
        NoticeBlock.Paragraph(
            "If you sign up as an ISATU student, you use your @students.isatu.edu.ph email and give your " +
                "Student ID, course, year and section. You book teachers only. You get **no pass**, no " +
                "guard scans you, and **your location is never shared**: the directions to a teacher's room " +
                "don't use it. Instead, you tap I'm here, and the teacher is told.",
        ),
        NoticeBlock.Point(
            "**A teacher you book** sees your name, Student ID, course, year and section, your reason, " +
                "and when you tap I'm here. They can't see your email or contact number.",
        ),
        NoticeBlock.Point(
            "Your visits to teachers aren't shown to guards and aren't counted as visitors. Administrators " +
                "see your account and count student visits separately.",
        ),
        NoticeBlock.Heading("Who can see it"),
        NoticeBlock.Point(
            "**The office you are visiting:** your name, email, contact number, visit details, and when you " +
                "arrive or ask for help. Offices can't see your position on a map.",
        ),
        NoticeBlock.Point(
            "**A teacher you are visiting:** your name, visit details, and when you arrive or ask for help. " +
                "Teachers can't see your email, contact number, or position on a map.",
        ),
        NoticeBlock.Point(
            "**Guards:** your name, contact details, visit details, your position on campus, and the path you walked.",
        ),
        NoticeBlock.Point("**Administrators:** all of the above, activity records, and reports they can download."),
        NoticeBlock.Paragraph("Apart from the services below, we share your information only when the law requires it."),
        NoticeBlock.Heading("Services that help run the app"),
        NoticeBlock.Paragraph("Your information may be handled outside the Philippines by:"),
        NoticeBlock.Point("**Railway,** which hosts the system and its database in Singapore."),
        NoticeBlock.Point(
            "**Google,** whose Firebase delivers notifications and whose Play services work out your phone's position.",
        ),
        NoticeBlock.Point("**Brevo,** which delivers password recovery emails to your email address."),
        NoticeBlock.Point("**OpenFreeMap,** which supplies the map and can see your internet address and the area you view."),
        NoticeBlock.Heading("How long we keep it"),
        NoticeBlock.Point("**Location positions:** 90 days, then deleted automatically."),
        NoticeBlock.Point("**Sign-in attempts:** 90 days."),
        NoticeBlock.Point(
            "**Your account, visits, consent records, and activity records:** [[period for ISATU to set]].",
        ),
        NoticeBlock.Heading("Your rights"),
        NoticeBlock.Paragraph(
            "You can ask to see your information, correct it, get a copy, object to how it is used, or have " +
                "it blocked or deleted. We may have to keep some records for security or legal reasons. You " +
                "can change your name and contact number yourself in Profile. For anything else, contact us " +
                "below. You can also complain to the National Privacy Commission (privacy.gov.ph).",
        ),
        NoticeBlock.Heading("Contact"),
        NoticeBlock.Paragraph(
            "Data Protection Officer, Iloilo Science and Technology University. " +
                "[[Email and phone: ISATU to fill in]]",
        ),
    )

    /** The wording ISATU still has to supply or confirm. */
    val blanks: List<String>
        get() = BLOCKS.flatMap(NoticeBlock::texts)
            .flatMap(::noticeSpans)
            .filter { it.style == NoticeStyle.BLANK }
            .map { it.text }
}

sealed interface NoticeBlock {
    /** Small print. */
    data class Fine(val text: String) : NoticeBlock

    /** The summary box at the top. */
    data class Summary(val title: String, val points: List<String>) : NoticeBlock

    /** One of the two main parts. */
    data class Part(val title: String) : NoticeBlock

    data class Heading(val title: String) : NoticeBlock
    data class Paragraph(val text: String) : NoticeBlock
    data class Point(val text: String) : NoticeBlock

    fun texts(): List<String> = when (this) {
        is Fine -> listOf(text)
        is Summary -> listOf(title) + points
        is Part -> listOf(title)
        is Heading -> listOf(title)
        is Paragraph -> listOf(text)
        is Point -> listOf(text)
    }
}

enum class NoticeStyle { PLAIN, BOLD, BLANK }

data class NoticeSpan(val text: String, val style: NoticeStyle)

private val NOTICE_MARKS = Regex("""\*\*(.+?)\*\*|\[\[(.+?)]]""")

/** Splits a line of the notice into its plain, bold, and blank parts. A blank keeps its brackets. */
fun noticeSpans(line: String): List<NoticeSpan> {
    val spans = mutableListOf<NoticeSpan>()
    var position = 0
    NOTICE_MARKS.findAll(line).forEach { mark ->
        if (mark.range.first > position) {
            spans += NoticeSpan(line.substring(position, mark.range.first), NoticeStyle.PLAIN)
        }
        val (bold, blank) = mark.destructured
        spans += if (bold.isNotEmpty()) NoticeSpan(bold, NoticeStyle.BOLD) else NoticeSpan("[$blank]", NoticeStyle.BLANK)
        position = mark.range.last + 1
    }
    if (position < line.length) spans += NoticeSpan(line.substring(position), NoticeStyle.PLAIN)
    return spans
}
