package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import com.android.tools.screenshot.PreviewTest
import java.time.LocalDate
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.OfficeSummaryDto
import ph.edu.isatu.visitor.data.SignupCourseDto
import ph.edu.isatu.visitor.data.SignupDepartmentDto
import ph.edu.isatu.visitor.data.SignupOptionsData
import ph.edu.isatu.visitor.data.StudentProfileDto
import ph.edu.isatu.visitor.data.TeacherDto
import ph.edu.isatu.visitor.data.UserDto
import ph.edu.isatu.visitor.data.VisitTeacherDto
import ph.edu.isatu.visitor.navigation.InsideStep
import ph.edu.isatu.visitor.navigation.OfficeDirections

/*
 * Pictures of the screens for ISATU students (SCHOOL_ACCOUNTS.md), with sample data:
 *
 *   ./gradlew :app:updateDebugScreenshotTest
 */

private val day: LocalDate = LocalDate.now(ManilaZone)
private fun time(clock: String) = "$day $clock:00"

private val student = UserDto(
    id = 7,
    fullName = "Mika Dela Cruz",
    role = "student",
    student = StudentProfileDto(studentNumber = "2023-4363-I", courseCode = "IT", course = "IT", department = "CCI", yearLevel = 3, section = "B", label = "IT 3-B"),
)

private fun studentVisit(status: String, arrivedAt: String? = null, answerBy: String? = null) = AppointmentDto(
    id = 211,
    visitType = if (answerBy != null) "walk_in" else "appointment",
    visitorKind = "student",
    arrivedAt = arrivedAt,
    office = OfficeSummaryDto(code = "T12", name = "Prof. Elena Reyes"),
    purpose = "Consultation",
    subject = "Chapter 2 of our capstone",
    scheduledStartAt = time("10:30"),
    scheduledEndAt = time("11:00"),
    status = status,
    teacher = VisitTeacherDto(code = "T12", name = "Prof. Elena Reyes", shortName = "Prof. Reyes", course = "IT", department = "CCI"),
    answerByAt = answerBy,
)

private val facultyRoom = OfficeDirections(
    appointmentId = 211,
    office = "Prof. Reyes",
    building = "CCI Building",
    floor = "2nd floor",
    room = "IT Faculty Room",
    steps = listOf(InsideStep("Take the stairs on your left to the 2nd floor."), InsideStep("The IT Faculty Room is the second door on the right.")),
)

private val signupOptions = SignupOptionsData(
    departments = listOf(
        SignupDepartmentDto("CCI", "College of Computing and Informatics", listOf("IT", "IS", "CS").map { SignupCourseDto(it, it) }),
    ),
)

private val schoolTeachers = listOf(
    TeacherDto(code = "T12", name = "Prof. Elena Reyes", shortName = "Prof. Reyes", course = "IT", department = "CCI", departmentName = "College of Computing and Informatics", availableNow = true, canWalkIn = true),
    TeacherDto(code = "T40", name = "Mr. Jose Reyes", shortName = "Mr. Reyes", course = "BSED", department = "COED", departmentName = "College of Education", nextFreeAt = time("13:00")),
    TeacherDto(code = "T13", name = "Dr. Ana Salazar", shortName = "Dr. Salazar", course = "CS", department = "CCI", departmentName = "College of Computing and Informatics", nextFreeAt = time("14:00")),
)

@PreviewTest
@Preview(name = "Student, who's signing up", widthDp = 360, heightDp = 780)
@Composable
fun StudentChoose() = ISATUVisitorTheme {
    BrandedAuthPage(onBack = {}, busy = false, banner = {}) { SignupChoice(onStudent = {}, onVisitor = {}, onLogin = {}) }
}

/** A student part-way through signing up, at [step], with everything before it filled in. */
private fun studentDraft(step: Int) = StudentDraft().apply {
    this.step = step
    name = "Mika Dela Cruz"
    studentId = "20234363I"
    if (step >= 2) {
        courseCode = "IT"
        year = 3
        section = "B"
    }
    if (step >= 3) {
        email = "mika.cruz@students.isatu.edu.ph"
        password = "Notebook-4410"
    }
    agreed = step == STUDENT_STEPS
}

@Composable
private fun StudentStep(step: Int) = ISATUVisitorTheme {
    StudentSignupFlow(
        draft = studentDraft(step),
        options = signupOptions,
        busy = false,
        banner = {},
        onLoadOptions = {},
        onRegister = { _, _, _, _, _, _, _, _ -> },
        onOpenNotice = {},
        onExit = {},
    )
}

@PreviewTest
@Preview(name = "Student, step 1, who you are", widthDp = 360, heightDp = 780)
@Composable
fun StudentStep1() = StudentStep(1)

@PreviewTest
@Preview(name = "Student, step 2, your class", widthDp = 360, heightDp = 780)
@Composable
fun StudentStep2() = StudentStep(2)

@PreviewTest
@Preview(name = "Student, step 3, sign-in details", widthDp = 360, heightDp = 780)
@Composable
fun StudentStep3() = StudentStep(3)

@PreviewTest
@Preview(name = "Student, step 4, check your details", widthDp = 360, heightDp = 780)
@Composable
fun StudentStep4() = StudentStep(4)

@PreviewTest
@Preview(name = "Student, the code from the email", widthDp = 360, heightDp = 780)
@Composable
fun StudentCode() = ISATUVisitorTheme {
    AuthFormPage("Confirm your email", onBack = {}, busy = false, banner = {}) {
        SignupCodeForm(
            signup = SignupProgress(requestId = 41, email = "mika.cruz@students.isatu.edu.ph", accountType = "student", resendAt = 0L),
            busy = false,
            onConfirm = {},
            onResend = {},
            onChangeDetails = {},
            autoFocus = false,
        )
    }
}

@PreviewTest
@Preview(name = "Student, find any teacher", widthDp = 360, heightDp = 780)
@Composable
fun StudentFindTeacher() = ISATUVisitorTheme {
    Column(Modifier.fillMaxSize().background(AppBackground)) {
        FindTeacherContent(schoolTeachers, false, {}, {}, {}, allowOffice = false)
    }
}

/** Home with the app's own top bar and tabs around it, as MainShell draws them. */
@Composable
private fun StudentHome(appointments: List<AppointmentDto>) = ISATUVisitorTheme {
    Scaffold(
        containerColor = AppBackground,
        topBar = { MainTopBar(unreadCount = 0, busy = false, goldEdge = false, onNotifications = {}, onProfile = {}) },
        bottomBar = { MainBottomBar(selected = 0) {} },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            HomeScreen(student, appointments, onOpenAppointment = {}, onOpenVisit = {}, onBook = {})
        }
    }
}

@PreviewTest
@Preview(name = "Student, Home before the first visit", widthDp = 360, heightDp = 800)
@Composable
fun StudentHomeFirst() = StudentHome(emptyList())

@PreviewTest
@Preview(name = "Welcome, new student", widthDp = 360, heightDp = 780)
@Composable
fun WelcomeStudent() = OpeningOverHome(2800L, greeting = "Welcome, Mika", subline = "Your student account is ready", home = { StudentHome(emptyList()) })

@PreviewTest
@Preview(name = "Student, Home waiting for the teacher", widthDp = 360, heightDp = 800)
@Composable
fun StudentHomeWaiting() = StudentHome(listOf(studentVisit("pending_approval", answerBy = time("23:59"))))

@PreviewTest
@Preview(name = "Student, Home accepted", widthDp = 360, heightDp = 800)
@Composable
fun StudentHomeAccepted() = StudentHome(listOf(studentVisit("approved")))

@PreviewTest
@Preview(name = "Student, the visit accepted", widthDp = 360, heightDp = 780)
@Composable
fun StudentVisitAccepted() = ISATUVisitorTheme {
    DetailScaffold("Your visit", onBack = {}) { modifier ->
        StudentVisitContent(studentVisit("approved"), facultyRoom, busy = false, onDirections = {}, onImHere = {}, onCancel = {}, modifier = modifier)
    }
}

@PreviewTest
@Preview(name = "Student, after I'm here", widthDp = 360, heightDp = 780)
@Composable
fun StudentVisitHere() = ISATUVisitorTheme {
    DetailScaffold("Your visit", onBack = {}) { modifier ->
        StudentVisitContent(studentVisit("approved", arrivedAt = time("10:27")), facultyRoom, busy = false, onDirections = {}, onImHere = {}, onCancel = {}, modifier = modifier)
    }
}
