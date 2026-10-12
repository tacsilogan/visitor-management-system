package ph.edu.isatu.visitor.navigation

import java.util.Locale
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

enum class DistanceUnit { METERS, FEET }

/** Where the target is, seen from the direction the visitor is facing or walking. */
enum class RelativeSide { AHEAD, SLIGHT_LEFT, SLIGHT_RIGHT, LEFT, RIGHT, BEHIND }

enum class TargetKind { OFFICE, GATE, ENTRY }

/** How an arrival was confirmed: by the phone's GPS, or by the visitor tapping "I'm here". */
enum class ArrivalMethod { GPS, VISITOR }

/**
 * Where the visitor is being guided. [point] is null when the office has no pin yet.
 * [startLine] opens the first spoken direction, e.g. "Next stop: Dean's Office".
 * [appointmentId] is the office stop, used to report the arrival; [arrivedBy] is set when
 * the server already has this stop's arrival (for example after reopening the app).
 */
data class GuidanceTarget(
    val key: String,
    val name: String,
    val kind: TargetKind,
    val point: GeoPoint?,
    val detail: String = "",
    val startLine: String = "Heading to $name",
    val appointmentId: Long? = null,
    val arrivedBy: ArrivalMethod? = null,
    /** The office code, so the walking path arrives along that office's own routes. */
    val officeCode: String? = null,
)

/** One position reading. [timeMillis] is when the app received it. */
data class LocationFix(
    val point: GeoPoint,
    val accuracyMeters: Double?,
    val timeMillis: Long,
    val speedMetersPerSecond: Double? = null,
    val courseDegrees: Double? = null,
)

/** The campus outline and the server's exit rules (campus_map.php exit_policy). */
data class CampusArea(
    val boundary: List<GeoPoint>,
    val bufferMeters: Double = 25.0,
    val minimumAccuracyMeters: Double = 50.0,
    val exitSeconds: Int = 300,
)

/**
 * The server's arrival rule (campus_map.php): GPS must place the visitor within
 * [distanceMeters] of the pin, from readings accurate to [maxAccuracyMeters].
 */
data class ArrivalRule(val distanceMeters: Double = 3.0, val maxAccuracyMeters: Double = 8.0)

enum class GuidancePhase {
    WAITING_FOR_FIX,
    WEAK_SIGNAL,
    NO_TARGET,
    NO_PIN,
    NAVIGATING,
    /** The pin is within GPS uncertainty: close, but GPS can't confirm the exact spot. */
    VERY_CLOSE,
    ARRIVED,
    OUTSIDE_CAMPUS,
}

/**
 * Directions along recorded walkways (a [WalkingPath]); absent while the app points straight
 * at the target because no walkway leads there.
 */
data class RouteGuidance(
    /** Walking distance left along the path, including the way back onto it. */
    val remainingMeters: Double,
    /** How far the visitor is from the walkway. */
    val offPathMeters: Double,
    val nextTurn: TurnDirection? = null,
    /** Walking distance to [nextTurn]. */
    val nextTurnMeters: Double? = null,
)

data class GuidanceSnapshot(
    val phase: GuidancePhase,
    val target: GuidanceTarget? = null,
    /** Straight-line distance to the target's pin. */
    val distanceMeters: Double? = null,
    /**
     * Compass direction to walk: along the walkway path when there is one, otherwise
     * straight at the target.
     */
    val bearingDegrees: Double? = null,
    val side: RelativeSide? = null,
    val accuracyMeters: Double? = null,
    val outsideSinceMillis: Long? = null,
    val arrivedBy: ArrivalMethod? = null,
    val route: RouteGuidance? = null,
)

/** [urgent] prompts interrupt whatever is being said. */
data class VoicePrompt(val text: String, val urgent: Boolean = false)

/** An arrival to report to the server, sent once when it happens. */
data class ArrivalEvent(
    val target: GuidanceTarget,
    val method: ArrivalMethod,
    val distanceMeters: Double?,
    val accuracyMeters: Double?,
)

data class GuidanceUpdate(
    val snapshot: GuidanceSnapshot,
    val prompts: List<VoicePrompt>,
    val arrival: ArrivalEvent? = null,
)

/**
 * Turns position readings into walking directions and spoken prompts toward one pin.
 * It never claims more than GPS knows:
 *
 * - **Arrived** only when GPS places the visitor within the arrival distance (3 m by
 *   default) of the pin, from a reading accurate to 8 m or better, for three readings in a
 *   row, or when the visitor taps "I'm here". The GPS accuracy circle is never used as an
 *   arrival radius. Arrival is final for that target, so indoor GPS drift never restarts
 *   directions during a meeting.
 * - **Very close** when the pin is inside the reading's own uncertainty: the app says the
 *   office is within about that distance and asks the visitor to look for it.
 * - Distances are spoken only from readings accurate to 20 m: first the distance and side,
 *   then at 200, 100, 50, 25 ("just ahead"), and 10 m. Each is spoken once, never within
 *   6 s of another prompt. Readings worse than 50 m show a weak-signal notice.
 * - Outside the campus (past the server's 25 m allowance), directions pause and the
 *   visitor is warned once; coming back is acknowledged.
 * - The position may be one that [PositionFilter] steadied; the reading it came from is
 *   then passed too, and arrival is judged from that reading alone.
 * - With a [WalkingPath] along the walkways an administrator recorded, directions follow
 *   the path instead of a straight line: distances are walking distances, each turn is
 *   announced ahead ("In 20 meters, turn left") and at the turn ("Turn left now"), and
 *   wandering more than about 20 m off the path or walking the wrong way is pointed out.
 *   Arrival is still judged at the pin itself.
 */
class GuidanceEngine {
    private var targetKey: String? = null
    private var started = false
    private var arrivedBy: ArrivalMethod? = null
    private var arrivalStreak = 0
    private var veryCloseStreak = 0
    private var veryCloseAnnounced = false
    private var nextMilestone = 0
    private var closestMeters = Double.MAX_VALUE
    private var noPinAnnounced = false
    private var outsideSince: Long? = null
    private var outsideWarned = false
    private var lastPromptAt: Long? = null
    private var lastCorrectionAt: Long? = null

    // Along a walkway path.
    private var nextRouteMilestone = 0
    private var closestRemaining = Double.MAX_VALUE
    private var offPathStreak = 0
    private var offPathWarned = false
    private var justAheadSaid = false
    private val announcedTurns = mutableListOf<AnnouncedTurn>()

    private class AnnouncedTurn(val point: GeoPoint) {
        var ahead = false
        var atTurn = false
    }

    fun update(
        fix: LocationFix?,
        compassHeading: Double?,
        target: GuidanceTarget?,
        campus: CampusArea?,
        unit: DistanceUnit,
        arrival: ArrivalRule = ArrivalRule(),
        path: WalkingPath? = null,
        /** The reading as GPS gave it, when [fix] is a steadied position: arrival is judged from it. */
        reading: LocationFix? = fix,
    ): GuidanceUpdate {
        val prompts = mutableListOf<VoicePrompt>()
        if (target?.key != targetKey) resetTarget(target)
        if (arrivedBy == null && target?.arrivedBy != null) arrivedBy = target.arrivedBy
        val accuracy = fix?.accuracyMeters

        if (campus == null || campus.boundary.size < 3) {
            outsideSince = null
            outsideWarned = false
        } else if (fix != null && (accuracy == null || accuracy <= campus.minimumAccuracyMeters)) {
            val inside = GeoMath.insideCampus(campus.boundary, fix.point, campus.bufferMeters)
            if (!inside && outsideSince == null) outsideSince = fix.timeMillis
            if (inside && outsideSince != null) {
                if (outsideWarned) prompts += say(fix.timeMillis, "Welcome back to the campus.", urgent = true)
                outsideSince = null
                outsideWarned = false
            }
        }

        val point = target?.point
        val distance = if (fix != null && point != null) GeoMath.distanceMeters(fix.point, point) else null
        // A path only counts when it leads to this target.
        val walkway = path?.takeIf { point != null && it.target == point }
        val route = if (fix != null && walkway != null) routeGuidance(walkway) else null
        val bearing = when {
            fix == null || point == null -> null
            // Along the path: toward a point a few meters ahead on it, so the arrow turns with it.
            // On the walkway itself it follows the line, not the position's wander beside it.
            walkway != null -> GeoMath.bearingDegrees(
                if (walkway.joinMeters <= 0.0) walkway.points.first() else fix.point,
                walkway.pointAt(walkway.joinMeters + LOOK_AHEAD_METERS),
            )
            else -> GeoMath.bearingDegrees(fix.point, point)
        }
        val heading = chooseHeading(fix, compassHeading)
        val side = if (bearing != null && heading != null) sideFor(GeoMath.signedDifference(bearing, heading)) else null
        fun result(phase: GuidancePhase, event: ArrivalEvent? = null) = GuidanceUpdate(
            GuidanceSnapshot(phase, target, distance, bearing, side, accuracy, outsideSince, arrivedBy, route),
            prompts,
            event,
        )

        if (outsideSince != null && fix != null) {
            if (!outsideWarned) {
                outsideWarned = true
                val minutes = max(1, (campus?.exitSeconds ?: 300) / 60)
                prompts += say(
                    fix.timeMillis,
                    "You are outside the campus. Your visit ends automatically if you stay outside " +
                        "for $minutes ${plural(minutes, "minute")}.",
                    urgent = true,
                )
            }
            return result(GuidancePhase.OUTSIDE_CAMPUS)
        }
        if (target == null) return result(GuidancePhase.NO_TARGET)
        if (arrivedBy != null) return result(GuidancePhase.ARRIVED)
        if (fix == null) return result(GuidancePhase.WAITING_FOR_FIX)
        if (distance == null) {
            if (!noPinAnnounced) {
                noPinAnnounced = true
                prompts += say(fix.timeMillis, "${target.name} isn't on the campus map yet. Please ask a guard for directions.")
            }
            return result(GuidancePhase.NO_PIN)
        }
        if (accuracy != null && accuracy > WEAK_SIGNAL_METERS) {
            arrivalStreak = 0
            veryCloseStreak = 0
            return result(GuidancePhase.WEAK_SIGNAL)
        }

        // Arrival: the exact spot, from accurate readings, held for a few seconds. It is judged
        // from the readings themselves, never from a steadied position.
        val readingDistance = if (reading != null && point != null) GeoMath.distanceMeters(reading.point, point) else null
        val readingAccuracy = reading?.accuracyMeters
        val confirmable = readingAccuracy != null && readingAccuracy <= arrival.maxAccuracyMeters
        arrivalStreak = if (confirmable && readingDistance != null && readingDistance <= arrival.distanceMeters) arrivalStreak + 1 else 0
        if (arrivalStreak >= ARRIVAL_READINGS) {
            arrivedBy = ArrivalMethod.GPS
            started = true
            prompts += say(fix.timeMillis, arrivalLine(target), urgent = true)
            return result(GuidancePhase.ARRIVED, ArrivalEvent(target, ArrivalMethod.GPS, readingDistance, readingAccuracy))
        }

        // Very close: the pin is inside this reading's uncertainty, so GPS can't say more.
        val trustworthy = accuracy == null || accuracy <= PROMPT_MAX_ACCURACY_METERS
        if (trustworthy && accuracy != null && distance <= max(accuracy, arrival.distanceMeters)) {
            veryCloseStreak += 1
            if (veryCloseStreak >= ARRIVAL_READINGS && arrivalStreak == 0 && !veryCloseAnnounced) {
                veryCloseAnnounced = true
                started = true
                prompts += say(fix.timeMillis, veryCloseLine(target))
            }
            return result(GuidancePhase.VERY_CLOSE)
        }
        veryCloseStreak = 0

        // Spoken directions only from readings good enough to be worth saying.
        if (!trustworthy) return result(GuidancePhase.NAVIGATING)
        if (walkway != null && route != null) {
            routePrompts(fix, target, walkway, route, heading, accuracy, unit, prompts)
            return result(GuidancePhase.NAVIGATING)
        }
        closestMeters = min(closestMeters, distance)
        if (!started) {
            started = true
            while (nextMilestone < MILESTONES.size && MILESTONES[nextMilestone] >= distance) nextMilestone++
            prompts += say(fix.timeMillis, "${target.startLine}. It's ${spokenDistance(distance, unit)} away${sideClause(side)}.")
        } else {
            val crossed = crossedMilestone(distance)
            if (crossed != null && canSpeak(fix.timeMillis)) {
                nextMilestone = crossed + 1
                prompts += say(fix.timeMillis, milestoneLine(target, MILESTONES[crossed], distance, side, unit))
            } else if (distance > closestMeters + WRONG_WAY_METERS && canSpeak(fix.timeMillis) &&
                lastCorrectionAt.let { it == null || fix.timeMillis - it >= CORRECTION_GAP_MILLIS }
            ) {
                lastCorrectionAt = fix.timeMillis
                closestMeters = distance
                prompts += say(
                    fix.timeMillis,
                    "You're moving away from ${target.name}. It's ${spokenDistance(distance, unit)} away${sideClause(side)}.",
                )
            }
        }
        return result(GuidancePhase.NAVIGATING)
    }

    /**
     * The visitor tapped "I'm here". Marks the current target as arrived and returns the
     * confirmation to speak and report, or null when there is nothing to confirm.
     */
    fun confirmArrival(fix: LocationFix?, target: GuidanceTarget?, nowMillis: Long): GuidanceUpdate? {
        if (target == null || arrivedBy != null) return null
        if (target.key != targetKey) resetTarget(target)
        arrivedBy = ArrivalMethod.VISITOR
        started = true
        val point = target.point
        val distance = if (fix != null && point != null) GeoMath.distanceMeters(fix.point, point) else null
        val text = when (target.kind) {
            TargetKind.OFFICE -> "Arrival confirmed at ${target.name}."
            TargetKind.GATE, TargetKind.ENTRY -> "Arrival confirmed. Show your pass to the guard to check out."
        }
        val prompts = listOf(say(nowMillis, text, urgent = true))
        val snapshot = GuidanceSnapshot(
            GuidancePhase.ARRIVED, target, distance, null, null, fix?.accuracyMeters, outsideSince, ArrivalMethod.VISITOR,
        )
        return GuidanceUpdate(snapshot, prompts, ArrivalEvent(target, ArrivalMethod.VISITOR, distance, fix?.accuracyMeters))
    }

    private fun resetTarget(target: GuidanceTarget?) {
        targetKey = target?.key
        started = false
        arrivedBy = target?.arrivedBy
        arrivalStreak = 0
        veryCloseStreak = 0
        veryCloseAnnounced = false
        nextMilestone = 0
        closestMeters = Double.MAX_VALUE
        noPinAnnounced = false
        lastCorrectionAt = null
        nextRouteMilestone = 0
        closestRemaining = Double.MAX_VALUE
        offPathStreak = 0
        offPathWarned = false
        justAheadSaid = false
        announcedTurns.clear()
    }

    /** Spoken directions along a walkway path: the opening line, turns, leaving it, the end. */
    private fun routePrompts(
        fix: LocationFix,
        target: GuidanceTarget,
        path: WalkingPath,
        route: RouteGuidance,
        heading: Double?,
        accuracy: Double?,
        unit: DistanceUnit,
        prompts: MutableList<VoicePrompt>,
    ) {
        val now = fix.timeMillis
        val remaining = route.remainingMeters
        // Slight bends are shown on the banner but not spoken: recorded routes wiggle a little.
        val turn = path.turns.firstOrNull { it.atMeters > TURN_PASSED_METERS && it.direction.isSpoken() }
        val pathSide = if (heading != null && route.offPathMeters >= 1) {
            sideFor(GeoMath.signedDifference(GeoMath.bearingDegrees(fix.point, path.pointAt(path.joinMeters)), heading))
        } else {
            null
        }
        val offPath = route.offPathMeters > offPathLimit(accuracy)
        closestRemaining = min(closestRemaining, remaining)

        if (!started) {
            started = true
            // A milestone close to the distance just spoken would only repeat it.
            while (nextRouteMilestone < ROUTE_MILESTONES.size &&
                ROUTE_MILESTONES[nextRouteMilestone] >= remaining - MILESTONE_REPEAT_METERS
            ) {
                nextRouteMilestone++
            }
            var text = "${target.startLine}. Follow the blue line, ${spokenDistance(remaining, unit)} to walk."
            if (offPath) {
                text += " " + pathLine(route.offPathMeters, pathSide, unit)
            } else if (turn != null && turn.atMeters <= TURN_AHEAD_METERS) {
                val record = announced(turn.point)
                record.ahead = true
                if (turn.atMeters <= TURN_NOW_METERS) {
                    record.atTurn = true
                    text += " " + turnNowLine(turn.direction)
                } else {
                    text += " " + turnAheadLine(turn, unit)
                }
            }
            prompts += say(now, text)
            return
        }

        // Off the path: said once, after a few readings, with the way back.
        if (offPath) {
            offPathStreak += 1
            if (offPathStreak >= OFF_PATH_READINGS && !offPathWarned && canSpeak(now)) {
                offPathWarned = true
                prompts += say(now, "You're off the path. " + pathLine(route.offPathMeters, pathSide, unit))
            }
            return
        }
        offPathStreak = 0
        if (offPathWarned && route.offPathMeters <= BACK_ON_PATH_METERS) {
            offPathWarned = false
            closestRemaining = remaining
            if (canSpeak(now)) {
                prompts += say(now, "You're back on the path.")
                return
            }
        }

        // Each turn: once ahead of time, and once at the turn.
        if (turn != null) {
            val record = announced(turn.point)
            if (turn.atMeters <= TURN_NOW_METERS) {
                if (!record.atTurn && canSpeakAtTurn(now)) {
                    record.atTurn = true
                    record.ahead = true
                    prompts += say(now, turnNowLine(turn.direction), urgent = true)
                    return
                }
            } else if (turn.atMeters <= TURN_AHEAD_METERS && !record.ahead && canSpeak(now)) {
                record.ahead = true
                prompts += say(now, turnAheadLine(turn, unit))
                return
            }
        }

        // Walking back along the path, away from the target.
        if (remaining > closestRemaining + WRONG_WAY_METERS && canSpeak(now) &&
            lastCorrectionAt.let { it == null || now - it >= CORRECTION_GAP_MILLIS }
        ) {
            lastCorrectionAt = now
            closestRemaining = remaining
            prompts += say(now, "You're going the wrong way. Turn around and follow the blue line.")
            return
        }

        // The end of the path, once no turn is left before it.
        val turnBeforeEnd = turn != null && turn.atMeters < remaining - TURN_PASSED_METERS
        if (remaining <= JUST_AHEAD_METERS && !justAheadSaid && !turnBeforeEnd) {
            if (canSpeak(now)) {
                justAheadSaid = true
                prompts += say(now, "${target.name} is just ahead.")
            }
            return
        }

        // Distance milestones, unless a turn is about to be announced.
        val crossed = crossedRouteMilestone(remaining)
        if (crossed != null && (turn == null || turn.atMeters > TURN_AHEAD_METERS) && canSpeak(now)) {
            nextRouteMilestone = crossed + 1
            prompts += say(now, "${spokenDistance(remaining, unit).replaceFirstChar { it.uppercase() }} to ${target.name}.")
        }
    }

    /** The turn already announced at about this spot, or a new record for it. */
    private fun announced(point: GeoPoint): AnnouncedTurn =
        announcedTurns.firstOrNull { GeoMath.distanceMeters(it.point, point) <= SAME_TURN_METERS }
            ?: AnnouncedTurn(point).also {
                if (announcedTurns.size >= MAX_REMEMBERED_TURNS) announcedTurns.removeAt(0)
                announcedTurns += it
            }

    private fun crossedRouteMilestone(remaining: Double): Int? {
        var crossed: Int? = null
        for (index in nextRouteMilestone until ROUTE_MILESTONES.size) {
            if (remaining <= ROUTE_MILESTONES[index]) crossed = index
        }
        return crossed
    }

    /** A turn is due now: it may follow the last prompt more closely than other directions. */
    private fun canSpeakAtTurn(now: Long): Boolean = lastPromptAt.let { it == null || now - it >= TURN_PROMPT_GAP_MILLIS }

    /** The smallest milestone not yet announced that the visitor is now within. */
    private fun crossedMilestone(distance: Double): Int? {
        var crossed: Int? = null
        for (index in nextMilestone until MILESTONES.size) {
            if (distance <= MILESTONES[index]) crossed = index
        }
        return crossed
    }

    private fun canSpeak(now: Long): Boolean = lastPromptAt.let { it == null || now - it >= MIN_PROMPT_GAP_MILLIS }

    private fun say(now: Long, text: String, urgent: Boolean = false): VoicePrompt {
        lastPromptAt = now
        return VoicePrompt(text, urgent)
    }

    companion object {
        /** Distances (meters) at which directions are repeated; 25 m is spoken as "just ahead". */
        val MILESTONES = listOf(200.0, 100.0, 50.0, 25.0, 10.0)
        /** Readings in a row needed to confirm an arrival (about 3 seconds on the map screen). */
        const val ARRIVAL_READINGS = 3
        /** Distances are only spoken from readings at least this accurate. */
        const val PROMPT_MAX_ACCURACY_METERS = 20.0
        /** Readings worse than this show a weak-signal notice instead of directions. */
        const val WEAK_SIGNAL_METERS = 50.0
        const val WRONG_WAY_METERS = 25.0
        const val MIN_PROMPT_GAP_MILLIS = 6_000L
        const val CORRECTION_GAP_MILLIS = 45_000L
        /** Moving faster than this, the walking direction is more reliable than the compass. */
        const val COURSE_MIN_SPEED = 0.8

        /** Walking distances left (meters along the path) at which the distance is spoken. */
        val ROUTE_MILESTONES = listOf(200.0, 100.0, 50.0)
        /** Along a path, the arrow points at the path this far ahead of the visitor. */
        const val LOOK_AHEAD_METERS = 6.0
        /** A turn is announced once within this walking distance... */
        const val TURN_AHEAD_METERS = 30.0
        /** ...and again at the turn. */
        const val TURN_NOW_METERS = 7.0
        /** A turn this close is being taken, or was. */
        const val TURN_PASSED_METERS = 2.0
        const val TURN_PROMPT_GAP_MILLIS = 2_500L
        /** Off the path: farther than this from it (more with a rough reading)... */
        const val OFF_PATH_METERS = 20.0
        /** ...for this many readings in a row. */
        const val OFF_PATH_READINGS = 3
        const val BACK_ON_PATH_METERS = 10.0
        const val JUST_AHEAD_METERS = 25.0
        private const val MILESTONE_REPEAT_METERS = 10.0
        private const val SAME_TURN_METERS = 10.0
        private const val MAX_REMEMBERED_TURNS = 24

        /** How far from the path counts as off it: GPS error never makes a visitor "off the path". */
        fun offPathLimit(accuracy: Double?): Double = max(OFF_PATH_METERS, (accuracy ?: 0.0) + 10.0)

        /** Where the visitor stands on [path]: distance left, distance from it, the next turn. */
        fun routeGuidance(path: WalkingPath): RouteGuidance {
            val turn = path.turns.firstOrNull { it.atMeters > TURN_PASSED_METERS }
            return RouteGuidance(path.lengthMeters, path.joinMeters, turn?.direction, turn?.atMeters)
        }

        fun chooseHeading(fix: LocationFix?, compassHeading: Double?): Double? {
            val course = fix?.courseDegrees
            val speed = fix?.speedMetersPerSecond
            return if (course != null && speed != null && speed >= COURSE_MIN_SPEED) course else compassHeading
        }

        fun sideFor(relativeDegrees: Double): RelativeSide {
            val angle = abs(relativeDegrees)
            return when {
                angle <= 20 -> RelativeSide.AHEAD
                angle <= 60 -> if (relativeDegrees < 0) RelativeSide.SLIGHT_LEFT else RelativeSide.SLIGHT_RIGHT
                angle <= 135 -> if (relativeDegrees < 0) RelativeSide.LEFT else RelativeSide.RIGHT
                else -> RelativeSide.BEHIND
            }
        }

        fun arrivalLine(target: GuidanceTarget): String = when (target.kind) {
            TargetKind.OFFICE -> "You have arrived at ${target.name}." + locationSentence(target)
            TargetKind.GATE -> "You have arrived at ${target.name}. Show your pass to the guard to check out."
            TargetKind.ENTRY -> "You're back where you checked in. Show your pass to the guard to check out."
        }

        fun veryCloseLine(target: GuidanceTarget): String = when (target.kind) {
            TargetKind.OFFICE -> "You're very close to ${target.name}." + locationSentence(target) +
                " Tap I'm here when you reach it."
            TargetKind.GATE -> "You're very close to ${target.name}."
            TargetKind.ENTRY -> "You're very close to where you checked in."
        }

        private fun locationSentence(target: GuidanceTarget): String =
            if (target.detail.isNotBlank()) " It's at ${target.detail.trimEnd('.')}." else ""

        private fun milestoneLine(
            target: GuidanceTarget,
            milestone: Double,
            distance: Double,
            side: RelativeSide?,
            unit: DistanceUnit,
        ): String = when {
            milestone != 25.0 -> "${target.name} is ${spokenDistance(distance, unit)} away${sideClause(side)}."
            side == null || side == RelativeSide.AHEAD -> "${target.name} is just ahead."
            else -> "${target.name} is close, ${sideWords(side)}."
        }
    }
}

/** "slightly to your left", or "" when the side is unknown. */
fun sideWords(side: RelativeSide?): String = when (side) {
    RelativeSide.AHEAD -> "straight ahead"
    RelativeSide.SLIGHT_LEFT -> "slightly to your left"
    RelativeSide.SLIGHT_RIGHT -> "slightly to your right"
    RelativeSide.LEFT -> "to your left"
    RelativeSide.RIGHT -> "to your right"
    RelativeSide.BEHIND -> "behind you"
    null -> ""
}

private fun sideClause(side: RelativeSide?): String = if (side == null) "" else ", ${sideWords(side)}"

/** Slight bends are shown but not spoken: a route recorded by walking wiggles a little. */
fun TurnDirection.isSpoken(): Boolean = this != TurnDirection.SLIGHT_LEFT && this != TurnDirection.SLIGHT_RIGHT

/** "turn left", "keep slightly right", "make a sharp left". */
fun turnPhrase(direction: TurnDirection): String = when (direction) {
    TurnDirection.SLIGHT_LEFT -> "keep slightly left"
    TurnDirection.LEFT -> "turn left"
    TurnDirection.SHARP_LEFT -> "make a sharp left"
    TurnDirection.SLIGHT_RIGHT -> "keep slightly right"
    TurnDirection.RIGHT -> "turn right"
    TurnDirection.SHARP_RIGHT -> "make a sharp right"
}

/** "In 20 meters, turn left." */
private fun turnAheadLine(turn: PathTurn, unit: DistanceUnit): String =
    "In ${spokenDistance(turn.atMeters, unit)}, ${turnPhrase(turn.direction)}."

/** "Turn left now.", or "Keep slightly left." for a bend. */
private fun turnNowLine(direction: TurnDirection): String {
    val phrase = turnPhrase(direction).replaceFirstChar { it.uppercase() }
    return if (direction.isSpoken()) "$phrase now." else "$phrase."
}

/** "The path is 25 meters to your left." */
private fun pathLine(meters: Double, side: RelativeSide?, unit: DistanceUnit): String =
    if (side == null) "The path is ${spokenDistance(meters, unit)} away." else "The path is ${spokenDistance(meters, unit)} ${sideWords(side)}."

/** The banner's instruction while following a walkway path, e.g. "Turn left in 20 m". */
fun routeInstruction(route: RouteGuidance, unit: DistanceUnit): String {
    val turn = route.nextTurn
    val turnMeters = route.nextTurnMeters
    return when {
        route.offPathMeters > GuidanceEngine.OFF_PATH_METERS -> "Go back to the path, ${displayDistance(route.offPathMeters, unit)} away"
        turn != null && turnMeters != null && turnMeters <= GuidanceEngine.TURN_NOW_METERS -> turnNowLine(turn).trimEnd('.')
        turn != null && turnMeters != null && turnMeters <= TURN_SHOWN_METERS ->
            "${turnPhrase(turn).replaceFirstChar { it.uppercase() }} in ${displayDistance(turnMeters, unit)}"
        route.remainingMeters <= GuidanceEngine.JUST_AHEAD_METERS -> "Almost there"
        else -> "Continue on the path"
    }
}

/** The banner shows the next turn from this far away. */
private const val TURN_SHOWN_METERS = 60.0

private fun plural(count: Int, word: String): String = if (count == 1) word else "${word}s"

private fun roundTo(value: Double, step: Int): Int = (value / step).roundToInt() * step

/** Meters as spoken and shown: to the meter under 20 m, then 5 m and 10 m steps. */
fun roundedMeters(meters: Double): Int = when {
    meters < 20 -> max(1, meters.roundToInt())
    meters < 100 -> roundTo(meters, 5)
    meters < 1000 -> roundTo(meters, 10)
    else -> roundTo(meters, 100)
}

/** Feet as spoken and shown: to the foot under 60 ft, then 5, 10, and 50 ft steps. */
fun roundedFeet(meters: Double): Int {
    val feet = meters * FEET_PER_METER
    return when {
        feet < 60 -> max(1, feet.roundToInt())
        feet < 300 -> roundTo(feet, 5)
        feet < 1000 -> roundTo(feet, 10)
        else -> roundTo(feet, 50)
    }
}

/** "120 meters", "1 meter", "1.2 kilometers", or "400 feet". */
fun spokenDistance(meters: Double, unit: DistanceUnit): String = when (unit) {
    DistanceUnit.FEET -> roundedFeet(meters).let { "$it ${if (it == 1) "foot" else "feet"}" }
    DistanceUnit.METERS -> roundedMeters(meters).let {
        if (it >= 1000) String.format(Locale.US, "%.1f kilometers", it / 1000.0) else "$it ${plural(it, "meter")}"
    }
}

/** "120 m", "1.2 km", or "400 ft". */
fun displayDistance(meters: Double, unit: DistanceUnit): String = when (unit) {
    DistanceUnit.FEET -> "${roundedFeet(meters)} ft"
    DistanceUnit.METERS -> roundedMeters(meters).let {
        if (it >= 1000) String.format(Locale.US, "%.1f km", it / 1000.0) else "$it m"
    }
}

/** GPS accuracy as "±4 m" or "±13 ft". */
fun displayAccuracy(meters: Double, unit: DistanceUnit): String = when (unit) {
    DistanceUnit.FEET -> "±${max(1, (meters * FEET_PER_METER).roundToInt())} ft"
    DistanceUnit.METERS -> "±${max(1, meters.roundToInt())} m"
}

/**
 * Walking minutes for a straight-line distance: paths are about 30% longer than a
 * straight line, at an easy 1.25 m/s. Never less than one minute.
 */
fun walkingMinutes(meters: Double): Int = max(1, ceil(meters * 1.3 / 1.25 / 60).toInt())

/** Walking minutes for a distance along a walkway path, at an easy 1.25 m/s. Never less than one. */
fun walkingMinutesAlongPath(meters: Double): Int = max(1, ceil(meters / 1.25 / 60).toInt())

private const val FEET_PER_METER = 3.28084
