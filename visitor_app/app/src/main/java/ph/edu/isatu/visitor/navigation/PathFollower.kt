package ph.edu.isatu.visitor.navigation

import kotlin.math.max
import kotlin.math.min

/**
 * Decides whether the visitor counts as walking on the blue line, so the arrow can be drawn
 * on it. GPS wanders a few meters either side of a walkway (and the recorded walkway itself
 * is a few meters off), which would show the arrow walking beside the line. So:
 *
 * - the visitor is **on the path** once GPS places them within [JOIN_METERS] of it for
 *   [JOIN_READINGS] readings in a row;
 * - they stay on it until GPS places them farther than [leaveLimit] from it for
 *   [LEAVE_READINGS] readings in a row. The limit grows with the reading's own error, up to
 *   the distance at which the voice says "You're off the path", so a visitor who really
 *   walks away is shown where they are within a few steps;
 * - past the end of the walkway and close to the office itself there is no line to stand
 *   on, so the arrow is drawn at the visitor's own position.
 *
 * While on the path, the arrow is drawn at the path's own point nearest the visitor and
 * directions are measured along the line ([WalkingPath.onWalkway]). Arrival at the office
 * is never judged from that point: see [GuidanceEngine].
 */
class PathFollower {
    private var onPath = false
    private var nearReadings = 0
    private var strayReadings = 0
    private var countedAt: Long? = null
    private var target: GeoPoint? = null

    /**
     * Whether the visitor is on [path], as planned from their own position.
     * [accuracyMeters] is the latest reading's accuracy and [readingMillis] its time, so the
     * same reading is never counted twice.
     */
    fun follows(path: WalkingPath?, accuracyMeters: Double?, readingMillis: Long): Boolean {
        // A path to another place starts over: being on the old one says nothing about it.
        if (path == null || path.target != target) {
            leave()
            target = path?.target
            if (path == null) return false
        }
        val fresh = countedAt != readingMillis
        countedAt = readingMillis

        val walkwayLeft = path.lengthMeters - path.joinMeters - path.finishMeters
        val toTarget = GeoMath.distanceMeters(path.points.first(), path.target)
        if (walkwayLeft < MIN_WALKWAY_METERS || toTarget <= max(NEAR_TARGET_METERS, accuracyMeters ?: 0.0)) {
            leave()
            return false
        }
        if (!onPath) {
            if (path.joinMeters > JOIN_METERS) {
                nearReadings = 0
            } else {
                if (fresh) nearReadings += 1
                if (nearReadings >= JOIN_READINGS) {
                    onPath = true
                    strayReadings = 0
                }
            }
        } else if (path.joinMeters > leaveLimit(accuracyMeters)) {
            if (fresh) strayReadings += 1
            if (strayReadings >= LEAVE_READINGS) leave()
        } else {
            strayReadings = 0
        }
        return onPath
    }

    private fun leave() {
        onPath = false
        nearReadings = 0
        strayReadings = 0
    }

    companion object {
        /** GPS within this distance of the walkway counts as being on it... */
        const val JOIN_METERS = 10.0
        /** ...after this many readings in a row. */
        const val JOIN_READINGS = 2
        /** Leaving takes this many readings in a row beyond [leaveLimit]. */
        const val LEAVE_READINGS = 3
        const val LEAVE_METERS = 12.0
        /** Room for the reading's own error on top of its accuracy. */
        const val LEAVE_MARGIN_METERS = 6.0
        /** This close to the office, the arrow shows the visitor's own position. */
        const val NEAR_TARGET_METERS = 10.0
        /** With less walkway than this left to walk, there is no line to follow. */
        const val MIN_WALKWAY_METERS = 3.0

        /**
         * How far from the walkway still counts as on it: [LEAVE_METERS], more with a rough
         * reading, and never beyond the distance the voice calls off the path.
         */
        fun leaveLimit(accuracyMeters: Double?): Double =
            min(GuidanceEngine.OFF_PATH_METERS, max(LEAVE_METERS, (accuracyMeters ?: 0.0) + LEAVE_MARGIN_METERS))
    }
}
