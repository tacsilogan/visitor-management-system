package ph.edu.isatu.visitor.navigation

import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min
import kotlin.math.sqrt

/**
 * One position reading as the phone gave it. [elapsedMillis] is the reading's own time on a
 * clock that never jumps (the time since the phone started), not the wall clock.
 */
data class PositionReading(
    val point: GeoPoint,
    val accuracyMeters: Double?,
    val elapsedMillis: Long,
    val speedMetersPerSecond: Double? = null,
)

/**
 * A reading's time on the clock that never jumps, in milliseconds since the phone started:
 * the time the reading was taken, or [nowMillis] when it carries none (some test and mock
 * location apps leave it out, and every reading would then look like a repeat).
 */
fun readingTimeMillis(takenNanos: Long, nowMillis: Long): Long {
    val taken = takenNanos / 1_000_000
    return if (taken in 1..nowMillis) taken else nowMillis
}

/** What [PositionFilter] did with the latest reading. */
enum class PositionQuality {
    /** The reading was used. */
    USED,
    /** The reading was too rough to place the visitor, so the last good position is kept. */
    HELD_WEAK,
    /** The reading was an impossible jump, so the last good position is kept. */
    HELD_JUMP,
}

/** The visitor's position after [PositionFilter] steadied it. */
data class SteadyPosition(
    val point: GeoPoint,
    /** The accuracy the phone reported for the last reading that was used. */
    val accuracyMeters: Double,
    val quality: PositionQuality,
    /** The visitor is standing still. */
    val still: Boolean,
    /** When the last used reading was taken, on the readings' own clock. */
    val elapsedMillis: Long,
)

/**
 * Turns raw GPS readings into a position steady enough to follow on a map. A phone's
 * readings wander by several meters, and now and then one lands tens of meters away (a
 * Wi-Fi or cell-tower position, or a signal bounced off a building). Drawn as they come,
 * the arrow jumps. This keeps the arrow where the visitor can really be:
 *
 * - **Start.** The first position waits for a reading accurate to [START_ACCURACY_METERS]
 *   (after [START_PATIENCE_MILLIS], any reading up to [WEAK_ACCURACY_METERS]), so the arrow
 *   never opens on a coarse guess.
 * - **Weak readings** (worse than [WEAK_ACCURACY_METERS]) never move the position.
 * - **Jumps.** A reading farther away than the visitor could have gone since the last one,
 *   after allowing for both readings' error, is left out. The position only follows when
 *   the readings stay at the new place for [REACQUIRE_MILLIS], and only if the visitor
 *   could have walked there in that time going by the phone's own speed; otherwise, and
 *   for rough readings, they must stay there for [ROUGH_REACQUIRE_MILLIS]. So one bad
 *   reading, or a burst of them beside a building, never moves the arrow.
 * - **Smoothing.** Every other reading pulls the position toward it by its accuracy: a
 *   reading good to 3 m counts for much more than one good to 30 m, and one farther off
 *   than walking and ordinary error explain pulls only a little, so the arrow edges over
 *   instead of jumping. While the phone reports walking speed, the position is kept within
 *   about [MAX_LAG_METERS] of the visitor.
 * - **Standing still.** When the phone reports no speed for [STILL_READINGS] readings in a
 *   row, the position settles on the average of the next [SETTLE_READINGS] readings and
 *   then does not move at all; it lets go as soon as the visitor walks on.
 *
 * It only steadies what GPS reports and never invents accuracy: [SteadyPosition.accuracyMeters]
 * is the phone's own figure. Arrival at an office is still judged from the readings
 * themselves (see [GuidanceEngine]).
 */
class PositionFilter {
    private var started = false
    private var waitingSince = NEVER
    private var seenAt = NEVER

    private var originLatitude = 0.0
    private var originLongitude = 0.0
    private var metersPerLongitude = 0.0

    private var x = 0.0
    private var y = 0.0
    /** How unsure the position is, in square meters. */
    private var variance = 0.0
    private var accuracy = 0.0
    private var usedAt = 0L
    /** Up to when [variance] has been widened for the time that passed. */
    private var widenedAt = 0L
    private var quality = PositionQuality.USED

    private var still = false
    private var stillReadings = 0
    private var slowReadings = 0
    private var movingReadings = 0
    private var awayReadings = 0
    /** Until then the phone's speed is not believed: it said "still" while the visitor walked. */
    private var speedDoubtedUntil = NEVER

    // A run of readings that agree with each other but not with the position.
    private var runReadings = 0
    private var runSince = 0L
    private var runX = 0.0
    private var runY = 0.0
    /** How far the visitor could have walked during the run, by the phone's own speed. */
    private var runReach = 0.0

    /** Forgets everything, so the next reading starts afresh. */
    fun reset() {
        started = false
        waitingSince = NEVER
        seenAt = NEVER
        still = false
        slowReadings = 0
        movingReadings = 0
        awayReadings = 0
        speedDoubtedUntil = NEVER
        runReadings = 0
    }

    /** Takes one reading and returns the steady position, or null while there is none yet. */
    fun update(reading: PositionReading): SteadyPosition? {
        val now = reading.elapsedMillis
        // A repeated or late reading says nothing new.
        if (seenAt != NEVER && now <= seenAt) return current()
        val sinceSeen = if (seenAt == NEVER) 0L else now - seenAt
        if (started && sinceSeen > RESTART_GAP_MILLIS) reset()
        seenAt = now

        val claimed = reading.accuracyMeters?.takeIf { it.isFinite() && it > 0 } ?: UNKNOWN_ACCURACY_METERS
        val radius = max(MIN_ACCURACY_METERS, claimed)
        val speed = reading.speedMetersPerSecond?.takeIf { it.isFinite() && it >= 0 }

        if (!started) {
            if (waitingSince == NEVER) waitingSince = now
            val limit = if (now - waitingSince < START_PATIENCE_MILLIS) START_ACCURACY_METERS else WEAK_ACCURACY_METERS
            if (claimed > limit) return null
            originLatitude = reading.point.latitude
            originLongitude = reading.point.longitude
            metersPerLongitude = METERS_PER_DEGREE * cos(originLatitude * PI / 180)
            started = true
            place(0.0, 0.0, radius, claimed, now)
            return current()
        }

        if (claimed > WEAK_ACCURACY_METERS) {
            quality = PositionQuality.HELD_WEAK
            return current()
        }

        // Without a used reading for a while, standing still is no longer known.
        if (still && now - usedAt > STILL_GAP_MILLIS) wake(radius)

        val readingX = (reading.point.longitude - originLongitude) * metersPerLongitude
        val readingY = (reading.point.latitude - originLatitude) * METERS_PER_DEGREE
        val distance = hypot(readingX - x, readingY - y)
        val seconds = max(MIN_STEP_SECONDS, sinceSeen / 1000.0)
        val reach = min(FAST_SPEED, max(TOP_SPEED, (speed ?: 0.0) * SPEED_HEADROOM)) * seconds

        // Farther than the visitor could have gone, even with both positions off by their error.
        val spread = sqrt(radius * radius + 2 * widen(now, speed))
        if (distance > reach + 2 * spread + JUMP_SLACK_METERS) {
            return heldJump(readingX, readingY, radius, claimed, reach, distance, speed, seconds, now)
        }
        runReadings = 0

        judgeStillness(distance, radius, speed, now)
        // The pull toward the reading: strong when the position is unsure or the reading sharp.
        val unsure = widen(now, speed)
        var gain = unsure / (unsure + radius * radius)
        // Farther off than walking (since the last reading used) and ordinary error explain, the
        // reading is only partly believed: a burst of bad readings beside a building then cannot
        // drag the position away, while the first good reading after a lost signal still counts.
        val walkable = reach / seconds * max(MIN_STEP_SECONDS, (now - usedAt) / 1000.0) + spread
        val doubtful = distance > walkable
        if (doubtful) gain *= (walkable / distance).let { it * it }
        if (still) {
            // Settled on the average of the first readings, the position stays exactly there.
            if (stillReadings >= SETTLE_READINGS) gain = 0.0
            stillReadings += 1
        } else if (!doubtful && speed != null && speed >= MOVING_SPEED && radius <= LAG_ACCURACY_METERS) {
            // Walking: never left more than MAX_LAG_METERS behind, however rough the readings.
            gain = max(gain, speed / (speed + MAX_LAG_METERS))
        }
        x += gain * (readingX - x)
        y += gain * (readingY - y)
        variance = (1 - gain) * unsure
        accuracy = claimed
        usedAt = now
        widenedAt = now
        quality = PositionQuality.USED
        return current()
    }

    /** [variance] after the time since it was last widened: the visitor may have moved. */
    private fun widen(now: Long, speed: Double?): Double {
        val pace = if (still) STILL_PACE else max(WALK_PACE, (speed ?: 0.0) * SPEED_PACE)
        val seconds = max(0.0, (now - widenedAt) / 1000.0)
        return min(MAX_VARIANCE, variance + pace * pace * seconds)
    }

    /** Standing still is taken from the phone's own speed, and given up when it walks on. */
    private fun judgeStillness(distance: Double, radius: Double, speed: Double?, now: Long) {
        val away = distance > max(AWAY_METERS, 1.5 * radius)
        if (still) {
            movingReadings = if (speed != null && speed >= MOVING_SPEED) movingReadings + 1 else 0
            awayReadings = if (away) awayReadings + 1 else 0
            if (movingReadings >= WAKE_READINGS) {
                wake(radius)
            } else if (awayReadings >= WAKE_READINGS) {
                // The readings walked off while the phone still reported no speed.
                speedDoubtedUntil = now + SPEED_DOUBT_MILLIS
                wake(radius)
            }
        } else {
            val believed = speedDoubtedUntil == NEVER || now >= speedDoubtedUntil
            slowReadings = if (believed && speed != null && speed <= STILL_SPEED && !away) slowReadings + 1 else 0
            if (slowReadings >= STILL_READINGS) {
                still = true
                stillReadings = 0
                movingReadings = 0
                awayReadings = 0
            }
        }
    }

    /** Walking again: readings count fully from the first step. */
    private fun wake(radius: Double) {
        still = false
        slowReadings = 0
        movingReadings = 0
        awayReadings = 0
        variance = max(variance, radius * radius / 2)
    }

    private fun heldJump(
        readingX: Double,
        readingY: Double,
        radius: Double,
        claimed: Double,
        reach: Double,
        distance: Double,
        speed: Double?,
        seconds: Double,
        now: Long,
    ): SteadyPosition? {
        quality = PositionQuality.HELD_JUMP
        // A jump says nothing about how far the visitor walked, so it does not make room for itself.
        widenedAt = now

        val walked = (if (speed != null) min(FAST_SPEED, speed * SPEED_HEADROOM + SPEED_SLACK) else TOP_SPEED) * seconds
        val together = runReadings > 0 &&
            hypot(readingX - runX, readingY - runY) <= reach + max(RUN_METERS, 2 * radius)
        if (together) {
            runReadings += 1
            runReach += walked
        } else {
            runReadings = 1
            runSince = now
            runReach = walked
        }
        runX = readingX
        runY = readingY

        // Readings that keep agreeing with each other for a while are where the visitor really is.
        // They are believed soon only when accurate and within walking reach by the phone's own
        // speed: a signal bounced off a building moves the position, not the speed.
        val walkable = claimed <= REACQUIRE_ACCURACY_METERS && distance <= runReach + 2 * radius + RUN_SLACK_METERS
        val patience = if (walkable) REACQUIRE_MILLIS else ROUGH_REACQUIRE_MILLIS
        if (runReadings >= REACQUIRE_READINGS && now - runSince >= patience) {
            still = false
            slowReadings = 0
            place(readingX, readingY, radius, claimed, now)
        }
        return current()
    }

    private fun place(atX: Double, atY: Double, radius: Double, claimed: Double, now: Long) {
        x = atX
        y = atY
        variance = radius * radius
        accuracy = claimed
        usedAt = now
        widenedAt = now
        quality = PositionQuality.USED
        runReadings = 0
    }

    private fun current(): SteadyPosition? {
        if (!started) return null
        val point = GeoPoint(originLatitude + y / METERS_PER_DEGREE, originLongitude + x / metersPerLongitude)
        return SteadyPosition(point, accuracy, quality, still, usedAt)
    }

    companion object {
        /** The first position waits for a reading at least this accurate... */
        const val START_ACCURACY_METERS = 25.0
        /** ...for this long, then takes any reading that is not weak. */
        const val START_PATIENCE_MILLIS = 6_000L
        /** Readings worse than this never move the position (GuidanceEngine's weak-signal limit). */
        const val WEAK_ACCURACY_METERS = 50.0
        /** No reading for this long (the screen was closed): start afresh. */
        const val RESTART_GAP_MILLIS = 30_000L

        /** Phones sometimes claim 1 m; no reading is trusted closer than this. */
        const val MIN_ACCURACY_METERS = 3.0
        const val UNKNOWN_ACCURACY_METERS = 20.0

        /** The fastest a visitor on foot is taken to move, in meters per second. */
        const val TOP_SPEED = 4.0
        /** A reported speed allows steps this many times as long. */
        const val SPEED_HEADROOM = 1.5
        const val SPEED_SLACK = 0.5
        /** The fastest anyone moves on a campus (a vehicle). */
        const val FAST_SPEED = 20.0
        const val JUMP_SLACK_METERS = 2.0

        /** How fast the position grows unsure while walking: about 3 m after one second. */
        const val WALK_PACE = 3.0
        /** While standing still it does not grow unsure at all. */
        const val STILL_PACE = 0.0
        const val SPEED_PACE = 1.2
        /** While the phone reports walking speed, the position trails the visitor by at most this... */
        const val MAX_LAG_METERS = 4.0
        /** ...as long as the readings are at least this accurate. */
        const val LAG_ACCURACY_METERS = 20.0

        /** Standing still: the phone reports this speed or less... */
        const val STILL_SPEED = 0.4
        /** ...for this many readings in a row. */
        const val STILL_READINGS = 3
        /** Standing still, the position is the average of this many readings, then fixed. */
        const val SETTLE_READINGS = 8
        /** Walking again: this speed or more, or readings well away from the spot... */
        const val MOVING_SPEED = 0.8
        const val AWAY_METERS = 6.0
        /** ...for this many readings in a row. */
        const val WAKE_READINGS = 2
        const val STILL_GAP_MILLIS = 5_000L
        const val SPEED_DOUBT_MILLIS = 60_000L

        /** Readings at a new place are believed when this accurate... */
        const val REACQUIRE_ACCURACY_METERS = 25.0
        /** ...and this many of them, each close to the one before... */
        const val REACQUIRE_READINGS = 5
        /** ...have kept it up this long, within walking reach of the position... */
        const val REACQUIRE_MILLIS = 6_000L
        /** ...or this long when they are rougher than that, or farther than the visitor walked. */
        const val ROUGH_REACQUIRE_MILLIS = 20_000L
        const val RUN_METERS = 8.0
        const val RUN_SLACK_METERS = 5.0

        private const val MAX_VARIANCE = 2_500.0
        private const val MIN_STEP_SECONDS = 0.2
        private const val METERS_PER_DEGREE = 111_320.0
        private const val NEVER = Long.MIN_VALUE
    }
}
