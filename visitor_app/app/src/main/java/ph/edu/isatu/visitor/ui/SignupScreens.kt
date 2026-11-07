package ph.edu.isatu.visitor.ui

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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Badge
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import ph.edu.isatu.visitor.data.SignupOptionsData

/** Every ISATU student has an email at this address. */
internal const val STUDENT_EMAIL_DOMAIN = "students.isatu.edu.ph"

internal fun isStudentEmail(email: String): Boolean =
    email.trim().lowercase().substringAfterLast("@", "") == STUDENT_EMAIL_DOMAIN

/** "Who's signing up?": the one question that decides how the app works for them. */
@Composable
internal fun ColumnScope.SignupChoice(onStudent: () -> Unit, onVisitor: () -> Unit) {
    AuthHeading("CREATE ACCOUNT", "Who's signing up?")
    ChoiceCard(
        icon = Icons.Rounded.School,
        title = "ISATU student",
        body = "Book a time with any teacher. Sign up with your @$STUDENT_EMAIL_DOMAIN email.",
        onClick = onStudent,
    )
    ChoiceCard(
        icon = Icons.Rounded.Person,
        title = "Visitor",
        body = "Visit an office or a teacher. You get a pass for the gate.",
        onClick = onVisitor,
    )
}

@Composable
private fun ChoiceCard(icon: ImageVector, title: String, body: String, onClick: () -> Unit) {
    Surface(
        modifier = Modifier.fillMaxWidth().clickable(role = Role.Button, onClick = onClick),
        shape = RoundedCornerShape(18.dp),
        color = Color.White,
        border = BorderStroke(1.dp, BorderSoft),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            horizontalArrangement = Arrangement.spacedBy(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier.size(48.dp).background(Color(0xFFEAF2FF), RoundedCornerShape(12.dp)),
                contentAlignment = Alignment.Center,
            ) { Icon(icon, contentDescription = null, tint = IsatuBlue) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                Text(body, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = MutedInk)
        }
    }
}

/** An ISATU student: school email, student number, course, year and section. Never tracked. */
@Composable
internal fun ColumnScope.StudentSignupForm(
    busy: Boolean,
    options: SignupOptionsData?,
    onLoadOptions: () -> Unit,
    onRegister: (email: String, name: String, password: String, number: String, courseCode: String?, courseName: String?, year: Int, section: String) -> Unit,
    onOpenNotice: () -> Unit,
) {
    LaunchedEffect(Unit) { onLoadOptions() }
    var email by remember { mutableStateOf("") }
    var name by remember { mutableStateOf("") }
    var number by remember { mutableStateOf("") }
    var courseCode by remember { mutableStateOf<String?>(null) }
    var typedCourse by remember { mutableStateOf("") }
    var notListed by remember { mutableStateOf(false) }
    var year by remember { mutableStateOf<Int?>(null) }
    var section by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    var termsAgreed by remember { mutableStateOf(false) }

    val emailProblem = when {
        email.isBlank() -> null
        !isStudentEmail(email) -> "Use your @$STUDENT_EMAIL_DOMAIN email."
        else -> null
    }
    val courses = options?.departments.orEmpty().flatMap { department ->
        department.courses.map { course -> course.code to "${course.name}, ${department.code}" }
    }
    val courseText = when {
        notListed -> "My course isn't listed"
        courseCode != null -> courses.firstOrNull { it.first == courseCode }?.second.orEmpty()
        else -> ""
    }

    AuthHeading("STUDENT ACCOUNT", "Book a time with any ISATU teacher. No pass or gate scan needed.")
    PortalTextField(email, { email = it.trim() }, "School email", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
    FieldNote(emailProblem ?: "Only @$STUDENT_EMAIL_DOMAIN emails. We'll send a code there.", emailProblem != null)
    PortalTextField(name, { name = it }, "Full name", { Icon(Icons.Rounded.Person, null) })
    PortalTextField(number, { number = it.filter { c -> c.isDigit() || c == '-' }.take(20) }, "Student number", { Icon(Icons.Rounded.Badge, null) }, KeyboardType.Number)
    SimpleSelect(
        label = "Course",
        selectedText = courseText,
        placeholder = if (options == null) "Loading courses…" else "Choose your course",
        options = courses + ("" to "My course isn't listed"),
        onSelect = { value ->
            notListed = value.isEmpty()
            courseCode = value.ifEmpty { null }
        },
    )
    if (notListed) {
        PortalTextField(typedCourse, { typedCourse = it.take(100) }, "Your course, e.g. BSEd Math", { Icon(Icons.Rounded.School, null) })
    }
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Box(Modifier.weight(1f)) {
            SimpleSelect(
                label = "Year",
                selectedText = year?.let { yearName(it) }.orEmpty(),
                placeholder = "Year",
                options = (options?.yearLevels ?: listOf(1, 2, 3, 4, 5)).map { it.toString() to yearName(it) },
                onSelect = { year = it.toIntOrNull() },
            )
        }
        Box(Modifier.weight(1f)) {
            Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Text("Section", style = MaterialTheme.typography.bodySmall, color = MutedInk)
                OutlinedTextField(
                    value = section,
                    onValueChange = { section = it.filter { c -> c.isLetterOrDigit() }.take(10).uppercase() },
                    placeholder = { Text("e.g. B") },
                    modifier = Modifier.fillMaxWidth().height(54.dp),
                    shape = RoundedCornerShape(10.dp),
                    singleLine = true,
                    colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = IsatuBlue, unfocusedBorderColor = BorderSoft),
                )
            }
        }
    }
    PasswordField(password, { password = it }, visible, { visible = !visible }, "Password")
    FieldNote("At least 10 characters, with a letter and a number.")
    TermsCheck(termsAgreed, { termsAgreed = it }, onOpenNotice)
    val ready = !busy && isStudentEmail(email) && name.trim().length >= 2 && number.length >= 4 &&
        (courseCode != null || (notListed && typedCourse.trim().length >= 2)) && year != null &&
        section.isNotBlank() && password.length >= 10 && termsAgreed
    PrimaryButton(
        "Send code",
        { onRegister(email, name, password, number, courseCode, if (notListed) typedCourse else null, year ?: 1, section) },
        Modifier.fillMaxWidth(),
        ready,
    )
}

private fun yearName(year: Int): String = when (year) {
    1 -> "1st year"
    2 -> "2nd year"
    3 -> "3rd year"
    else -> "${year}th year"
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
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = MutedInk)
        Box(Modifier.fillMaxWidth()) {
            OutlinedButton(
                onClick = { expanded = true },
                modifier = Modifier.fillMaxWidth().height(54.dp),
                shape = RoundedCornerShape(10.dp),
                contentPadding = PaddingValues(horizontal = 14.dp),
                border = BorderStroke(1.dp, BorderSoft),
            ) {
                Text(
                    selectedText.ifBlank { placeholder },
                    modifier = Modifier.weight(1f),
                    color = if (selectedText.isBlank()) MutedInk else Ink,
                    textAlign = TextAlign.Start,
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
        modifier = Modifier.align(Alignment.CenterHorizontally).size(64.dp).background(Color(0xFFEAF2FF), CircleShape),
        contentAlignment = Alignment.Center,
    ) { Icon(Icons.Rounded.MarkEmailRead, contentDescription = null, tint = IsatuBlue, modifier = Modifier.size(30.dp)) }
    AuthHeading("CHECK YOUR EMAIL", "We sent a 6-digit code to ${signup.email}. It works for 10 minutes.")
    OutlinedTextField(
        value = code,
        onValueChange = { typed ->
            code = typed.filter { it.isDigit() }.take(6)
            if (code.length == 6 && !busy) onConfirm(code)
        },
        label = { Text("6-digit code") },
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
        singleLine = true,
        textStyle = TextStyle(fontSize = 24.sp, letterSpacing = 10.sp, textAlign = TextAlign.Center, fontWeight = FontWeight.SemiBold),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = IsatuBlue, focusedLabelColor = IsatuBlue, unfocusedBorderColor = BorderSoft),
    )
    PrimaryButton("Confirm", { onConfirm(code) }, Modifier.fillMaxWidth(), !busy && code.length == 6)
    Text(
        "No email? Check Spam too.",
        modifier = Modifier.fillMaxWidth(),
        style = MaterialTheme.typography.bodySmall,
        color = MutedInk,
        textAlign = TextAlign.Center,
    )
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
        TextButton(onClick = onResend, enabled = !busy && waitSeconds == 0L) {
            Text(if (waitSeconds > 0) "Send again in ${waitSeconds}s" else "Send the code again")
        }
        TextButton(onClick = onChangeDetails) { Text("Change my details") }
    }
}
