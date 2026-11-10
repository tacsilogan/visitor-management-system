package ph.edu.isatu.visitor.ui

import kotlin.math.min
import kotlin.math.pow

/** How the seal looks at one moment of the opening animation. */
data class OpeningFrame(
    /** The seal's size as it arrives: 1 is full size. */
    val growth: Float,
    val turnDegrees: Float,
    /** How far the centre is open, as a radius in parts of the seal's width. */
    val aperture: Float,
    val flameAlpha: Float,
    val flameScaleX: Float,
    val flameScaleY: Float,
    val glowAlpha: Float,
    val wordsAlpha: Float,
    /** How far below their place the words are, from 1 down to 0. */
    val wordsDrop: Float,
    /** The move to the header: 0 in the middle of the screen, 1 landed. */
    val flight: Float,
)

/**
 * The opening animation, moment by moment. It is the web's sign-in animation (login.js and
 * .auth-seal in style.css) without the checks: the seal winds back with its centre closed, turns
 * one tooth at a time as the centre opens, the fourth brings it upright, the torch lights with
 * the greeting, and it flies to the logo in the header. Plain arithmetic, so it can be tested and
 * drawn at any moment without a phone.
 */
object OpeningTimeline {
    const val WIND_MS = 360L
    private val BEATS = longArrayOf(440, 820, 1200, 1580)
    const val CLICK_MS = 300L
    const val WORDS_AT = 1580L
    private const val WORDS_IN_MS = 220L
    private const val WORDS_OUT_MS = 140L
    private const val LIGHT_AT = 1700L
    private const val FLAME_MS = 460L
    private const val GLOW_MS = 800L
    const val LEAVE_AT = 3200L
    const val LEAVE_MS = 400L
    const val TOTAL_MS = LEAVE_AT + LEAVE_MS

    /** When the status bar turns back to the app's: the blue has left the top of the screen by then. */
    const val REVEAL_AFTER_LEAVING_MS = 120L

    const val FULL_APERTURE = 0.35f
    private const val ARRIVING_SIZE = 0.2f
    private val TURNS = floatArrayOf(-90f, -67.5f, -45f, -22.5f, 0f)
    private val APERTURES = floatArrayOf(0f, 0.14f, 0.225f, 0.29f, FULL_APERTURE)

    private val CLICK = Bezier(0.5f, -0.2f, 0.2f, 1.2f)
    private val WIND = Bezier(0.3f, 0f, 0.1f, 1f)
    private val WORDS = Bezier(0.2f, 0.7f, 0.2f, 1f)
    private val FLAME = Bezier(0.2f, 0.7f, 0.3f, 1f)
    private val GLOW = Bezier(0f, 0f, 0.58f, 1f)

    /** The seal whole and lit, for anyone who has animations switched off. */
    val WHOLE = OpeningFrame(1f, 0f, FULL_APERTURE, 1f, 1f, 1f, 0f, 1f, 0f, 0f)

    /** The frame [ms] after the start, for an animation that starts leaving at [leaveAt]. */
    fun at(ms: Long, leaveAt: Long = LEAVE_AT): OpeningFrame {
        val settled = before(min(ms, leaveAt))
        if (ms <= leaveAt) return settled
        val gone = ms - leaveAt
        val progress = (gone.toFloat() / LEAVE_MS).coerceAtMost(1f)
        // Tapped before the end, the seal finishes itself on the way, so it lands whole.
        val finish = WIND.ease(progress)
        return settled.copy(
            growth = lerp(settled.growth, 1f, finish),
            turnDegrees = lerp(settled.turnDegrees, 0f, finish),
            aperture = lerp(settled.aperture, FULL_APERTURE, finish),
            flameAlpha = lerp(settled.flameAlpha, 1f, finish),
            flameScaleX = lerp(settled.flameScaleX, 1f, finish),
            flameScaleY = lerp(settled.flameScaleY, 1f, finish),
            glowAlpha = lerp(settled.glowAlpha, 0f, finish),
            wordsAlpha = settled.wordsAlpha * (1f - gone.toFloat() / WORDS_OUT_MS).coerceAtLeast(0f),
            flight = easeInOut(progress),
        )
    }

    private fun before(ms: Long): OpeningFrame {
        val growth = lerp(ARRIVING_SIZE, 1f, easeOut((ms.toFloat() / WIND_MS).coerceAtMost(1f)))
        val turn: Float
        val aperture: Float
        if (ms < WIND_MS) {
            val wound = WIND.ease(ms.toFloat() / WIND_MS)
            turn = lerp(0f, TURNS[0], wound)
            aperture = lerp(FULL_APERTURE, APERTURES[0], wound)
        } else {
            val beat = BEATS.indexOfLast { ms >= it }
            if (beat < 0) {
                turn = TURNS[0]
                aperture = APERTURES[0]
            } else {
                val click = CLICK.ease(((ms - BEATS[beat]).toFloat() / CLICK_MS).coerceAtMost(1f))
                turn = lerp(TURNS[beat], TURNS[beat + 1], click)
                aperture = lerp(APERTURES[beat], APERTURES[beat + 1], click).coerceAtLeast(0f)
            }
        }

        // The seal arrives whole and lit, and the flame goes out as the centre closes over it.
        val flameProgress = ((ms - LIGHT_AT).toFloat() / FLAME_MS).coerceIn(0f, 1f)
        val lit = ms < WIND_MS || ms >= LIGHT_AT
        val flameAlpha = if (lit) (if (ms < WIND_MS) 1f else keyframes(flameProgress, FLAME_TIMES, FLAME_ALPHA, FLAME)) else 0f
        val flameX = if (ms < WIND_MS) 1f else keyframes(flameProgress, FLAME_TIMES, FLAME_X, FLAME)
        val flameY = if (ms < WIND_MS) 1f else keyframes(flameProgress, FLAME_TIMES, FLAME_Y, FLAME)
        val glowProgress = ((ms - LIGHT_AT).toFloat() / GLOW_MS).coerceIn(0f, 1f)
        val glow = if (ms < LIGHT_AT) 0f else keyframes(glowProgress, GLOW_TIMES, GLOW_ALPHA, GLOW)

        val words = if (ms < WORDS_AT) 0f else WORDS.ease(((ms - WORDS_AT).toFloat() / WORDS_IN_MS).coerceAtMost(1f))
        return OpeningFrame(growth, turn, aperture, flameAlpha, flameX, flameY, glow, words, 1f - words, 0f)
    }

    private val FLAME_TIMES = floatArrayOf(0f, 0.3f, 0.56f, 0.78f, 1f)
    private val FLAME_ALPHA = floatArrayOf(0f, 1f, 1f, 1f, 1f)
    private val FLAME_X = floatArrayOf(0.3f, 0.84f, 1.07f, 0.98f, 1f)
    private val FLAME_Y = floatArrayOf(0f, 1.24f, 0.93f, 1.04f, 1f)
    private val GLOW_TIMES = floatArrayOf(0f, 0.26f, 1f)
    private val GLOW_ALPHA = floatArrayOf(0f, 1f, 0f)

    private fun keyframes(progress: Float, times: FloatArray, values: FloatArray, ease: Bezier): Float {
        val next = times.indexOfFirst { progress <= it }.coerceAtLeast(1)
        val span = times[next] - times[next - 1]
        return lerp(values[next - 1], values[next], ease.ease((progress - times[next - 1]) / span))
    }

    private fun lerp(from: Float, to: Float, amount: Float) = from + (to - from) * amount

    private fun easeOut(t: Float) = 1f - (1f - t).pow(3)

    private fun easeInOut(t: Float) = if (t < 0.5f) 4f * t * t * t else 1f - (-2f * t + 2f).pow(3) / 2f
}

/** What the opening animation says when the torch lights. */
fun greetingFor(signedIn: Boolean, fullName: String?): String {
    if (!signedIn) return "Welcome to ISATU Visits"
    val firstName = firstNameOf(fullName)
    return if (firstName.isEmpty()) "Welcome back" else "Welcome back, $firstName"
}

/** The name to greet someone by: the first word of their name, and the next one after "Ma." and the like. */
fun firstNameOf(fullName: String?): String {
    val words = fullName?.trim()?.split(Regex("\\s+"))?.filter { it.isNotEmpty() }.orEmpty()
    val first = words.firstOrNull() ?: return ""
    return if (first.endsWith('.') && words.size > 1) "$first ${words[1]}" else first
}

/**
 * The opening animation once more: right after an account is made, with their name and what they
 * now have, or after logging in ([returning]), with "Welcome back" and their name, one line only.
 */
data class Welcome(val firstName: String, val returning: Boolean = false) {
    val greeting: String
        get() = when {
            returning -> if (firstName.isEmpty()) "Welcome back" else "Welcome back, $firstName"
            firstName.isEmpty() -> "Welcome"
            else -> "Welcome, $firstName"
        }

    val subline: String?
        get() = when {
            returning -> null
            else -> "Your visitor account is ready"
        }
}

/** A CSS cubic-bezier() timing curve. */
private class Bezier(private val x1: Float, private val y1: Float, private val x2: Float, private val y2: Float) {
    fun ease(t: Float): Float {
        if (t <= 0f) return 0f
        if (t >= 1f) return 1f
        var low = 0f
        var high = 1f
        repeat(18) {
            val middle = (low + high) / 2f
            if (curve(middle, x1, x2) < t) low = middle else high = middle
        }
        return curve((low + high) / 2f, y1, y2)
    }

    private fun curve(u: Float, first: Float, second: Float): Float {
        val rest = 1f - u
        return 3f * rest * rest * u * first + 3f * rest * u * u * second + u * u * u
    }
}
