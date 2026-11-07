package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
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
import androidx.compose.foundation.layout.statusBarsPadding
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat

private enum class AuthMode { Login, Choose, Register, Student, Recover }

private data class PendingRegistration(
    val email: String,
    val name: String,
    val contact: String,
    val password: String,
)

@Composable
fun AuthFlow(state: VisitorUiState, viewModel: AppViewModel) {
    var mode by remember { mutableStateOf(AuthMode.Login) }
    var noticeOpen by remember { mutableStateOf(false) }
    val focusManager = LocalFocusManager.current
    val showMode: (AuthMode) -> Unit = {
        viewModel.clearBanner()
        mode = it
    }
    Box(Modifier.fillMaxSize().background(AppBackground)) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .imePadding()
                .navigationBarsPadding(),
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(Color.White)
                    .statusBarsPadding()
                    .padding(horizontal = 20.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (mode != AuthMode.Login || state.signup != null) {
                    IconButton(onClick = {
                        when {
                            state.signup != null -> viewModel.cancelSignup()
                            mode == AuthMode.Register || mode == AuthMode.Student -> showMode(AuthMode.Choose)
                            else -> showMode(AuthMode.Login)
                        }
                    }) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                    }
                }
                PortalBrand(
                    modifier = Modifier.weight(1f),
                    subtitle = if (mode == AuthMode.Login) "Visitor mobile application" else null,
                )
            }

            Column(
                modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 26.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(22.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                ) {
                    Column(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 22.dp, vertical = 26.dp),
                        verticalArrangement = Arrangement.spacedBy(14.dp),
                    ) {
                        state.error?.let { MessageCard(it, true, viewModel::clearBanner) }
                        state.message?.let { MessageCard(it, false, viewModel::clearBanner) }
                        val openNotice = {
                            focusManager.clearFocus()
                            noticeOpen = true
                        }
                        val signup = state.signup
                        if (signup != null) {
                            SignupCodeForm(
                                signup = signup,
                                busy = state.busy,
                                onConfirm = viewModel::confirmSignup,
                                onResend = viewModel::resendSignupCode,
                                onChangeDetails = viewModel::cancelSignup,
                            )
                        } else when (mode) {
                            AuthMode.Login -> LoginForm(
                                busy = state.busy,
                                onLogin = viewModel::login,
                                onRegister = { showMode(AuthMode.Choose) },
                                onRecover = { showMode(AuthMode.Recover) },
                            )
                            AuthMode.Choose -> SignupChoice(
                                onStudent = { showMode(AuthMode.Student) },
                                onVisitor = { showMode(AuthMode.Register) },
                            )
                            AuthMode.Register -> RegisterForm(
                                busy = state.busy,
                                onRegister = viewModel::registerVisitor,
                                onOpenNotice = openNotice,
                                onStudentInstead = { showMode(AuthMode.Student) },
                            )
                            AuthMode.Student -> StudentSignupForm(
                                busy = state.busy,
                                options = state.signupOptions,
                                onLoadOptions = viewModel::loadSignupOptions,
                                onRegister = viewModel::registerStudent,
                                onOpenNotice = openNotice,
                            )
                            AuthMode.Recover -> RecoverForm(
                                busy = state.busy,
                                onRequest = viewModel::requestPasswordReset,
                                onReset = viewModel::resetPassword,
                            )
                        }
                        if (state.busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = IsatuBlue)
                    }
                }
                Spacer(Modifier.height(18.dp))
                Text(
                    "Your visits and their updates appear in this app.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MutedInk,
                    textAlign = TextAlign.Center,
                )
            }
        }
        // Over the form, which keeps what was typed.
        if (noticeOpen) {
            TermsNoticeScreen("Back to sign-up") { noticeOpen = false }
        }
    }
}

@Composable
private fun ColumnScope.LoginForm(
    busy: Boolean,
    onLogin: (String, String) -> Unit,
    onRegister: () -> Unit,
    onRecover: () -> Unit,
) {
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }

    AuthHeading("LOG IN", "Welcome back. Sign in to manage your campus visits.")
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
    HorizontalDivider(color = BorderSoft)
    Row(Modifier.align(Alignment.CenterHorizontally), verticalAlignment = Alignment.CenterVertically) {
        Text("New here?", color = MutedInk)
        TextButton(onClick = onRegister) { Text("Create account") }
    }
}

@Composable
internal fun ColumnScope.RegisterForm(
    busy: Boolean,
    onRegister: (String, String, String, String) -> Unit,
    onOpenNotice: () -> Unit,
    onStudentInstead: () -> Unit = {},
) {
    val context = LocalContext.current
    var name by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var contact by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    var termsAgreed by remember { mutableStateOf(false) }
    var locationAgreed by remember { mutableStateOf(false) }
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
        val registration = PendingRegistration(email, name, contact, password)
        val preciseLocationGranted = ContextCompat.checkSelfPermission(
            context,
            Manifest.permission.ACCESS_FINE_LOCATION,
        ) == PackageManager.PERMISSION_GRANTED
        if (preciseLocationGranted) {
            onRegister(registration.email, registration.name, registration.contact, registration.password)
        } else {
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

    val studentEmail = isStudentEmail(email)
    AuthHeading("VISITOR ACCOUNT", "Register once, then request and monitor your visits in the app.")
    PortalTextField(name, { name = it }, "Full name", { Icon(Icons.Rounded.Person, null) })
    PortalTextField(email, { email = it }, "Email address", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
    if (studentEmail) {
        // A student's school email: they have an account type of their own, with no pass or tracking.
        FieldNote("This is a student email.", error = true)
        TextButton(onClick = onStudentInstead) { Text("Sign up as an ISATU student instead") }
    }
    PortalTextField(contact, { contact = it }, "Contact number", { Icon(Icons.Rounded.Phone, null) }, KeyboardType.Phone)
    PasswordField(password, { password = it }, visible, { visible = !visible }, "Password (at least 10 characters)")
    NoticeChecks(termsAgreed, { termsAgreed = it }, locationAgreed, { locationAgreed = it }, onOpenNotice)
    PrimaryButton(
        "Send code",
        submitRegistration,
        Modifier.fillMaxWidth(),
        !busy && !permissionRequestInProgress && name.trim().length >= 2 && email.isNotBlank() && !studentEmail &&
            password.length >= 10 && termsAgreed && locationAgreed,
    )
    Text(
        "We'll email you a code to confirm the address. Android will ask once for precise location access.",
        style = MaterialTheme.typography.bodySmall,
        color = MutedInk,
        textAlign = TextAlign.Center,
    )
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

    AuthHeading("RESET PASSWORD", "Request a secure recovery code, then create a new password.")
    PortalTextField(email, { email = it }, "Email address", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email)
    PrimaryButton("Request recovery code", { onRequest(email) }, Modifier.fillMaxWidth(), !busy && email.isNotBlank())
    HorizontalDivider(color = BorderSoft)
    PortalTextField(token, { token = it.trim() }, "Recovery code", { Icon(Icons.Rounded.VerifiedUser, null) })
    PasswordField(password, { password = it }, visible, { visible = !visible }, "New password")
    PrimaryButton(
        "Save new password",
        { onReset(email, token, password) },
        Modifier.fillMaxWidth(),
        !busy && email.isNotBlank() && token.isNotBlank() && password.length >= 10,
    )
}

@Composable
internal fun AuthHeading(title: String, body: String) {
    Column(
        modifier = Modifier.fillMaxWidth(),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Text(title, style = MaterialTheme.typography.headlineMedium, color = IsatuBlueDark)
        Text(body, color = MutedInk, textAlign = TextAlign.Center)
    }
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
        shape = RoundedCornerShape(12.dp),
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
        shape = RoundedCornerShape(12.dp),
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
