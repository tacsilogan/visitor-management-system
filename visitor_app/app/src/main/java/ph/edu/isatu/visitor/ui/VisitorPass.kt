package ph.edu.isatu.visitor.ui

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.graphics.Bitmap
import android.view.WindowManager
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.LightMode
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import ph.edu.isatu.visitor.data.QrPassDto

/** Whether the guard can scan the pass now, later, or no more. */
private enum class PassState { READY, LATER, ENDED }

private fun passState(pass: QrPassDto, nowMillis: Long): PassState = when {
    pass.currentlyValid -> PassState.READY
    (serverTimeMillis(pass.validFrom) ?: 0L) > nowMillis -> PassState.LATER
    else -> PassState.ENDED
}

/**
 * The visitor pass the way the guard needs it: the QR code first, then whose it is.
 * While the pass can be scanned, the screen is at full brightness.
 */
@Composable
internal fun PassCard(payload: String, visitorName: String, reference: String?, pass: QrPassDto) {
    val bitmap = remember(payload) { generateQrBitmap(payload) }
    val state = passState(pass, System.currentTimeMillis())
    if (state == PassState.READY) FullBrightness()
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(22.dp),
    ) {
        Column(
            Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 18.dp, bottom = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            PassStatePill(state, pass)
            bitmap?.let {
                Image(
                    it.asImageBitmap(),
                    "Visitor QR pass",
                    Modifier.background(Color.White).padding(8.dp).size(248.dp),
                )
            }
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(visitorName, style = MaterialTheme.typography.titleMedium)
                reference?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MutedInk) }
            }
            when (state) {
                PassState.READY -> PassHint(
                    Icons.Rounded.LightMode,
                    Color(0xFF9A6700),
                    "Show it with a valid ID. The screen is at full brightness for the scan.",
                )
                PassState.LATER -> PassHint(Icons.Rounded.Info, IsatuBlue, "Show it to the guard with a valid ID when you arrive.")
                PassState.ENDED -> Unit
            }
        }
    }
}

@Composable
private fun PassStatePill(state: PassState, pass: QrPassDto) {
    val (text, background, foreground) = when (state) {
        PassState.READY -> Triple("Ready to scan", Color(0xFFDCFCE7), IsatuGreen)
        PassState.LATER -> Triple(passOpensText(pass.validFrom), BlueSurface, IsatuBlueDark)
        PassState.ENDED -> Triple("No longer active", Color(0xFFE8EEF7), Color(0xFF475569))
    }
    Row(
        Modifier.background(background, RoundedCornerShape(50)).padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        if (state == PassState.READY) {
            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = foreground, modifier = Modifier.size(18.dp))
        }
        Text(text, color = foreground, style = MaterialTheme.typography.labelLarge)
    }
}

/** "Ready from 1:30 PM", "Ready tomorrow from 8:30 AM", or "Ready on Thu, Nov 5 from 8:30 AM". */
private fun passOpensText(validFrom: String): String {
    val from = serverDateTime(validFrom) ?: return "Not ready to scan yet"
    val time = clockTime(from)
    return when (val day = formatDayLabel(from.toLocalDate())) {
        "Today" -> "Ready from $time"
        "Tomorrow" -> "Ready tomorrow from $time"
        else -> "Ready on $day from $time"
    }
}

@Composable
private fun PassHint(icon: ImageVector, tint: Color, text: String) {
    Row(Modifier.widthIn(max = 290.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(18.dp))
        Text(text, style = MaterialTheme.typography.bodySmall, color = MutedInk)
    }
}

/** The visit under the pass: the office, when, where it is, and how to find it. */
@Composable
internal fun PassSummaryCard(title: String, time: String, where: String = "", onHowToFind: (() -> Unit)? = null) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Box(
                Modifier.size(42.dp).background(BlueSurface, RoundedCornerShape(12.dp)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.CalendarMonth, contentDescription = null, tint = IsatuBlue)
            }
            Column(Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.titleMedium)
                Text(time, style = MaterialTheme.typography.bodyMedium, color = MutedInk)
                if (where.isNotBlank()) Text(where, style = MaterialTheme.typography.bodyMedium, color = MutedInk)
            }
        }
        onHowToFind?.let {
            TextButton(onClick = it, modifier = Modifier.fillMaxWidth().padding(bottom = 4.dp)) {
                Text("How to find it", fontWeight = FontWeight.SemiBold)
                Icon(Icons.Rounded.ChevronRight, contentDescription = null, modifier = Modifier.size(18.dp))
            }
        }
    }
}

/** Purpose, subject and the scan window, folded under "Details" until the visitor opens them. */
@Composable
internal fun PassDetails(lines: List<Pair<String, String>>, note: String) {
    var open by rememberSaveable { mutableStateOf(false) }
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(18.dp),
    ) {
        Row(
            Modifier.fillMaxWidth()
                .clickable(role = Role.Button, onClickLabel = if (open) "Hide details" else "Show details") { open = !open }
                .padding(horizontal = 16.dp)
                .height(56.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("Details", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
            Icon(
                if (open) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore,
                contentDescription = null,
                tint = MutedInk,
            )
        }
        AnimatedVisibility(open) {
            Column(
                Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, bottom = 16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                lines.forEach { (label, value) ->
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(label, color = MutedInk, style = MaterialTheme.typography.bodyMedium)
                        Text(value)
                    }
                }
                Text(note, style = MaterialTheme.typography.bodySmall, color = MutedInk)
            }
        }
    }
}

/** Cancelling is rarely what a visitor wants at the gate, so it is a plain text button at the bottom. */
@Composable
internal fun QuietCancelButton(label: String, enabled: Boolean, onClick: () -> Unit) {
    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
        TextButton(onClick = onClick, enabled = enabled) {
            Text(label, color = MaterialTheme.colorScheme.error)
        }
    }
}

/** Full screen brightness while this is shown, then the phone's own setting again. */
@Composable
private fun FullBrightness() {
    val activity = LocalContext.current.findActivity() ?: return
    DisposableEffect(activity) {
        val window = activity.window
        val previous = window.attributes.screenBrightness
        window.attributes = window.attributes.apply {
            screenBrightness = WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_FULL
        }
        onDispose {
            window.attributes = window.attributes.apply { screenBrightness = previous }
        }
    }
}

private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}

internal fun generateQrBitmap(payload: String): Bitmap? = runCatching {
    val matrix = QRCodeWriter().encode(payload, BarcodeFormat.QR_CODE, 720, 720)
    Bitmap.createBitmap(matrix.width, matrix.height, Bitmap.Config.ARGB_8888).apply {
        for (x in 0 until matrix.width) {
            for (y in 0 until matrix.height) {
                setPixel(x, y, if (matrix[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
            }
        }
    }
}.getOrNull()
