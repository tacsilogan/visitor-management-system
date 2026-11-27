package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.android.tools.screenshot.PreviewTest
import java.time.LocalDate
import ph.edu.isatu.visitor.data.AppReleaseData
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.OfficeSummaryDto
import ph.edu.isatu.visitor.data.PersonDto
import ph.edu.isatu.visitor.data.PersonRequest
import ph.edu.isatu.visitor.data.QrPassDto
import ph.edu.isatu.visitor.data.UserDto
import ph.edu.isatu.visitor.data.VisitorSummaryDto

/*
 * Pictures of the people with a visitor (GROUP_VISITS.md) and of app 0.13.0's other new parts:
 * booking with people, Add a person, the names on the pass with Change, the update card on Home,
 * the gate's answers in the visit details, and agreeing to Version 6 of the notice.
 *
 *   ./gradlew :app:updateDebugScreenshotTest
 *
 * Like VisitorScreenshots.kt, the times are built from today's date: for looking at, not checks.
 */

private val groupDay: LocalDate = LocalDate.now(ManilaZone)

private fun groupAt(time: String) = "$groupDay $time:00"

private val family = listOf(
    PersonRequest("Elena Dela Cruz", isAdult = true),
    PersonRequest("Ramon Dela Cruz", isAdult = true),
    PersonRequest("Miguel Dela Cruz", isAdult = false),
)

private val bookedPeople = family.mapIndexed { index, person ->
    PersonDto(id = index + 1L, fullName = person.fullName, isAdult = person.isAdult)
}

private val groupPass = QrPassDto(
    token = "sample-group-pass-for-screenshots",
    validFrom = groupAt("13:30"),
    validUntil = groupAt("14:30"),
    currentlyValid = true,
)

private fun groupVisit(
    status: String = "approved",
    people: List<PersonDto> = bookedPeople,
    editable: Boolean = true,
    checkedInAt: String? = null,
) = AppointmentDto(
    id = 141,
    registrationCode = "V-2026-000141",
    office = OfficeSummaryDto(code = "REG", name = "Registrar's Office"),
    purpose = "Enrollment",
    subject = "Enrolling our son for the second semester",
    scheduledStartAt = groupAt("14:00"),
    scheduledEndAt = groupAt("14:30"),
    status = status,
    checkedInAt = checkedInAt,
    qrPass = groupPass,
    visitor = VisitorSummaryDto(fullName = "Juan Dela Cruz"),
    people = people,
    peopleEditable = editable,
)

/** The top of the Book tab, as BookingScreen draws it, down to "Anyone coming with you?". */
@Composable
private fun BookFrame(people: List<PersonRequest>) {
    ISATUVisitorTheme {
        Scaffold(
            containerColor = AppBackground,
            topBar = { MainTopBar(unreadCount = 0, busy = false, goldEdge = false, onNotifications = {}, onProfile = {}) },
            bottomBar = { MainBottomBar(selected = 2) {} },
        ) { padding ->
            LazyColumn(
                modifier = Modifier.fillMaxSize().padding(padding),
                contentPadding = PaddingValues(horizontal = 22.dp, vertical = 20.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text("Book a visit", style = MaterialTheme.typography.headlineMedium.copy(fontSize = 24.sp), color = IsatuBlueDark)
                        Text("Choose how you'll visit the campus.", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
                    }
                }
                item {
                    SelectionField(
                        label = "Destination",
                        selectedText = "Registrar's Office",
                        placeholder = "Select an office",
                        options = listOf("REG" to "Registrar's Office"),
                        onSelect = {},
                    )
                }
                item {
                    Text("Visitor type", style = MaterialTheme.typography.titleMedium)
                    Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        VisitTypeButton("Walk-in", selected = false, onClick = {}, modifier = Modifier.weight(1f))
                        VisitTypeButton("Appointment", selected = true, onClick = {}, modifier = Modifier.weight(1f))
                    }
                }
                item { PeopleWithYouCard(people, onAdd = {}, onRemove = {}) }
            }
        }
    }
}

/** The pass screen with the people on it, as AppointmentDetailScreen draws it. */
@Composable
private fun GroupPassFrame(appointment: AppointmentDto, onChange: (() -> Unit)?) {
    ISATUVisitorTheme {
        DetailScaffold("Your pass", onBack = {}) { modifier ->
            LazyColumn(
                modifier = modifier.fillMaxSize(),
                contentPadding = PaddingValues(18.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                passItems(appointment, groupPass, busy = false, directions = null, onHowToFind = {}, onCancel = {}, onChangePeople = onChange)
            }
        }
    }
}

@PreviewTest
@Preview(name = "Book, no one with you yet", widthDp = 360, heightDp = 800)
@Composable
fun BookAlone() = BookFrame(emptyList())

@PreviewTest
@Preview(name = "Book, three people with you", widthDp = 360, heightDp = 800)
@Composable
fun BookWithFamily() = BookFrame(family)

@PreviewTest
@Preview(name = "Add a person", widthDp = 360, heightDp = 800)
@Composable
fun AddPerson() = Box(Modifier.fillMaxSize()) {
    BookFrame(family.take(1))
    ISATUVisitorTheme { AddPersonSheet(onAdd = {}, onDismiss = {}) }
}

@PreviewTest
@Preview(name = "Pass with the people", widthDp = 360, heightDp = 800)
@Composable
fun PassWithPeople() = GroupPassFrame(groupVisit(), onChange = {})

@PreviewTest
@Preview(name = "Change who's coming", widthDp = 360, heightDp = 800)
@Composable
fun ChangeWhoIsComing() = Box(Modifier.fillMaxSize()) {
    GroupPassFrame(groupVisit(), onChange = {})
    ISATUVisitorTheme { ChangePeopleSheet(initial = bookedPeople, saving = false, error = null, onSave = {}, onDismiss = {}) }
}

@PreviewTest
@Preview(name = "Home, a newer app and a group", widthDp = 360, heightDp = 800)
@Composable
fun HomeUpdateAndGroup() = ISATUVisitorTheme {
    Scaffold(
        containerColor = AppBackground,
        topBar = { MainTopBar(unreadCount = 0, busy = false, goldEdge = false, onNotifications = {}, onProfile = {}) },
        bottomBar = { MainBottomBar(selected = 0) {} },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            HomeScreen(
                UserDto(fullName = "Juan Dela Cruz"),
                listOf(groupVisit()),
                onOpenAppointment = {},
                onOpenVisit = {},
                onBook = {},
                appUpdate = AppReleaseData(
                    versionName = "0.13.0",
                    versionCode = 18,
                    whatsNew = "Add the people coming with you.",
                    pageUrl = "https://isatu-visitor.up.railway.app/",
                ),
            )
        }
    }
}

@PreviewTest
@Preview(name = "Visit details after the gate", widthDp = 360, heightDp = 640)
@Composable
fun DetailsAfterTheGate() = ISATUVisitorTheme {
    DetailScaffold("Visit details", onBack = {}) { modifier ->
        Column(modifier.fillMaxSize().padding(18.dp)) {
            PeopleDetailsCard(
                people = listOf(
                    PersonDto(id = 1, fullName = "Elena Dela Cruz", gateStatus = "in", idTypeShown = "national_id", checkedInAt = groupAt("13:52")),
                    PersonDto(id = 2, fullName = "Ramon Dela Cruz", gateStatus = "in", idTypeShown = "drivers_license", checkedInAt = groupAt("13:52"), leftAt = groupAt("14:10")),
                    PersonDto(id = 3, fullName = "Miguel Dela Cruz", isAdult = false, gateStatus = "in", checkedInAt = groupAt("13:52")),
                    PersonDto(id = 4, fullName = "Ana Reyes", gateStatus = "absent"),
                ),
                editable = false,
                onChange = {},
            )
        }
    }
}

@PreviewTest
@Preview(name = "Agree to Version 6", widthDp = 360, heightDp = 800)
@Composable
fun AgreeVersionSix() = ISATUVisitorTheme {
    TermsAgreementScreen(busy = false, onAgree = {}, onSignOut = {})
}

@PreviewTest
@Preview(name = "Pass with the people, small phone", widthDp = 320, heightDp = 568)
@Composable
fun PassWithPeopleSmallPhone() = GroupPassFrame(groupVisit(), onChange = {})
