package ph.edu.isatu.visitor.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.MarkEmailRead
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.School
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import ph.edu.isatu.visitor.data.SignupOptionsData

/** Every ISATU student has an email at this address. */
internal const val STUDENT_EMAIL_DOMAIN = "students.isatu.edu.ph"

internal fun isStudentEmail(email: String): Boolean =
    email.trim().lowercase().substringAfterLast("@", "") == STUDENT_EMAIL_DOMAIN

/** "Who's signing up?": two buttons, one short line each. */
@Composable
internal fun ColumnScope.SignupChoice(onStudent: () -> Unit, onVisitor: () -> Unit, onLogin: () -> Unit = {}) {
    AuthHeading("Create an account", "Who's signing up?")
    ChoiceCard(
        icon = Icons.Rounded.School,
        title = "ISATU Student",
        body = "I study at ISATU",
        tile = BlueSurface,
        tint = IsatuBlue,
        onClick = onStudent,
    )
    ChoiceCard(
        icon = Icons.Rounded.Person,
        title = "Visitor",
        body = "I'm visiting the campus",
        tile = YellowSurface,
        tint = Color(0xFF9A6B00),
        onClick = onVisitor,
    )
    Row(Modifier.align(Alignment.CenterHorizontally), verticalAlignment = Alignment.CenterVertically) {
        Text("Have an account?", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        TextButton(onClick = onLogin) { Text("Log in") }
    }
}

@Composable
private fun ChoiceCard(icon: ImageVector, title: String, body: String, tile: Color, tint: Color, onClick: () -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth().clickable(role = Role.Button, onClick = onClick),
        shape = RoundedCornerShape(18.dp),
        color = Color.White,
        border = BorderStroke(1.5.dp, BorderSoft),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            horizontalArrangement = Arrangement.spacedBy(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier.size(52.dp).background(tile, RoundedCornerShape(15.dp)),
                contentAlignment = Alignment.Center,
            ) { Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(27.dp)) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, color = Ink)
                Text(body, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = MutedInk)
        }
    }
}

internal const val STUDENT_STEPS = 4

/**
 * What a student typed, kept across the steps and while they look at the code screen and come
 * back. Step 4 is the summary.
 */
@Stable
internal class StudentDraft {
    var step by mutableIntStateOf(1)

    /** The step was opened with Edit on the summary, so its button goes straight back there. */
    var fromSummary by mutableStateOf(false)
    var name by mutableStateOf("")

    /** What the Student ID boxes hold: "20234363I" for 2023-4363-I. */
    var studentId by mutableStateOf("")
    var courseCode by mutableStateOf<String?>(null)
    var courseNotListed by mutableStateOf(false)
    var typedCourse by mutableStateOf("")
    var year by mutableStateOf<Int?>(null)
    var section by mutableStateOf("")
    var email by mutableStateOf("")
    var password by mutableStateOf("")
    var agreed by mutableStateOf(false)

    fun stepReady(step: Int): Boolean = when (step) {
        1 -> name.trim().length >= 2 && isCompleteStudentId(studentId)
        2 -> (courseCode != null || (courseNotListed && typedCourse.trim().length >= 2)) && year != null && section.isNotBlank()
        3 -> isStudentEmail(email) && passwordReady(password)
        else -> stepReady(1) && stepReady(2) && stepReady(3) && agreed
    }
}

/**
 * An ISATU student's sign-up, in steps that each fit above the keyboard: who they are, their class,
 * how they'll sign in, then a summary to check before the code is sent. A student is never tracked.
 */
@Composable
internal fun StudentSignupFlow(
    draft: StudentDraft,
    options: SignupOptionsData?,
    busy: Boolean,
    banner: @Composable () -> Unit,
    onLoadOptions: () -> Unit,
    onRegister: (email: String, name: String, password: String, number: String, courseCode: String?, courseName: String?, year: Int, section: String) -> Unit,
    onOpenNotice: () -> Unit,
    onExit: () -> Unit,
) {
    LaunchedEffect(Unit) { onLoadOptions() }
    val focusManager = LocalFocusManager.current
    val back: () -> Unit = {
        focusManager.clearFocus()
        when {
            draft.fromSummary && draft.step < STUDENT_STEPS -> draft.step = STUDENT_STEPS
            draft.step > 1 -> {
                draft.fromSummary = false
                draft.step -= 1
            }
            else -> onExit()
        }
    }
    BackHandler(onBack = back)
    val next: () -> Unit = {
        focusManager.clearFocus()
        draft.step = if (draft.fromSummary) STUDENT_STEPS else draft.step + 1
    }
    val submit: () -> Unit = {
        onRegister(
            draft.email.trim(),
            draft.name.trim(),
            draft.password,
            formatStudentId(draft.studentId),
            if (draft.courseNotListed) null else draft.courseCode,
            if (draft.courseNotListed) draft.typedCourse.trim() else null,
            draft.year ?: 1,
            draft.section,
        )
    }
    val step = draft.step
    AuthFormPage(
        title = "Student account",
        onBack = back,
        busy = busy,
        banner = banner,
        bottom = {
            if (step == STUDENT_STEPS) {
                TermsCheck(draft.agreed, { draft.agreed = it }, onOpenNotice)
                PrimaryButton("Send code", submit, Modifier.fillMaxWidth(), !busy && draft.stepReady(STUDENT_STEPS))
            } else {
                PrimaryButton(if (draft.fromSummary) "Back to summary" else "Next", next, Modifier.fillMaxWidth(), draft.stepReady(step))
            }
        },
    ) {
        FormStepProgress(step, STUDENT_STEPS)
        when (step) {
            1 -> StudentWhoStep(draft)
            2 -> StudentClassStep(draft, options)
            3 -> StudentSignInStep(draft)
            else -> StudentSummaryStep(draft, options) { editStep ->
                draft.fromSummary = true
                draft.step = editStep
            }
        }
    }
}

@Composable
private fun StudentWhoStep(draft: StudentDraft) {
    StepHeading("Who you are", "As written on your ISATU ID.")
    PortalTextField(draft.name, { draft.name = it }, "Full name", { Icon(Icons.Rounded.Person, null) })
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        FieldLabel("Student ID")
        StudentIdField(draft.studentId, { draft.studentId = it })
        FieldNote("Numbers first, then the letter. The dashes are added for you.")
    }
}

@Composable
private fun StudentClassStep(draft: StudentDraft, options: SignupOptionsData?) {
    val courses = options?.departments.orEmpty().flatMap { department ->
        department.courses.map { course -> course.code to "${course.name}, ${department.code}" }
    }
    val courseText = when {
        draft.courseNotListed -> "My course isn't listed"
        draft.courseCode != null -> courses.firstOrNull { it.first == draft.courseCode }?.second.orEmpty()
        else -> ""
    }
    StepHeading("Your class", "Teachers see this when you book them.")
    SimpleSelect(
        label = "Course",
        selectedText = courseText,
        placeholder = if (options == null) "Loading courses…" else "Choose your course",
        options = courses + ("" to "My course isn't listed"),
        onSelect = { value ->
            draft.courseNotListed = value.isEmpty()
            draft.courseCode = value.ifEmpty { null }
        },
    )
    if (draft.courseNotListed) {
        PortalTextField(draft.typedCourse, { draft.typedCourse = it.take(100) }, "Your course, e.g. BSEd Math", { Icon(Icons.Rounded.School, null) })
    }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        FieldLabel("Year")
        ChoiceChips(
            options = (options?.yearLevels ?: listOf(1, 2, 3, 4, 5)).map { it.toString() to yearChip(it) },
            selected = draft.year?.toString(),
            onSelect = { draft.year = it.toIntOrNull() },
        )
    }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        FieldLabel("Section")
        OutlinedTextField(
            value = draft.section,
            onValueChange = { draft.section = it.filter { c -> c.isLetterOrDigit() }.take(10).uppercase() },
            placeholder = { Text("e.g. B") },
            modifier = Modifier.width(150.dp),
            shape = RoundedCornerShape(14.dp),
            singleLine = true,
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters),
            colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = IsatuBlue, unfocusedBorderColor = BorderSoft),
        )
    }
}

@Composable
private fun StudentSignInStep(draft: StudentDraft) {
    var visible by remember { mutableStateOf(false) }
    val wrongEmail = draft.email.isNotBlank() && !isStudentEmail(draft.email)
    StepHeading("Sign-in details", "You'll log in with these.")
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        PortalTextField(draft.email, { draft.email = it.trim() }, "School email", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
        FieldNote(if (wrongEmail) "Use your @$STUDENT_EMAIL_DOMAIN email." else "Your @$STUDENT_EMAIL_DOMAIN email.", wrongEmail)
    }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        PasswordField(draft.password, { draft.password = it }, visible, { visible = !visible }, "Password")
        PasswordChecks(draft.password)
    }
}

@Composable
private fun StudentSummaryStep(draft: StudentDraft, options: SignupOptionsData?, onEdit: (Int) -> Unit) {
    val course = if (draft.courseNotListed) {
        draft.typedCourse.trim()
    } else {
        options?.departments.orEmpty().flatMap { it.courses }.firstOrNull { it.code == draft.courseCode }?.name ?: draft.courseCode.orEmpty()
    }
    StepHeading("Check your details", "Tap Edit to change anything.")
    ReviewSection(
        "Who you are",
        listOf("Name" to draft.name.trim(), "Student ID" to formatStudentId(draft.studentId)),
    ) { onEdit(1) }
    ReviewSection(
        "Your class",
        listOf("Course" to course, "Year and section" to "${draft.year?.let { yearName(it) }.orEmpty()}, ${draft.section}"),
    ) { onEdit(2) }
    ReviewSection("Sign-in details", listOf("School email" to draft.email.trim())) { onEdit(3) }
}

private fun yearName(year: Int): String = "${yearChip(year)} year"

private fun yearChip(year: Int): String = when (year) {
    1 -> "1st"
    2 -> "2nd"
    3 -> "3rd"
    else -> "${year}th"
}

@Composable
internal fun FieldNote(text: String, error: Boolean = false) {
    Text(
        text,
        modifier = Modifier.fillMaxWidth().padding(start = 4.dp),
        style = MaterialTheme.typography.bodySmall,
        color = if (error) MaterialTheme.colorScheme.error else MutedInk,
        fontWeight = if (error) FontWeight.SemiBold else FontWeight.Normal,
    )
}

/** A field that opens a short list, like the booking form's office field. */
@Composable
internal fun SimpleSelect(
    label: String,
    selectedText: String,
    placeholder: String,
    options: List<Pair<String, String>>,
    onSelect: (String) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        FieldLabel(label)
        Box(Modifier.fillMaxWidth()) {
            OutlinedButton(
                onClick = { expanded = true },
                modifier = Modifier.fillMaxWidth().height(54.dp),
                shape = RoundedCornerShape(14.dp),
                contentPadding = PaddingValues(horizontal = 14.dp),
                border = BorderStroke(1.dp, BorderSoft),
            ) {
                Text(
                    selectedText.ifBlank { placeholder },
                    modifier = Modifier.weight(1f),
                    color = if (selectedText.isBlank()) MutedInk else Ink,
                    textAlign = TextAlign.Start,
                    fontWeight = FontWeight.Normal,
                    maxLines = 1,
                )
                Icon(Icons.Rounded.KeyboardArrowDown, contentDescription = "Open $label", tint = MutedInk)
            }
            DropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
                options.forEach { (value, text) ->
                    DropdownMenuItem(
                        text = { Text(text) },
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

/** The 6-digit code from the email. The account exists only once it is entered. */
@Composable
internal fun ColumnScope.SignupCodeForm(
    signup: SignupProgress,
    busy: Boolean,
    onConfirm: (String) -> Unit,
    onResend: () -> Unit,
    onChangeDetails: () -> Unit,
    autoFocus: Boolean = true,
) {
    var code by remember(signup.requestId) { mutableStateOf("") }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(signup.resendAt) {
        while (System.currentTimeMillis() < signup.resendAt) {
            now = System.currentTimeMillis()
            delay(1_000)
        }
        now = System.currentTimeMillis()
    }
    val waitSeconds = ((signup.resendAt - now) / 1000).coerceAtLeast(0)

    Box(
        modifier = Modifier.align(Alignment.CenterHorizontally).padding(top = 6.dp).size(68.dp).background(BlueSurface, CircleShape),
        contentAlignment = Alignment.Center,
    ) { Icon(Icons.Rounded.MarkEmailRead, contentDescription = null, tint = IsatuBlue, modifier = Modifier.size(32.dp)) }
    StepHeading("Check your email", "We sent a 6-digit code to ${signup.email}. It works for 10 minutes.", center = true)
    CodeField(
        code = code,
        onChange = { typed ->
            code = typed
            if (typed.length == 6 && !busy) onConfirm(typed)
        },
        autoFocus = autoFocus,
    )
    PrimaryButton("Confirm", { onConfirm(code) }, Modifier.fillMaxWidth(), !busy && code.length == 6)
    Text(
        "No email? Check Spam too.",
        modifier = Modifier.fillMaxWidth(),
        style = MaterialTheme.typography.bodySmall,
        color = MutedInk,
        textAlign = TextAlign.Center,
    )
    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
        TextButton(onClick = onResend, enabled = !busy && waitSeconds == 0L) {
            Text(if (waitSeconds > 0) "Send again in ${waitSeconds}s" else "Send the code again")
        }
        TextButton(onClick = onChangeDetails) { Text("Change my details") }
    }
}
