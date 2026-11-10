package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.MarkEmailRead
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay

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
