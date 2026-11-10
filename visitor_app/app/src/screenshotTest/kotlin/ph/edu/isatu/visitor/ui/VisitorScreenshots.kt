package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.Canvas
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Card
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Canvas
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.Paint
import androidx.compose.ui.graphics.drawscope.drawIntoCanvas
import androidx.compose.ui.graphics.nativeCanvas
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.tooling.preview.PreviewParameter
import androidx.compose.ui.tooling.preview.PreviewParameterProvider
import androidx.compose.ui.unit.dp
import com.android.tools.screenshot.PreviewTest
import java.time.LocalDate
import ph.edu.isatu.visitor.R
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.AvailabilitySlotDto
import ph.edu.isatu.visitor.data.TeacherDto
import ph.edu.isatu.visitor.data.VisitTeacherDto
import ph.edu.isatu.visitor.data.OfficeSummaryDto
import ph.edu.isatu.visitor.data.QrPassDto
import ph.edu.isatu.visitor.data.UserDto
import ph.edu.isatu.visitor.data.VisitorSummaryDto
import ph.edu.isatu.visitor.navigation.InsideStep
import ph.edu.isatu.visitor.navigation.OfficeDirections

/*
 * Pictures of the visitor's Home and pass screens with sample visits, to review the layout
 * without installing the app:
 *
 *   ./gradlew :app:updateDebugScreenshotTest
 *
 * They land in app/src/screenshotTestDebug/reference. The sample times are built from today's
 * date so the cards say "Today", which means the pictures change from day to day: they are
 * for looking at, not pass/fail checks.
 */

private val today: LocalDate = LocalDate.now(ManilaZone)

private fun at(day: LocalDate, time: String) = "$day $time:00"

private val visitor = UserDto(fullName = "Juan Dela Cruz")

private fun sampleVisit(
    status: String,
    office: String = "IT Department",
    day: LocalDate = today,
    start: String = "14:00",
    end: String = "14:30",
    pass: QrPassDto? = null,
    checkedInAt: String? = null,
) = AppointmentDto(
    id = 128,
    registrationCode = "V-2026-000128",
    office = OfficeSummaryDto(code = "IT", name = office),
    purpose = "Official Business",
    subject = "Meeting about the OJT partnership",
    scheduledStartAt = at(day, start),
    scheduledEndAt = at(day, end),
    status = status,
    checkedInAt = checkedInAt,
    qrPass = pass,
    visitor = VisitorSummaryDto(fullName = visitor.fullName),
)

private val readyPass = QrPassDto(
    token = "sample-pass-for-screenshots",
    validFrom = at(today, "13:30"),
    validUntil = at(today, "14:30"),
    currentlyValid = true,
)

private val approvedToday = sampleVisit("approved", pass = readyPass)

/** The app's top bar and bottom tabs around Home, as MainShell draws them. */
@Composable
private fun HomeFrame(appointments: List<AppointmentDto>) {
    ISATUVisitorTheme {
        Scaffold(
            containerColor = AppBackground,
            topBar = { MainTopBar(unreadCount = 1, busy = false, goldEdge = false, onNotifications = {}, onProfile = {}) },
            bottomBar = { MainBottomBar(selected = 0) {} },
        ) { padding ->
            Column(Modifier.fillMaxSize().padding(padding)) {
                HomeScreen(visitor, appointments, onOpenAppointment = {}, onOpenVisit = {}, onBook = {})
            }
        }
    }
}

/** The pass screen of a single visit, as AppointmentDetailScreen draws it. */
@Composable
private fun PassFrame(appointment: AppointmentDto, pass: QrPassDto) {
    ISATUVisitorTheme {
        DetailScaffold("Your pass", onBack = {}) { modifier ->
            LazyColumn(
                modifier = modifier.fillMaxSize(),
                contentPadding = PaddingValues(18.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                passItems(appointment, pass, busy = false, sampleDirections, {}, onCancel = {})
            }
        }
    }
}

@PreviewTest
@Preview(name = "Home, approved", widthDp = 360, heightDp = 800)
@Composable
fun HomeApproved() = HomeFrame(listOf(approvedToday))

@PreviewTest
@Preview(name = "Home, new time suggested", widthDp = 360, heightDp = 800)
@Composable
fun HomeNewTimeSuggested() = HomeFrame(listOf(sampleVisit("reschedule_proposed", office = "Dean's Office", day = today.plusDays(1), start = "09:00", end = "09:30")))

@PreviewTest
@Preview(name = "Home, on campus", widthDp = 360, heightDp = 800)
@Composable
fun HomeOnCampus() = HomeFrame(listOf(sampleVisit("checked_in", pass = readyPass, checkedInAt = at(today, "13:45"))))

@PreviewTest
@Preview(name = "Home, waiting for approval", widthDp = 360, heightDp = 800)
@Composable
fun HomeWaiting() = HomeFrame(listOf(sampleVisit("pending_approval", office = "CS Department", day = today.plusDays(6), start = "10:00", end = "10:30")))

@PreviewTest
@Preview(name = "Home, nothing booked", widthDp = 360, heightDp = 800)
@Composable
fun HomeNothingBooked() = HomeFrame(listOf(sampleVisit("completed", day = today.minusDays(7))))

@PreviewTest
@Preview(name = "Home, first visit", widthDp = 360, heightDp = 800)
@Composable
fun HomeFirstVisit() = HomeFrame(emptyList())

@PreviewTest
@Preview(name = "Pass, ready to scan", widthDp = 360, heightDp = 800)
@Composable
fun PassReady() = PassFrame(approvedToday, readyPass)

@PreviewTest
@Preview(name = "Pass, opens tomorrow", widthDp = 360, heightDp = 800)
@Composable
fun PassTomorrow() {
    val tomorrow = today.plusDays(1)
    val pass = QrPassDto(
        token = "sample-pass-for-screenshots",
        validFrom = at(tomorrow, "08:30"),
        validUntil = at(tomorrow, "09:30"),
        currentlyValid = false,
    )
    PassFrame(sampleVisit("approved", day = tomorrow, start = "09:00", end = "09:30", pass = pass), pass)
}

private val sampleDirections = OfficeDirections(
    appointmentId = 128,
    office = "Tech Support",
    location = "CCI Building, 2nd floor, Room 201",
    building = "CCI Building",
    floor = "2nd floor",
    room = "Room 201",
    message = "Take the stairs near the main door. Show your pass when you reach our office.",
    steps = listOf(
        InsideStep("Enter through the main door under the CCI Building sign.", "uploads/directions/a.jpg"),
        InsideStep("Take the stairs on your left up to the 2nd floor.", "uploads/directions/b.jpg"),
        InsideStep("At the top, turn right and walk to the end of the hallway."),
        InsideStep("Room 201 is the door with the Tech Support sign.", "uploads/directions/d.jpg"),
    ),
)

/** Stands in for an admin's photo: stairs of a sort. */
private fun samplePhoto(): ImageBitmap {
    val bitmap = ImageBitmap(800, 600)
    val canvas = Canvas(bitmap)
    canvas.drawRect(Rect(Offset.Zero, androidx.compose.ui.geometry.Size(800f, 600f)), Paint().apply { color = Color(0xFFD7DEE8) })
    repeat(6) { step ->
        canvas.drawRect(
            Rect(left = 80f + step * 100f, top = 500f - step * 70f, right = 800f, bottom = 600f),
            Paint().apply { color = if (step % 2 == 0) Color(0xFF8795A8) else Color(0xFF9FAEC1) },
        )
    }
    return bitmap
}

@Composable
private fun StepPicture(step: Int, reading: Boolean = false, photo: ImageBitmap? = samplePhoto()) {
    ISATUVisitorTheme {
        InsideStepScreen(
            directions = sampleDirections,
            step = step,
            voiceOn = true,
            photo = photo,
            onToggleVoice = {},
            onHear = {},
            onStep = {},
            onClose = {},
            onHelp = if (reading) null else ({}),
            onArrived = if (reading) null else ({}),
        )
    }
}

/** The map stands behind these on the phone. */
@Composable
private fun OverMap(content: @Composable androidx.compose.foundation.layout.BoxScope.() -> Unit) {
    ISATUVisitorTheme {
        Box(Modifier.fillMaxSize().background(Color(0xFFDDE5DA)), content = content)
    }
}

@PreviewTest
@Preview(name = "Near the building", widthDp = 360, heightDp = 780)
@Composable
fun NearBuilding() = OverMap {
    NearBuildingCard(sampleDirections, onYes = {}, onNotYet = {}, onCantFind = null, modifier = Modifier.align(Alignment.BottomCenter))
}

@PreviewTest
@Preview(name = "Near the building, description only", widthDp = 360, heightDp = 780)
@Composable
fun NearBuildingDescriptionOnly() = OverMap {
    NearBuildingCard(
        sampleDirections.copy(office = "Dean's Office", floor = "3rd floor", room = "Room 305", steps = emptyList(), message = "Take the main stairs to the 3rd floor. Our office is the first door on your right."),
        onYes = {},
        onNotYet = {},
        onCantFind = {},
        modifier = Modifier.align(Alignment.BottomCenter),
    )
}

@PreviewTest
@Preview(name = "Near an office with nothing written", widthDp = 360, heightDp = 780)
@Composable
fun NearOfficeNothingWritten() = OverMap {
    NearBuildingCard(OfficeDirections(1, "IT Department"), onYes = {}, onNotYet = {}, onCantFind = {}, modifier = Modifier.align(Alignment.BottomCenter))
}

@PreviewTest
@Preview(name = "Inside, first step", widthDp = 360, heightDp = 780)
@Composable
fun InsideFirstStep() = StepPicture(0)

@PreviewTest
@Preview(name = "Inside, a step without a photo", widthDp = 360, heightDp = 780)
@Composable
fun InsideStepNoPhoto() = StepPicture(2, photo = null)

@PreviewTest
@Preview(name = "Inside, last step", widthDp = 360, heightDp = 780)
@Composable
fun InsideLastStep() = StepPicture(3)

@PreviewTest
@Preview(name = "Inside, on a small phone", widthDp = 320, heightDp = 568)
@Composable
fun InsideSmallPhone() = StepPicture(3)

@PreviewTest
@Preview(name = "Reading the steps before the visit", widthDp = 360, heightDp = 780)
@Composable
fun InsideReadingAhead() = StepPicture(3, reading = true)

@PreviewTest
@Preview(name = "I can't find it", widthDp = 360, heightDp = 780)
@Composable
fun HelpAsking() = ISATUVisitorTheme {
    Box(Modifier.fillMaxSize()) {
        InsideStepScreen(sampleDirections, 1, true, samplePhoto(), {}, {}, {}, {}, {}, {})
        HelpSheet(sampleDirections, 1, HelpPrompt.Asking, onAsk = {}, onDismiss = {})
    }
}

@PreviewTest
@Preview(name = "The office was told", widthDp = 360, heightDp = 780)
@Composable
fun HelpTold() = ISATUVisitorTheme {
    Box(Modifier.fillMaxSize()) {
        InsideStepScreen(sampleDirections, 1, true, samplePhoto(), {}, {}, {}, {}, {}, {})
        HelpSheet(sampleDirections, 1, HelpPrompt.Answered(HelpResult.TOLD), onAsk = {}, onDismiss = {})
    }
}

@PreviewTest
@Preview(name = "The office has nobody to tell", widthDp = 360, heightDp = 780)
@Composable
fun HelpNoStaff() = ISATUVisitorTheme {
    Box(Modifier.fillMaxSize()) {
        InsideStepScreen(sampleDirections, 1, true, samplePhoto(), {}, {}, {}, {}, {}, {})
        HelpSheet(sampleDirections, 1, HelpPrompt.Answered(HelpResult.NO_STAFF), onAsk = {}, onDismiss = {})
    }
}

@PreviewTest
@Preview(name = "Leaving the building", widthDp = 360, heightDp = 780)
@Composable
fun LeavingBuilding() = ISATUVisitorTheme {
    HeadingOutScreen(sampleDirections, true, samplePhoto(), {}, {}, {}, {})
}

/** Every twentieth of a second of the opening animation. */
class OpeningMoments : PreviewParameterProvider<Long> {
    override val values = generateSequence(0L) { it + 50L }.takeWhile { it <= OpeningTimeline.TOTAL_MS }
}

/**
 * One moment of the opening animation, over the Home screen it opens onto. With a [subline], it is
 * the welcome after an account is made, which holds its words longer.
 */
@Composable
internal fun OpeningOverHome(
    ms: Long,
    greeting: String = "Welcome back, Juan",
    subline: String? = null,
    home: @Composable () -> Unit = { HomeFrame(listOf(approvedToday)) },
) {
    val art = loadSealArt(LocalContext.current)
    // Where PortalBrand puts the logo in this top bar, with no status bar above it in a picture.
    val landing = with(LocalDensity.current) { Rect(16.dp.toPx(), 16.dp.toPx(), 48.dp.toPx(), 48.dp.toPx()) }
    val sealHome = remember { SealHome(away = true) }
    val leaveAt = OpeningTimeline.LEAVE_AT + if (subline != null) WELCOME_HOLD_MS else 0L
    CompositionLocalProvider(LocalSealHome provides sealHome) {
        Box(Modifier.fillMaxSize()) {
            home()
            // In the app's theme, as in MainActivity, for the welcome's second line.
            ISATUVisitorTheme {
                OpeningStage(
                    art = art,
                    frame = { OpeningTimeline.at(ms, leaveAt) },
                    landing = landing,
                    words = greeting.takeIf { ms >= OpeningTimeline.WORDS_AT },
                    subline = subline,
                )
            }
        }
    }
}

@PreviewTest
@Preview(name = "Welcome, new visitor", widthDp = 360, heightDp = 780)
@Composable
fun WelcomeVisitor() = OpeningOverHome(2800L, greeting = "Welcome, Maria", subline = "Your visitor account is ready", home = { HomeFrame(emptyList()) })

@PreviewTest
@Preview(name = "Welcome, small phone", widthDp = 320, heightDp = 568)
@Composable
fun WelcomeSmallPhone() = OpeningOverHome(2800L, greeting = "Welcome, Maximiliano", subline = "Your visitor account is ready", home = { HomeFrame(emptyList()) })

@PreviewTest
@Preview(name = "Welcome, flying to the header", widthDp = 360, heightDp = 780)
@Composable
fun WelcomeLeaving() = OpeningOverHome(
    OpeningTimeline.LEAVE_AT + WELCOME_HOLD_MS + 200L,
    greeting = "Welcome, Maria",
    subline = "Your visitor account is ready",
    home = { HomeFrame(emptyList()) },
)

@PreviewTest
@Preview(name = "Opening animation", widthDp = 360, heightDp = 780)
@Composable
fun OpeningMoment(@PreviewParameter(OpeningMoments::class) ms: Long) = OpeningOverHome(ms)

@PreviewTest
@Preview(name = "Opening animation, small phone", widthDp = 320, heightDp = 568)
@Composable
fun OpeningSmallPhone() = OpeningOverHome(2600L, greeting = "Welcome back, Maximiliano Cristobal")

@PreviewTest
@Preview(name = "Opening animation, large phone", widthDp = 412, heightDp = 915)
@Composable
fun OpeningLargePhone() = OpeningOverHome(2600L, greeting = "Welcome to ISATU Visits")

@PreviewTest
@Preview(name = "Opening, nobody logged in, small phone", widthDp = 320, heightDp = 568)
@Composable
fun OpeningSignedOutSmallPhone() = OpeningOverHome(2600L, greeting = "Welcome to ISATU Visits")

@PreviewTest
@Preview(name = "Welcome back, after logging in", widthDp = 360, heightDp = 780)
@Composable
fun WelcomeBack() = OpeningOverHome(2600L, greeting = Welcome("Maria", isStudent = false, returning = true).greeting)

/** The real app icon, as Android builds it from the resources and masks it. */
@PreviewTest
@Preview(name = "App icon", widthDp = 240, heightDp = 240, showBackground = true, backgroundColor = 0xFF6B5B4A)
@Composable
fun AppIcon() {
    val context = LocalContext.current
    val icon = remember { context.getDrawable(R.mipmap.ic_launcher)!! }
    ISATUVisitorTheme {
        Column(Modifier.fillMaxSize().padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Canvas(Modifier.size(144.dp)) {
                drawIntoCanvas { canvas ->
                    icon.setBounds(0, 0, size.width.toInt(), size.height.toInt())
                    icon.draw(canvas.nativeCanvas)
                }
            }
            Text("${icon.javaClass.simpleName}: ${icon.intrinsicWidth} x ${icon.intrinsicHeight}", color = Color.White)
        }
    }
}

@Composable
private fun SignInFrame(rememberedEmail: String = "", message: String? = null) = ISATUVisitorTheme {
    BrandedAuthPage(onBack = null, busy = false, banner = { message?.let { MessageCard(it, true) {} } }) {
        LoginForm(busy = false, onLogin = { _, _ -> }, onRegister = {}, onRecover = {}, rememberedEmail = rememberedEmail)
    }
}

@PreviewTest
@Preview(name = "Sign in", widthDp = 360, heightDp = 780)
@Composable
fun SignIn() = SignInFrame()

@PreviewTest
@Preview(name = "Sign in, email remembered", widthDp = 360, heightDp = 780)
@Composable
fun SignInRemembered() = SignInFrame(rememberedEmail = "maria.santos@gmail.com")

@PreviewTest
@Preview(name = "Sign in, small phone", widthDp = 320, heightDp = 568)
@Composable
fun SignInSmallPhone() = SignInFrame(rememberedEmail = "maria.santos@gmail.com")

@PreviewTest
@Preview(name = "Sign in, large phone", widthDp = 412, heightDp = 915)
@Composable
fun SignInLargePhone() = SignInFrame(rememberedEmail = "maria.santos@gmail.com")

@PreviewTest
@Preview(name = "Sign in, wrong password", widthDp = 360, heightDp = 780)
@Composable
fun SignInWrongPassword() = SignInFrame(rememberedEmail = "maria.santos@gmail.com", message = "Incorrect email or password.")

@Composable
private fun ResetFrame(progress: PasswordResetProgress?, message: String? = null) = ISATUVisitorTheme {
    AuthFormPage("Reset password", onBack = {}, busy = false, banner = { message?.let { MessageCard(it, true) {} } }) {
        RecoverForm(
            progress = progress,
            busy = false,
            rememberedEmail = "maria.santos@gmail.com",
            onRequest = {},
            onReset = { _, _, _ -> },
            onDifferentEmail = {},
            autoFocus = false,
        )
    }
}

@PreviewTest
@Preview(name = "Forgot password, the email", widthDp = 360, heightDp = 780)
@Composable
fun ResetPasswordEmail() = ResetFrame(progress = null)

@PreviewTest
@Preview(name = "Forgot password, the code", widthDp = 360, heightDp = 780)
@Composable
fun ResetPasswordCode() = ResetFrame(PasswordResetProgress("maria.santos@gmail.com", minutes = 15, resendAt = 0L))

@PreviewTest
@Preview(name = "Forgot password, a wrong code", widthDp = 360, heightDp = 780)
@Composable
fun ResetPasswordWrongCode() = ResetFrame(
    PasswordResetProgress("maria.santos@gmail.com", minutes = 15, resendAt = 0L),
    message = "That code is not right. 4 tries left.",
)

@PreviewTest
@Preview(name = "Visitor account", widthDp = 360, heightDp = 780)
@Composable
fun CreateAccount() = ISATUVisitorTheme {
    val draft = VisitorDraft().apply {
        name = "Maria Santos"
        email = "maria.santos@gmail.com"
        contact = "0917 555 0142"
        password = "Campus-Visit-2026"
        agreed = true
    }
    VisitorSignupPage(draft, busy = false, banner = {}, onBack = {}, onRegister = { _, _, _, _ -> }, onOpenNotice = {}, onStudentInstead = {})
}

@PreviewTest
@Preview(name = "The agreement, ticked", widthDp = 320, heightDp = 130, showBackground = true)
@Composable
fun AgreementTicked() = ISATUVisitorTheme {
    Column(Modifier.padding(16.dp)) { NoticeAgreement(true, {}, includeLocation = true, onOpenNotice = {}) }
}

@PreviewTest
@Preview(name = "Terms and Privacy Notice", widthDp = 360, heightDp = 780)
@Composable
fun NoticeTop() = ISATUVisitorTheme { TermsNoticeScreen("Back to sign-up") {} }

@PreviewTest
@Preview(name = "Terms and Privacy Notice, most of it", widthDp = 360, heightDp = 3000)
@Composable
fun NoticeWhole() = ISATUVisitorTheme { TermsNoticeScreen("Back to sign-up") {} }

@PreviewTest
@Preview(name = "Terms and Privacy Notice, the end", widthDp = 360, heightDp = 1000)
@Composable
fun NoticeEnd() = ISATUVisitorTheme {
    Column(Modifier.fillMaxSize().background(AppBackground).padding(18.dp)) {
        TermsNotice.BLOCKS.takeLast(15).forEach { NoticeBlockView(it) }
    }
}

@PreviewTest
@Preview(name = "Agreeing, an existing account", widthDp = 360, heightDp = 780)
@Composable
fun AgreementScreen() = ISATUVisitorTheme { TermsAgreementScreen(busy = false, isStudent = false, onAgree = {}, onSignOut = {}) }

@PreviewTest
@Preview(name = "Agreeing, on a small phone", widthDp = 320, heightDp = 568)
@Composable
fun NoticeAgreementSmallPhone() = ISATUVisitorTheme { TermsAgreementScreen(busy = false, isStudent = false, onAgree = {}, onSignOut = {}) }

@PreviewTest
@Preview(name = "The link in Profile", widthDp = 320, heightDp = 80, showBackground = true)
@Composable
fun NoticeLinkInProfile() = ISATUVisitorTheme {
    Column(Modifier.padding(16.dp)) { NoticeLink {} }
}

/* ---------- Visits to a teacher ---------- */

private val sampleTeachers = listOf(
    TeacherDto(code = "T1", name = "Prof. Elena Reyes", shortName = "Prof. Reyes", course = "IT", department = "CCI", departmentName = "College of Computing and Informatics", availableNow = true, canWalkIn = true),
    TeacherDto(code = "T2", name = "Mr. Paolo Dizon", shortName = "Mr. Dizon", course = "IT", department = "CCI", departmentName = "College of Computing and Informatics", nextFreeAt = at(today, "13:00")),
    TeacherDto(code = "T3", name = "Dr. Ana Salazar", shortName = "Dr. Salazar", course = "CS", department = "CCI", departmentName = "College of Computing and Informatics", availableNow = true, canWalkIn = true),
    TeacherDto(code = "T4", name = "Engr. Marco Villanueva", shortName = "Engr. Villanueva", course = "CS", department = "CCI", departmentName = "College of Computing and Informatics", nextFreeAt = at(today.plusDays(3), "09:00"), acceptsWalkIns = false),
    TeacherDto(code = "T5", name = "Prof. Rosa Tan", shortName = "Prof. Tan", course = "IS", department = "CCI", departmentName = "College of Computing and Informatics", nextFreeAt = at(today, "14:00")),
    TeacherDto(code = "T6", name = "Mr. Ken Aquino", shortName = "Mr. Aquino", course = "IS", department = "CCI", departmentName = "College of Computing and Informatics", availableNow = true, acceptsWalkIns = false),
)

private val sampleTeacherVisit = VisitTeacherDto(code = "T1", name = "Prof. Elena Reyes", shortName = "Prof. Reyes", course = "IT", department = "CCI")

private fun teacherVisit(status: String, pass: QrPassDto? = null, answerBy: String? = null, type: String = "appointment") =
    sampleVisit(status, office = "Prof. Elena Reyes", start = "10:30", end = "11:00", pass = pass).copy(
        teacher = sampleTeacherVisit,
        answerByAt = answerBy,
        visitType = type,
        subject = "Thesis consultation",
        purpose = "Academic Concern",
    )

private val nextTimes = listOf("13:30", "14:00").map { AvailabilitySlotDto(at(today, it), at(today, it)) } +
    listOf("09:30", "10:30").map { AvailabilitySlotDto(at(today.plusDays(1), it), at(today.plusDays(1), it)) }

@PreviewTest
@Preview(name = "Find a teacher", widthDp = 360, heightDp = 780)
@Composable
fun TeacherFind() = ISATUVisitorTheme {
    Column(Modifier.fillMaxSize().background(AppBackground)) {
        FindTeacherContent(sampleTeachers, false, {}, {}, {})
    }
}

@PreviewTest
@Preview(name = "The teacher field on the form", widthDp = 360, heightDp = 320)
@Composable
fun TeacherFormParts() = ISATUVisitorTheme {
    Column(Modifier.fillMaxSize().background(AppBackground).padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        FindTeacherButton {}
        TeacherChoiceCard(sampleTeachers[0]) {}
        TeacherChoiceCard(sampleTeachers[4]) {}
    }
}

@PreviewTest
@Preview(name = "Waiting for the teacher to answer", widthDp = 360, heightDp = 780)
@Composable
fun TeacherWaiting() = ISATUVisitorTheme {
    DetailScaffold("Your request", onBack = {}) { modifier ->
        WalkInWaitingContent("Prof. Elena Reyes", "Thesis consultation", 582, false, {}, modifier)
    }
}

@PreviewTest
@Preview(name = "The teacher said not now", widthDp = 360, heightDp = 780)
@Composable
fun TeacherNotNow() = ISATUVisitorTheme {
    DetailScaffold("Your request", onBack = {}) { modifier ->
        TeacherAnswerContent(
            "Prof. Elena Reyes", "Can't see you right now", true,
            "Pick another time. What you typed stays, so there's nothing to fill in again.",
            nextTimes, OfficeSummaryDto("IT", "CCI IT Office"), false, {}, {}, {}, modifier,
        )
    }
}

@PreviewTest
@Preview(name = "No answer from the teacher", widthDp = 360, heightDp = 780)
@Composable
fun TeacherNoAnswer() = ISATUVisitorTheme {
    DetailScaffold("Your request", onBack = {}) { modifier ->
        TeacherAnswerContent(
            "Prof. Elena Reyes", "No answer", false,
            "Your request closed with no answer. Pick another time. What you typed stays, so there's nothing to fill in again.",
            nextTimes, OfficeSummaryDto("DEANS", "CCI Dean's Office"), false, {}, {}, {}, modifier,
        )
    }
}

@PreviewTest
@Preview(name = "Home, waiting for a teacher's answer", widthDp = 360, heightDp = 800)
@Composable
fun HomeTeacherWaitingAnswer() = HomeFrame(listOf(teacherVisit("pending_approval", answerBy = at(today, "23:59"), type = "walk_in")))

@PreviewTest
@Preview(name = "Home, booked with a teacher", widthDp = 360, heightDp = 800)
@Composable
fun HomeTeacherBooked() = HomeFrame(listOf(teacherVisit("pending_approval")))

@PreviewTest
@Preview(name = "Home, a teacher accepted", widthDp = 360, heightDp = 800)
@Composable
fun HomeTeacherAccepted() = HomeFrame(listOf(teacherVisit("approved", pass = readyPass)))
