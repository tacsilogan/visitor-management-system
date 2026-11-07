package ph.edu.isatu.visitor.ui

import android.content.Context
import android.graphics.BitmapFactory
import android.provider.Settings
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.runtime.withFrameMillis
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.ImageShader
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.ShaderBrush
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.drawscope.scale
import androidx.compose.ui.graphics.drawscope.withTransform
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.coerceIn
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.min
import androidx.compose.ui.unit.sp
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.pow
import kotlin.math.sin
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import ph.edu.isatu.visitor.R

private val FieldBlue = Color(0xFF1336B8)
private val SealNavy = Color(0xFF000092)

// Measured from the logo, in parts of the seal's width, as on the web (.auth-seal in style.css).
private const val PLATE_RADIUS = 0.344f
private const val FLAME_LEFT = 0.412f
private const val FLAME_TOP = 0.1813f
private const val FLAME_WIDTH = 0.1653f
private const val FLAME_HEIGHT = 0.1227f
private const val FLAME_FOOT_X = 0.516f
private const val FLAME_FOOT_Y = 0.978f
private const val GLOW_CENTRE_Y = 0.225f
private const val GLOW_RADIUS = 0.15f

// The seal's own teeth, a little inside it, so the blue gear hides behind the seal once landed.
private const val TEETH = 16
private const val TOOTH_ROOT = 0.441f
private const val TOOTH_TIP = 0.488f
private const val WOUND_BACK_DEGREES = -90f

// With no logo on screen to land on (still loading), the seal shrinks in place and fades.
private const val SHRINK_WITHOUT_HEADER = 0.4f

// Animations switched off in the phone's settings: the whole seal, then a quick fade.
private const val STILL_HOLD_MS = 900L
private const val STILL_FADE_MS = 160L

/** Where the header's logo is, so the seal can land on it, and whether the seal is still away. */
class SealHome(away: Boolean) {
    var bounds: Rect? = null
    var away by mutableStateOf(away)
}

val LocalSealHome = staticCompositionLocalOf<SealHome?> { null }

/**
 * The seal in layers: the gear ring turns, and the centre opens behind it. The pictures in
 * drawable-nodpi are cut from phone_tracker/assets/images/isatu-logo.png at twice its size.
 */
class SealArt(val ring: ImageBitmap, core: ImageBitmap, val flame: ImageBitmap) {
    val coreSize = core.width.toFloat()
    val coreBrush = ShaderBrush(ImageShader(core))
}

fun loadSealArt(context: Context): SealArt {
    fun picture(id: Int) = BitmapFactory.decodeResource(context.resources, id).asImageBitmap()
    return SealArt(
        picture(R.drawable.isatu_seal_ring),
        picture(R.drawable.isatu_seal_core),
        picture(R.drawable.isatu_seal_flame),
    )
}

/**
 * The seal's animation when the app opens, over the app, which loads underneath in the meantime.
 * A tap sends the seal to the header straight away. See [OpeningTimeline].
 */
@Composable
fun OpeningAnimation(greeting: () -> String, onReveal: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    val home = LocalSealHome.current
    val still = remember {
        Settings.Global.getFloat(context.contentResolver, Settings.Global.ANIMATOR_DURATION_SCALE, 1f) == 0f
    }
    var art by remember { mutableStateOf<SealArt?>(null) }
    var now by remember { mutableLongStateOf(0L) }
    var leaveAt by remember { mutableLongStateOf(if (still) STILL_HOLD_MS else OpeningTimeline.LEAVE_AT) }
    var landing by remember { mutableStateOf<Rect?>(null) }
    var words by remember { mutableStateOf<String?>(null) }

    fun finish() {
        home?.away = false
        onDone()
    }

    LaunchedEffect(Unit) {
        // Never hold the app up over a picture: without the seal, there is simply no animation.
        val loaded = withContext(Dispatchers.Default) { runCatching { loadSealArt(context) }.getOrNull() }
        if (loaded == null) {
            onReveal()
            finish()
            return@LaunchedEffect
        }
        art = loaded
        if (still) words = greeting()

        val start = withFrameMillis { it }
        var left = false
        var revealed = false
        val total = { leaveAt + if (still) STILL_FADE_MS else OpeningTimeline.LEAVE_MS }
        while (now < total()) {
            now = withFrameMillis { it } - start
            if (words == null && now >= OpeningTimeline.WORDS_AT && now < leaveAt) words = greeting()
            if (!left && now >= leaveAt) {
                left = true
                landing = home?.bounds.takeUnless { still }
            }
            if (!revealed && now >= leaveAt + if (still) 0L else OpeningTimeline.REVEAL_AFTER_LEAVING_MS) {
                revealed = true
                onReveal()
            }
        }
        finish()
    }

    Box(
        Modifier
            .fillMaxSize()
            .clearAndSetSemantics { contentDescription = "ISATU Visitor is opening" }
            .pointerInput(Unit) {
                detectTapGestures { if (now < leaveAt) leaveAt = now }
            },
    ) {
        OpeningStage(
            art = art,
            frame = { if (still) OpeningTimeline.WHOLE else OpeningTimeline.at(now, leaveAt) },
            landing = landing,
            words = words,
            alpha = {
                val gone = now - leaveAt
                when {
                    gone <= 0L -> 1f
                    still -> (1f - gone.toFloat() / STILL_FADE_MS).coerceAtLeast(0f)
                    landing != null -> 1f
                    else -> (1f - gone.toFloat() / OpeningTimeline.LEAVE_MS).coerceIn(0f, 1f)
                }
            },
        )
    }
}

/** One frame of the animation: everything it draws, given what moment it is. */
@Composable
fun OpeningStage(
    art: SealArt?,
    frame: () -> OpeningFrame,
    landing: Rect?,
    words: String?,
    alpha: () -> Float = { 1f },
) {
    BoxWithConstraints(Modifier.fillMaxSize().graphicsLayer { this.alpha = alpha() }) {
        val sealWidth = (min(maxWidth, maxHeight) * 0.49f).coerceIn(150.dp, 184.dp)
        val centreY = maxHeight / 2 - 40.dp
        Canvas(Modifier.fillMaxSize()) {
            drawOpening(art, frame(), sealWidth.toPx(), centreY.toPx(), landing)
        }
        if (words != null) OpeningWords(words, frame, top = centreY + sealWidth / 2 + 24.dp)
    }
}

@Composable
private fun BoxScope.OpeningWords(text: String, frame: () -> OpeningFrame, top: Dp) {
    Text(
        text,
        color = Color.White,
        // The serif of the lettering on the seal, as on the web's sign-in.
        fontFamily = FontFamily.Serif,
        fontSize = 22.sp,
        lineHeight = 28.sp,
        textAlign = TextAlign.Center,
        maxLines = 2,
        overflow = TextOverflow.Ellipsis,
        modifier = Modifier
            .align(Alignment.TopCenter)
            .padding(top = top, start = 24.dp, end = 24.dp)
            .graphicsLayer {
                val now = frame()
                this.alpha = now.wordsAlpha
                translationY = now.wordsDrop * 6.dp.toPx()
            },
    )
}

private fun DrawScope.drawOpening(art: SealArt?, frame: OpeningFrame, seal: Float, centreY: Float, landing: Rect?) {
    val flight = frame.flight
    if (art == null || flight <= 0f) drawRect(FieldBlue)
    if (art == null) return

    val centreX = size.width / 2f
    val moveX = (landing?.center?.x ?: centreX) - centreX
    val moveY = (landing?.center?.y ?: centreY) - centreY
    val shrink = landing?.let { it.height / seal } ?: SHRINK_WITHOUT_HEADER
    val sealScale = shrink.pow(flight) * frame.growth

    withTransform({
        // The curve meets the header from below, so it doesn't cross the name beside the logo.
        translate(centreX + moveX * flight.pow(0.8f), centreY + moveY * flight.pow(1.3f))
        scale(sealScale, sealScale, pivot = Offset.Zero)
    }) {
        if (flight > 0f) {
            // The same gear, filling the screen at first, shrinks away to show the app.
            val farthest = hypot(max(centreX, size.width - centreX), max(centreY, size.height - centreY))
            val cover = 1.04f * farthest / (seal * TOOTH_ROOT)
            rotate(WOUND_BACK_DEGREES * (1f - flight), pivot = Offset.Zero) {
                scale(cover.pow(1f - flight), pivot = Offset.Zero) {
                    drawPath(gearOutline(seal), FieldBlue)
                }
            }
        }
        drawSeal(art, frame, seal)
    }
}

private fun gearOutline(seal: Float) = Path().apply {
    val corners = listOf(-8.5f to TOOTH_ROOT, -4.2f to TOOTH_TIP, 4.2f to TOOTH_TIP, 8.5f to TOOTH_ROOT)
    for (tooth in 0 until TEETH) {
        corners.forEach { (offset, reach) ->
            val angle = Math.toRadians(tooth * 360.0 / TEETH + offset)
            val x = seal * reach * cos(angle).toFloat()
            val y = seal * reach * sin(angle).toFloat()
            if (isEmpty) moveTo(x, y) else lineTo(x, y)
        }
    }
    close()
}

/** The seal around the point (0, 0). */
private fun DrawScope.drawSeal(art: SealArt, frame: OpeningFrame, seal: Float) {
    drawCircle(SealNavy, radius = seal * PLATE_RADIUS, center = Offset.Zero)

    // The centre, opening like a lens: a circle filled with the picture, for a smooth edge.
    val scaleToSeal = seal / art.coreSize
    withTransform({
        scale(scaleToSeal, scaleToSeal, pivot = Offset.Zero)
        translate(-art.coreSize / 2f, -art.coreSize / 2f)
    }) {
        drawCircle(
            art.coreBrush,
            radius = frame.aperture * art.coreSize,
            center = Offset(art.coreSize / 2f, art.coreSize / 2f),
        )
    }

    if (frame.flameAlpha > 0f || frame.glowAlpha > 0f) {
        val lens = Path().apply { addOval(Rect(Offset.Zero, frame.aperture * seal)) }
        clipPath(lens) {
            drawGlow(frame.glowAlpha, seal)
            drawFlame(art.flame, frame, seal)
        }
    }

    rotate(frame.turnDegrees, pivot = Offset.Zero) {
        drawPicture(art.ring, -seal / 2f, -seal / 2f, seal, seal)
    }
}

private fun DrawScope.drawGlow(alpha: Float, seal: Float) {
    if (alpha <= 0f) return
    val centre = Offset(0f, seal * (GLOW_CENTRE_Y - 0.5f))
    val radius = seal * GLOW_RADIUS
    drawCircle(
        Brush.radialGradient(
            0f to Color(0xF2FFF6C4),
            0.38f to Color(0x80FFE882),
            0.72f to Color(0x1FFFD63C),
            1f to Color(0x00FFCC00),
            center = centre,
            radius = radius,
        ),
        radius = radius,
        center = centre,
        alpha = alpha,
    )
}

private fun DrawScope.drawFlame(flame: ImageBitmap, frame: OpeningFrame, seal: Float) {
    if (frame.flameAlpha <= 0f) return
    val left = seal * (FLAME_LEFT - 0.5f)
    val top = seal * (FLAME_TOP - 0.5f)
    val width = seal * FLAME_WIDTH
    val height = seal * FLAME_HEIGHT
    val foot = Offset(left + width * FLAME_FOOT_X, top + height * FLAME_FOOT_Y)
    scale(frame.flameScaleX, frame.flameScaleY, pivot = foot) {
        drawPicture(flame, left, top, width, height, frame.flameAlpha)
    }
}

private fun DrawScope.drawPicture(
    picture: ImageBitmap,
    left: Float,
    top: Float,
    width: Float,
    height: Float,
    alpha: Float = 1f,
) {
    withTransform({
        translate(left, top)
        scale(width / picture.width, height / picture.height, pivot = Offset.Zero)
    }) {
        drawImage(picture, alpha = alpha)
    }
}
