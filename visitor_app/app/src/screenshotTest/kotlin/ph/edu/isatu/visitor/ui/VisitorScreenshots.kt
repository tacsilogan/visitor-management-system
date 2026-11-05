package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Canvas
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.Paint
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import com.android.tools.screenshot.PreviewTest
import java.time.LocalDate
import ph.edu.isatu.visitor.data.AppointmentDto
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
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun HomeFrame(appointments: List<AppointmentDto>) {
    ISATUVisitorTheme {
        Scaffold(
            containerColor = AppBackground,
            topBar = {
                TopAppBar(
                    title = { PortalBrand() },
                    actions = {
                        IconButton(onClick = {}) {
                            BadgedBox(badge = { Badge(containerColor = Color(0xFFD92D20), contentColor = Color.White) { Text("1") } }) {
                                Icon(Icons.Rounded.Notifications, contentDescription = "Notifications", tint = IsatuBlueDark)
                            }
                        }
                        IconButton(onClick = {}) {
                            Icon(Icons.Rounded.Person, contentDescription = "Profile", tint = IsatuBlueDark)
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White),
                )
            },
            bottomBar = {
                NavigationBar(containerColor = Color.White, tonalElevation = 8.dp) {
                    listOf(
                        "Home" to Icons.Rounded.Home,
                        "Visits" to Icons.Rounded.CalendarMonth,
                        "Book" to Icons.Rounded.Add,
                    ).forEachIndexed { index, (label, icon) ->
                        NavigationBarItem(
                            selected = index == 0,
                            onClick = {},
                            icon = { Icon(icon, contentDescription = label) },
                            label = { Text(label) },
                            colors = NavigationBarItemDefaults.colors(
                                selectedIconColor = IsatuBlue,
                                selectedTextColor = IsatuBlueDark,
                                indicatorColor = BlueSurface,
                                unselectedIconColor = MutedInk,
                                unselectedTextColor = MutedInk,
                            ),
                        )
                    }
                }
            },
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
