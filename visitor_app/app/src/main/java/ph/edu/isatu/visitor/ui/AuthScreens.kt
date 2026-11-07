package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat

private enum class AuthMode { Login, Choose, Register, Student, Recover }

private data class PendingRegistration(
    val email: String,
    val name: String,
    val contact: String,
    val password: String,
)

/** What a visitor typed, kept while they look at the code screen and come back to change it. */
@Stable
internal class VisitorDraft {
    var name by mutableStateOf("")
    var email by mutableStateOf("")
    var contact by mutableStateOf("")
    var password by mutableStateOf("")
    var agreed by mutableStateOf(false)

    val ready: Boolean
        get() = name.trim().length >= 2 && email.isNotBlank() && !isStudentEmail(email) && passwordReady(password) && agreed
}

@Composable
fun AuthFlow(state: VisitorUiState, viewModel: AppViewModel) {
    var mode by remember { mutableStateOf(AuthMode.Login) }
    var noticeOpen by remember { mutableStateOf(false) }
    val visitor = remember { VisitorDraft() }
    val student = remember { StudentDraft() }
    val focusManager = LocalFocusManager.current
    val showMode: (AuthMode) -> Unit = {
        viewModel.clearBanner()
        mode = it
    }
    val openNotice = {
        focusManager.clearFocus()
        noticeOpen = true
    }
    val banner: @Composable () -> Unit = {
        state.error?.let { MessageCard(it, true, viewModel::clearBanner) }
        state.message?.let { MessageCard(it, false, viewModel::clearBanner) }
    }
    val signup = state.signup
    // The phone's Back does what the screen's back arrow does. The student's steps handle their own.
    BackHandler(enabled = signup != null || mode == AuthMode.Choose || mode == AuthMode.Register || mode == AuthMode.Recover) {
        when {
            signup != null -> viewModel.cancelSignup()
            mode == AuthMode.Register -> showMode(AuthMode.Choose)
            else -> showMode(AuthMode.Login)
        }
    }
    Box(Modifier.fillMaxSize().background(AppBackground)) {
        when {
            signup != null -> AuthFormPage("Confirm your email", onBack = viewModel::cancelSignup, busy = state.busy, banner = banner) {
                SignupCodeForm(
                    signup = signup,
                    busy = state.busy,
                    onConfirm = viewModel::confirmSignup,
                    onResend = viewModel::resendSignupCode,
                    onChangeDetails = viewModel::cancelSignup,
                )
            }
            mode == AuthMode.Login -> BrandedAuthPage(onBack = null, busy = state.busy, banner = banner) {
                LoginForm(
                    busy = state.busy,
                    onLogin = viewModel::login,
                    onRegister = { showMode(AuthMode.Choose) },
                    onRecover = { showMode(AuthMode.Recover) },
                )
            }
            mode == AuthMode.Choose -> BrandedAuthPage(onBack = { showMode(AuthMode.Login) }, busy = state.busy, banner = banner) {
                SignupChoice(
                    onStudent = { showMode(AuthMode.Student) },
                    onVisitor = { showMode(AuthMode.Register) },
                    onLogin = { showMode(AuthMode.Login) },
                )
            }
            mode == AuthMode.Register -> VisitorSignupPage(
                draft = visitor,
                busy = state.busy,
                banner = banner,
                onBack = { showMode(AuthMode.Choose) },
                onRegister = viewModel::registerVisitor,
                onOpenNotice = openNotice,
                onStudentInstead = {
                    student.email = visitor.email.trim()
                    showMode(AuthMode.Student)
                },
            )
            mode == AuthMode.Student -> StudentSignupFlow(
                draft = student,
                options = state.signupOptions,
                busy = state.busy,
                banner = banner,
                onLoadOptions = viewModel::loadSignupOptions,
                onRegister = viewModel::registerStudent,
                onOpenNotice = openNotice,
                onExit = { showMode(AuthMode.Choose) },
            )
            else -> AuthFormPage("Reset password", onBack = { showMode(AuthMode.Login) }, busy = state.busy, banner = banner) {
                RecoverForm(
                    busy = state.busy,
                    onRequest = viewModel::requestPasswordReset,
                    onReset = viewModel::resetPassword,
                )
            }
        }
        // Over the form, which keeps what was typed.
        if (noticeOpen) {
            TermsNoticeScreen("Back to sign-up") { noticeOpen = false }
        }
    }
}

/** Log in and choosing an account: the navy band with the seal, and a card lifted over it. */
@Composable
internal fun BrandedAuthPage(
    onBack: (() -> Unit)?,
    busy: Boolean,
    banner: @Composable () -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(
        Modifier
            .fillMaxSize()
            .background(AppBackground)
            .verticalScroll(rememberScrollState())
            .imePadding()
            .navigationBarsPadding(),
    ) {
        NavyBand(bottomSpace = 82.dp, underStatusBar = true) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (onBack != null) {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back", tint = Color.White)
                    }
                }
                // The university's full name only where there is no back arrow beside it.
                PortalBrand(subtitle = if (onBack == null) "Iloilo Science and Technology University" else null, onNavy = true)
            }
        }
        Card(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp).liftedOver(48.dp),
            shape = RoundedCornerShape(22.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White),
            elevation = CardDefaults.cardElevation(defaultElevation = 6.dp),
        ) {
            Column(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 24.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                banner()
                content()
                if (busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = IsatuBlue)
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

/**
 * Signing up and resetting a password: the navy title bar, a white page, and the main button in
 * [bottom], which stays above the keyboard.
 */
@Composable
internal fun AuthFormPage(
    title: String,
    onBack: () -> Unit,
    busy: Boolean,
    banner: @Composable () -> Unit,
    bottom: (@Composable ColumnScope.() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(Modifier.fillMaxSize().background(Color.White).imePadding()) {
        NavyTopBar(title = { Text(title, fontWeight = FontWeight.SemiBold) }, onBack = onBack)
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = IsatuBlue)
        Column(
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .then(if (bottom == null) Modifier.navigationBarsPadding() else Modifier)
                .padding(horizontal = 20.dp, vertical = 20.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            banner()
            content()
        }
        if (bottom != null) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .navigationBarsPadding()
                    .padding(start = 20.dp, end = 20.dp, top = 6.dp, bottom = 14.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
                content = bottom,
            )
        }
    }
}

@Composable
internal fun ColumnScope.LoginForm(
    busy: Boolean,
    onLogin: (String, String) -> Unit,
    onRegister: () -> Unit,
    onRecover: () -> Unit,
) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }

    AuthHeading("Log in", "Welcome back.")
    PortalTextField(
        value = email,
        onValueChange = { email = it },
        label = "Email address",
        icon = { Icon(Icons.Rounded.Email, contentDescription = null) },
        keyboardType = KeyboardType.Email,
    )
    PasswordField(password, { password = it }, visible, { visible = !visible }, "Password")
    TextButton(onClick = onRecover, modifier = Modifier.align(Alignment.End)) {
        Text("Forgot password?")
    }
    PrimaryButton(
        "Log in",
        { onLogin(email, password) },
        Modifier.fillMaxWidth(),
        !busy && email.isNotBlank() && password.isNotBlank(),
    )
    Row(Modifier.align(Alignment.CenterHorizontally), verticalAlignment = Alignment.CenterVertically) {
        Text("New here?", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
        TextButton(onClick = onRegister) { Text("Create an account") }
    }
}

/**
 * A visitor's sign-up: one short page, because most visitors come once. One box agrees to the
 * notice and to sharing their location during visits.
 */
@Composable
internal fun VisitorSignupPage(
    draft: VisitorDraft,
    busy: Boolean,
    banner: @Composable () -> Unit,
    onBack: () -> Unit,
    onRegister: (String, String, String, String) -> Unit,
    onOpenNotice: () -> Unit,
    onStudentInstead: () -> Unit,
) {
    val context = LocalContext.current
    var visible by remember { mutableStateOf(false) }
    var permissionRequestInProgress by remember { mutableStateOf(false) }
    var pendingRegistration by remember { mutableStateOf<PendingRegistration?>(null) }
    val locationPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) {
        val registration = pendingRegistration
        pendingRegistration = null
        permissionRequestInProgress = false
        registration?.let { onRegister(it.email, it.name, it.contact, it.password) }
    }
    val submitRegistration = {
        val registration = PendingRegistration(draft.email, draft.name, draft.contact, draft.password)
        val preciseLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_FINE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED
        if (preciseLocationGranted) {
            onRegister(registration.email, registration.name, registration.contact, registration.password)
        } else {
            // Android asks once for precise location, which the agreement above names.
            pendingRegistration = registration
            permissionRequestInProgress = true
            locationPermissionLauncher.launch(
                arrayOf(
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION,
                ),
            )
        }
    }

    AuthFormPage(
        title = "Visitor account",
        onBack = onBack,
        busy = busy,
        banner = banner,
        bottom = {
            NoticeAgreement(draft.agreed, { draft.agreed = it }, includeLocation = true, onOpenNotice = onOpenNotice)
            PrimaryButton(
                "Send code",
                submitRegistration,
                Modifier.fillMaxWidth(),
                !busy && !permissionRequestInProgress && draft.ready,
            )
        },
    ) {
        PortalTextField(draft.name, { draft.name = it }, "Full name", { Icon(Icons.Rounded.Person, null) })
        PortalTextField(draft.email, { draft.email = it }, "Email address", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
        if (isStudentEmail(draft.email)) {
            // A student's school email: they have an account type of their own, with no pass or tracking.
            FieldNote("This is a student email.", error = true)
            TextButton(onClick = onStudentInstead) { Text("Sign up as an ISATU student instead") }
        }
        PortalTextField(draft.contact, { draft.contact = it }, "Contact number", { Icon(Icons.Rounded.Phone, null) }, KeyboardType.Phone)
        PasswordField(draft.password, { draft.password = it }, visible, { visible = !visible }, "Password")
        PasswordChecks(draft.password, compact = true)
    }
}

@Composable
private fun ColumnScope.RecoverForm(
    busy: Boolean,
    onRequest: (String) -> Unit,
    onReset: (String, String, String) -> Unit,
) {
    var email by remember { mutableStateOf("") }
    var token by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }

    StepHeading("Reset your password", "Get a recovery code by email, then choose a new password.")
    PortalTextField(email, { email = it }, "Email address", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
    PrimaryButton("Send recovery code", { onRequest(email) }, Modifier.fillMaxWidth(), !busy && email.isNotBlank())
    HorizontalDivider(color = BorderSoft, modifier = Modifier.padding(vertical = 4.dp))
    PortalTextField(token, { token = it.trim() }, "Recovery code", { Icon(Icons.Rounded.VerifiedUser, null) })
    PasswordField(password, { password = it }, visible, { visible = !visible }, "New password")
    PasswordChecks(password, compact = true)
    PrimaryButton(
        "Save new password",
        { onReset(email, token, password) },
        Modifier.fillMaxWidth(),
        !busy && email.isNotBlank() && token.isNotBlank() && passwordReady(password),
    )
}

/** The title inside a sign-in card. */
@Composable
internal fun AuthHeading(title: String, body: String) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(
            title,
            modifier = Modifier.semantics { heading() },
            style = MaterialTheme.typography.headlineMedium.copy(fontSize = 24.sp, lineHeight = 30.sp),
            color = Ink,
        )
        Text(body, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
    }
}

/** A small label over a field that has none of its own, like the Student ID boxes. */
@Composable
internal fun FieldLabel(text: String) {
    Text(text, style = MaterialTheme.typography.labelLarge, color = Ink)
}

@Composable
internal fun PortalTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    icon: @Composable () -> Unit,
    keyboardType: KeyboardType = KeyboardType.Text,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        leadingIcon = icon,
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(14.dp),
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = IsatuBlue,
            focusedLabelColor = IsatuBlue,
            unfocusedBorderColor = BorderSoft,
        ),
    )
}

@Composable
internal fun PasswordField(
    value: String,
    onValueChange: (String) -> Unit,
    visible: Boolean,
    onToggle: () -> Unit,
    label: String,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        leadingIcon = { Icon(Icons.Rounded.Lock, contentDescription = null) },
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(14.dp),
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
        visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
        trailingIcon = {
            IconButton(onClick = onToggle) {
                Icon(
                    if (visible) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility,
                    contentDescription = if (visible) "Hide password" else "Show password",
                )
            }
        },
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = IsatuBlue,
            focusedLabelColor = IsatuBlue,
            unfocusedBorderColor = BorderSoft,
        ),
    )
}
