package ph.edu.isatu.visitor.ui

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBars
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.MarkEmailRead
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusDirection
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalAutofillManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import kotlin.math.roundToInt
import kotlinx.coroutines.delay
import ph.edu.isatu.visitor.R

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
    val rememberedEmail = remember { viewModel.rememberedEmail }
    val focusManager = LocalFocusManager.current
    val autofill = LocalAutofillManager.current
    // Signed in: the phone may offer to save the password that was typed. Leaving any other way, it doesn't.
    DisposableEffect(autofill) {
        onDispose { if (viewModel.signedInNow) autofill?.commit() else autofill?.cancel() }
    }
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
    val passwordChanged = state.passwordChanged
    // A new password was saved: back to Log in, keeping the message that says so.
    LaunchedEffect(passwordChanged) {
        if (passwordChanged != null) mode = AuthMode.Login
    }
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
                    rememberedEmail = passwordChanged?.email ?: rememberedEmail,
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
                    progress = state.passwordReset,
                    busy = state.busy,
                    rememberedEmail = rememberedEmail,
                    onRequest = viewModel::requestPasswordReset,
                    onReset = viewModel::resetPassword,
                    onDifferentEmail = viewModel::cancelPasswordReset,
                )
            }
        }
        // Over the form, which keeps what was typed.
        if (noticeOpen) {
            TermsNoticeScreen("Back to sign-up") { noticeOpen = false }
        }
    }
}

/**
 * Log in and choosing an account: the card in the middle of the screen, where a thumb reaches its
 * button, between the front of the ISATU building at the top, with the logo in the middle of the
 * space above the card, and a navy wave with the university's name at the bottom. With the keyboard
 * up, the page scrolls and the field being typed in stays in view.
 */
@Composable
internal fun BrandedAuthPage(
    onBack: (() -> Unit)?,
    busy: Boolean,
    banner: @Composable () -> Unit,
    content: @Composable ColumnScope.() -> Unit,
) {
    NavyUnderNavigationBar()
    BoxWithConstraints(Modifier.fillMaxSize().background(AppBackground)) {
        // The whole screen, measured before any keyboard, so the frame doesn't jump when one opens.
        val screen = maxHeight
        val statusBar = WindowInsets.statusBars.getTop(LocalDensity.current)
        Box(
            Modifier
                .fillMaxSize()
                .imePadding()
                .verticalScroll(rememberScrollState()),
        ) {
            CampusTop(Modifier.fillMaxWidth().height(screen * CAMPUS_TOP_SHARE))
            AuthFrame(
                minHeight = screen,
                topInset = statusBar,
                brand = { CentredBrand() },
                card = {
                    Card(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp),
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
                },
                footer = { UniversityWave() },
            )
            if (onBack != null) {
                IconButton(onClick = onBack, modifier = Modifier.statusBarsPadding().padding(start = 4.dp, top = 8.dp)) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back", tint = Color.White)
                }
            }
        }
    }
}

/** How much of the screen the photo at the top takes, as in the mock-up the card was placed in. */
private const val CAMPUS_TOP_SHARE = 0.44f

/**
 * Places [card] a little below the middle of a [minHeight] screen, and [brand] in the middle of the
 * space between the status bar ([topInset], in pixels) and the card. Nothing overlaps: when they
 * don't all fit, they stack and the page is taller than the screen.
 */
@Composable
private fun AuthFrame(
    minHeight: Dp,
    topInset: Int,
    brand: @Composable () -> Unit,
    card: @Composable () -> Unit,
    footer: @Composable () -> Unit,
) {
    Layout(contents = listOf(brand, card, footer), modifier = Modifier.fillMaxWidth()) { (top, middle, bottom), constraints ->
        val loose = constraints.copy(minWidth = 0, minHeight = 0, maxHeight = Constraints.Infinity)
        val brandPlaced = top.first().measure(loose)
        val cardPlaced = middle.first().measure(loose)
        val footerPlaced = bottom.first().measure(loose)
        val gap = 16.dp.roundToPx()
        // The least room the logo needs above the card.
        val brandRoom = topInset + gap + brandPlaced.height + gap
        val height = maxOf(minHeight.roundToPx(), brandRoom + cardPlaced.height + gap + footerPlaced.height)
        // The card centred a touch low, as the eye expects of a card on a screen.
        val centred = (height * 0.5f - cardPlaced.height * 0.46f).roundToInt()
        val cardTop = centred.coerceIn(brandRoom, height - footerPlaced.height - gap - cardPlaced.height)
        val brandTop = topInset + (cardTop - topInset - brandPlaced.height) / 2
        layout(constraints.maxWidth, height) {
            brandPlaced.place((constraints.maxWidth - brandPlaced.width) / 2, brandTop)
            cardPlaced.place(0, cardTop)
            footerPlaced.place(0, height - footerPlaced.height)
        }
    }
}

/**
 * The top of the sign-in screens: the front of the ISATU building, fading into ISATU navy toward the
 * card, with a gold edge. The photo is blurred beforehand (older phones can't blur), so the
 * building's own sign is texture and doesn't compete with the logo; the seal on its wall falls where
 * the navy is solid.
 */
@Composable
private fun CampusTop(modifier: Modifier) {
    Box(modifier.clipToBounds().background(IsatuBlueDark)) {
        Image(
            painter = painterResource(R.drawable.isatu_campus_front),
            contentDescription = null,
            contentScale = ContentScale.Crop,
            alignment = Alignment.TopStart,
            modifier = Modifier.matchParentSize(),
        )
        Box(Modifier.matchParentSize().background(CampusShade))
        GoldEdge(Modifier.align(Alignment.BottomCenter), height = 4.dp)
    }
}

/** Navy over the photo: enough at the top for the status bar and the white logo, solid from 60% down. */
private val CampusShade = Brush.verticalGradient(
    0f to Color(0x7A001E50),
    0.30f to Color(0x8F002660),
    0.50f to Color(0xF0003476),
    0.60f to IsatuBlueDark,
)

/** The seal on a white disc over the app's name: where the opening animation lands on these screens. */
@Composable
private fun CentredBrand(modifier: Modifier = Modifier) {
    val sealHome = LocalSealHome.current
    DisposableEffect(sealHome) {
        onDispose { sealHome?.bounds = null }
    }
    Column(
        modifier = modifier,
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Image(
            painter = painterResource(R.drawable.isatu_seal),
            contentDescription = "ISATU logo",
            modifier = Modifier
                .size(64.dp)
                // Until the animation's seal lands here, that one is the logo on screen.
                .alpha(if (sealHome?.away == true) 0f else 1f)
                .shadow(10.dp, CircleShape)
                .background(Color.White, CircleShape)
                .padding(3.dp)
                .onGloballyPositioned { sealHome?.bounds = it.boundsInRoot() },
        )
        Text(
            "ISATU Visits",
            style = MaterialTheme.typography.titleLarge.copy(fontWeight = FontWeight.Bold),
            color = Color.White,
        )
    }
}

/** The bottom of the sign-in screens: a navy wave edged in gold, with the university's name. */
@Composable
private fun UniversityWave() {
    Box(Modifier.fillMaxWidth()) {
        Canvas(Modifier.matchParentSize()) {
            // A gentle rise from the left, highest a little past the middle, as in the mock-up.
            val unit = 1.dp.toPx()
            val crest = Path().apply {
                moveTo(0f, 34 * unit)
                cubicTo(size.width * 0.25f, 6 * unit, size.width * 0.53f, 6 * unit, size.width, 30 * unit)
            }
            val body = Path().apply {
                addPath(crest)
                lineTo(size.width, size.height)
                lineTo(0f, size.height)
                close()
            }
            drawPath(body, IsatuBlueDark)
            drawPath(crest, IsatuYellow, style = Stroke(width = 3 * unit))
        }
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(top = 46.dp)
                .navigationBarsPadding()
                .padding(start = 16.dp, end = 16.dp, bottom = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                "Iloilo Science and Technology University",
                color = Color.White,
                style = MaterialTheme.typography.bodySmall,
                textAlign = TextAlign.Center,
            )
            Text(
                "Since 1905",
                color = IsatuYellow,
                style = MaterialTheme.typography.labelMedium.copy(fontWeight = FontWeight.SemiBold),
            )
        }
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

/**
 * [rememberedEmail] is the email last signed in with on this phone, so most people only type their
 * password. The keyboard's Next goes to the password, and its Done logs in.
 */
@Composable
internal fun ColumnScope.LoginForm(
    busy: Boolean,
    onLogin: (String, String) -> Unit,
    onRegister: () -> Unit,
    onRecover: () -> Unit,
    rememberedEmail: String = "",
) {
    var email by remember { mutableStateOf(rememberedEmail) }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    val ready = !busy && email.isNotBlank() && password.isNotBlank()
    val logIn = { if (ready) onLogin(email, password) }

    AuthHeading("Log in", "Welcome back.")
    PortalTextField(
        value = email,
        onValueChange = { email = it },
        label = "Email address",
        icon = { Icon(Icons.Rounded.Email, contentDescription = null) },
        keyboardType = KeyboardType.Email,
        imeAction = ImeAction.Next,
        autofill = ContentType.Username + ContentType.EmailAddress,
        onClear = { email = "" },
    )
    PasswordField(
        password,
        { password = it },
        visible,
        { visible = !visible },
        "Password",
        imeAction = ImeAction.Done,
        onImeAction = logIn,
        autofill = ContentType.Password,
    )
    // Closer to the password it belongs to; the button still has its full touch area.
    TextButton(onClick = onRecover, modifier = Modifier.align(Alignment.End).liftedOver(10.dp)) {
        Text("Forgot password?")
    }
    PrimaryButton("Log in", logIn, Modifier.fillMaxWidth(), ready)
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
        // The keyboard's Next walks down the form, and the phone can offer to save the new password.
        PortalTextField(
            draft.name, { draft.name = it }, "Full name", { Icon(Icons.Rounded.Person, null) },
            imeAction = ImeAction.Next, autofill = ContentType.PersonFullName,
        )
        PortalTextField(
            draft.email, { draft.email = it }, "Email address", { Icon(Icons.Rounded.Email, null) }, KeyboardType.Email,
            imeAction = ImeAction.Next, autofill = ContentType.NewUsername + ContentType.EmailAddress,
        )
        if (isStudentEmail(draft.email)) {
            // A student's school email: they have an account type of their own, with no pass or tracking.
            FieldNote("This is a student email.", error = true)
            TextButton(onClick = onStudentInstead) { Text("Sign up as an ISATU student instead") }
        }
        PortalTextField(
            draft.contact, { draft.contact = it }, "Contact number", { Icon(Icons.Rounded.Phone, null) }, KeyboardType.Phone,
            imeAction = ImeAction.Next, autofill = ContentType.PhoneNumber,
        )
        PasswordField(
            draft.password, { draft.password = it }, visible, { visible = !visible }, "Password",
            imeAction = ImeAction.Done, autofill = ContentType.NewPassword,
        )
        PasswordChecks(draft.password, compact = true)
    }
}

/**
 * "Forgot password?", as signing up works: the email first, then the 6-digit code sent to it with a
 * new password. [progress] is set once a code was sent.
 */
@Composable
internal fun ColumnScope.RecoverForm(
    progress: PasswordResetProgress?,
    busy: Boolean,
    rememberedEmail: String,
    onRequest: (String) -> Unit,
    onReset: (email: String, code: String, password: String) -> Unit,
    onDifferentEmail: () -> Unit,
    autoFocus: Boolean = true,
) {
    // Kept above both steps, so "Use a different email" comes back to what was typed.
    var email by remember { mutableStateOf(progress?.email ?: rememberedEmail) }
    if (progress != null) {
        ResetCodeStep(progress, busy, { onRequest(progress.email) }, { code, password -> onReset(progress.email, code, password) }, onDifferentEmail, autoFocus)
        return
    }
    val ready = !busy && email.isNotBlank()
    StepHeading("Reset your password", "Enter the email of your account. We'll send it a 6-digit code.")
    PortalTextField(
        value = email,
        onValueChange = { email = it },
        label = "Email address",
        icon = { Icon(Icons.Rounded.Email, contentDescription = null) },
        keyboardType = KeyboardType.Email,
        imeAction = ImeAction.Done,
        onImeAction = { if (ready) onRequest(email) },
        autofill = ContentType.Username + ContentType.EmailAddress,
        onClear = { email = "" },
    )
    PrimaryButton("Send code", { onRequest(email) }, Modifier.fillMaxWidth(), ready)
}

/** The code from the email, in boxes as when signing up, and the new password under it. */
@Composable
private fun ColumnScope.ResetCodeStep(
    progress: PasswordResetProgress,
    busy: Boolean,
    onResend: () -> Unit,
    onSave: (code: String, password: String) -> Unit,
    onDifferentEmail: () -> Unit,
    autoFocus: Boolean,
) {
    var code by remember(progress.email) { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    val focusManager = LocalFocusManager.current
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(progress.resendAt) {
        while (System.currentTimeMillis() < progress.resendAt) {
            now = System.currentTimeMillis()
            delay(1_000)
        }
        now = System.currentTimeMillis()
    }
    val waitSeconds = ((progress.resendAt - now) / 1000).coerceAtLeast(0)
    val ready = !busy && code.length == 6 && passwordReady(password)
    val save = { if (ready) onSave(code, password) }

    Box(
        modifier = Modifier.align(Alignment.CenterHorizontally).padding(top = 6.dp).size(68.dp).background(BlueSurface, CircleShape),
        contentAlignment = Alignment.Center,
    ) { Icon(Icons.Rounded.MarkEmailRead, contentDescription = null, tint = IsatuBlue, modifier = Modifier.size(32.dp)) }
    StepHeading(
        "Check your email",
        "We sent a 6-digit code to ${progress.email}. It works for ${progress.minutes} minutes.",
        center = true,
    )
    CodeField(
        code = code,
        onChange = { typed ->
            code = typed
            // On to the new password once the code is whole.
            if (typed.length == 6) focusManager.moveFocus(FocusDirection.Next)
        },
        autoFocus = autoFocus,
    )
    PasswordField(
        password,
        { password = it },
        visible,
        { visible = !visible },
        "New password",
        imeAction = ImeAction.Done,
        onImeAction = save,
        autofill = ContentType.NewPassword,
    )
    PasswordChecks(password, compact = true)
    PrimaryButton("Save new password", save, Modifier.fillMaxWidth(), ready)
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
        TextButton(onClick = onDifferentEmail) { Text("Use a different email") }
    }
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

/**
 * A one-line field. [imeAction] is the keyboard's action key: Next moves on by itself, and
 * [onImeAction] answers the others. [autofill] tells the phone's password manager what the field
 * holds. [onClear] shows an x that empties a filled-in field.
 */
@Composable
internal fun PortalTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    icon: @Composable () -> Unit,
    keyboardType: KeyboardType = KeyboardType.Text,
    imeAction: ImeAction = ImeAction.Default,
    onImeAction: (() -> Unit)? = null,
    autofill: ContentType? = null,
    onClear: (() -> Unit)? = null,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        leadingIcon = icon,
        trailingIcon = if (onClear != null && value.isNotEmpty()) {
            {
                IconButton(onClick = onClear) {
                    Icon(Icons.Rounded.Close, contentDescription = "Clear ${label.lowercase()}")
                }
            }
        } else {
            null
        },
        modifier = Modifier.fillMaxWidth().autofill(autofill),
        shape = RoundedCornerShape(14.dp),
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType, imeAction = imeAction),
        keyboardActions = keyboardActionsFor(onImeAction),
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = IsatuBlue,
            focusedLabelColor = IsatuBlue,
            unfocusedBorderColor = BorderSoft,
        ),
    )
}

/** Tells the phone's autofill what a field holds, when it is known. */
private fun Modifier.autofill(type: ContentType?): Modifier =
    if (type == null) this else semantics { contentType = type }

/** The keyboard's action key: the field's own answer, or else what Android does (Next moves on). */
private fun keyboardActionsFor(onImeAction: (() -> Unit)?): KeyboardActions =
    if (onImeAction == null) KeyboardActions.Default else KeyboardActions(onAny = { onImeAction() })

@Composable
internal fun PasswordField(
    value: String,
    onValueChange: (String) -> Unit,
    visible: Boolean,
    onToggle: () -> Unit,
    label: String,
    imeAction: ImeAction = ImeAction.Default,
    onImeAction: (() -> Unit)? = null,
    autofill: ContentType? = null,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(label) },
        leadingIcon = { Icon(Icons.Rounded.Lock, contentDescription = null) },
        modifier = Modifier.fillMaxWidth().autofill(autofill),
        shape = RoundedCornerShape(14.dp),
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = imeAction),
        keyboardActions = keyboardActionsFor(onImeAction),
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
