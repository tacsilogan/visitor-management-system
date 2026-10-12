package ph.edu.isatu.visitor.navigation

import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.sin
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class PositionFilterTest {
    private val home = GeoPoint(10.7177, 122.5559)
    private val metersPerLongitude = 111_320.0 * cos(home.latitude * PI / 180)

    /** A point [north] and [east] meters from the starting spot. */
    private fun at(north: Double, east: Double = 0.0) =
        GeoPoint(home.latitude + north / 111_320.0, home.longitude + east / metersPerLongitude)

    private fun north(point: GeoPoint) = (point.latitude - home.latitude) * 111_320.0
    private fun east(point: GeoPoint) = (point.longitude - home.longitude) * metersPerLongitude
    private fun apart(a: GeoPoint, b: GeoPoint) = hypot(north(a) - north(b), east(a) - east(b))

    private fun reading(north: Double, east: Double, second: Double, accuracy: Double? = 5.0, speed: Double? = null) =
        PositionReading(at(north, east), accuracy, (second * 1000).toLong(), speed)

    /** GPS wander that is the same on every run: values from -1 to 1. */
    private class Wander(private var state: Long = 20261012L) {
        fun next(): Double {
            state = (state * 6364136223846793005L + 1442695040888963407L)
            return ((state ushr 33) % 20001L) / 10000.0 - 1.0
        }
    }

    /** A filter already settled on a visitor walking north at 1.3 m/s, at [seconds]. */
    private fun PositionFilter.walkNorth(seconds: Int, speed: Double? = 1.3): SteadyPosition {
        var last: SteadyPosition? = null
        for (second in 0..seconds) last = update(reading(second * 1.3, 0.0, second.toDouble(), speed = speed))
        return last!!
    }

    @Test
    fun theFirstPositionWaitsForAnAccurateReading() {
        val filter = PositionFilter()
        assertNull("a Wi-Fi guess never opens the map", filter.update(reading(40.0, 30.0, 0.0, accuracy = 45.0)))
        assertNull(filter.update(reading(20.0, 10.0, 1.0, accuracy = 30.0)))
        val first = filter.update(reading(1.0, 0.0, 2.0, accuracy = 12.0))!!
        assertEquals(PositionQuality.USED, first.quality)
        assertEquals(0.0, apart(first.point, at(1.0)), 0.01)
        assertEquals(12.0, first.accuracyMeters, 0.0001)
    }

    @Test
    fun afterAShortWaitARougherFirstReadingIsTaken() {
        val filter = PositionFilter()
        for (second in 0..5) assertNull(filter.update(reading(0.0, 0.0, second.toDouble(), accuracy = 40.0)))
        assertNotNull(filter.update(reading(0.0, 0.0, 6.0, accuracy = 40.0)))
        assertNull("weak readings never start it", PositionFilter().also { weak ->
            for (second in 0..20) weak.update(reading(0.0, 0.0, second.toDouble(), accuracy = 80.0))
        }.update(reading(0.0, 0.0, 21.0, accuracy = 80.0)))
    }

    @Test
    fun standingStillTheArrowSettlesAndStopsWandering() {
        val filter = PositionFilter()
        val wander = Wander()
        var previous: SteadyPosition? = null
        var largestStep = 0.0
        var largestRawStep = 0.0
        var previousRaw: GeoPoint? = null
        for (second in 0..90) {
            // Ordinary GPS wander of up to 6 m either way while the visitor stands at the door.
            val raw = reading(6 * wander.next(), 6 * wander.next(), second.toDouble(), speed = 0.1)
            val steady = filter.update(raw)!!
            if (second >= 15) {
                largestStep = max(largestStep, apart(steady.point, previous!!.point))
                largestRawStep = max(largestRawStep, apart(raw.point, previousRaw!!))
                assertTrue(steady.still)
            }
            previous = steady
            previousRaw = raw.point
        }
        assertTrue("raw readings hop by meters ($largestRawStep)", largestRawStep > 8.0)
        assertEquals("the steady position does not move at all", 0.0, largestStep, 0.0)
        assertTrue("and sits on the true spot", apart(previous!!.point, at(0.0)) < 2.5)
    }

    @Test
    fun slowGpsDriftWhileStandingDoesNotMoveTheArrow() {
        val filter = PositionFilter()
        var settled: SteadyPosition? = null
        for (second in 0..180) {
            // Real GPS error drifts slowly rather than hopping: here a few meters over a minute.
            val north = 2.5 * sin(2 * PI * second / 60.0)
            val east = 2.5 * cos(2 * PI * second / 47.0) - 2.5
            val steady = filter.update(reading(north, east, second.toDouble(), speed = 0.2))!!
            if (second == 12) settled = steady
            if (second > 12) {
                assertTrue(steady.still)
                assertEquals(0.0, apart(steady.point, settled!!.point), 0.0)
            }
        }
    }

    @Test
    fun roughReadingsDoNotLeaveAWalkerFarBehind() {
        val filter = PositionFilter()
        var last: SteadyPosition? = null
        // A phone that is only good to 15 m, walking at 1.3 m/s.
        for (second in 0..60) last = filter.update(reading(second * 1.3, 0.0, second.toDouble(), accuracy = 15.0, speed = 1.3))
        assertTrue("${78.0 - north(last!!.point)} m behind", 78.0 - north(last.point) < 4.5)
    }

    @Test
    fun aSingleJumpIsLeftOut() {
        val filter = PositionFilter()
        val before = filter.walkNorth(20)
        // A reflected signal: 40 m east, though the phone still claims 12 m accuracy.
        val jump = filter.update(reading(27.3, 40.0, 21.0, accuracy = 12.0, speed = 1.3))!!
        assertEquals(PositionQuality.HELD_JUMP, jump.quality)
        assertEquals(0.0, apart(jump.point, before.point), 0.001)
        assertEquals("the accuracy stays the last used reading's", 5.0, jump.accuracyMeters, 0.0001)
        val after = filter.update(reading(28.6, 0.0, 22.0, speed = 1.3))!!
        assertEquals(PositionQuality.USED, after.quality)
        assertTrue(abs(east(after.point)) < 0.01)
        assertTrue("walking on is followed at once", north(after.point) > north(before.point))
    }

    @Test
    fun aRoughWifiReadingBarelyMovesTheArrow() {
        val filter = PositionFilter()
        val before = filter.walkNorth(20)
        // The phone lost GPS for a moment and fell back to a Wi-Fi position 40 m away.
        val rough = filter.update(reading(26.0, 40.0, 21.0, accuracy = 35.0))!!
        assertTrue("moved ${apart(rough.point, before.point)} m", apart(rough.point, before.point) < 1.0)
    }

    @Test
    fun aBurstOfBadReadingsIsLeftOut() {
        val filter = PositionFilter()
        val before = filter.walkNorth(20)
        for (second in 21..25) {
            val held = filter.update(reading(20.0, -30.0, second.toDouble(), accuracy = 10.0))!!
            assertEquals(PositionQuality.HELD_JUMP, held.quality)
            assertEquals(0.0, apart(held.point, before.point), 0.001)
        }
        // The readings come back to the walkway, 6.5 m farther on.
        val back = filter.update(reading(26 * 1.3, 0.0, 26.0, speed = 1.3))!!
        assertEquals(PositionQuality.USED, back.quality)
        assertTrue(abs(east(back.point)) < 0.01)
    }

    @Test
    fun aCloserBurstOnlyEdgesTheArrowOverAndItComesBackAtOnce() {
        val filter = PositionFilter()
        filter.walkNorth(20)
        // Readings 20 m to the side that still claim ±7 m: close enough to pass as possible, so
        // they are only partly believed and the arrow edges over instead of jumping.
        val first = filter.update(reading(21 * 1.3, -20.0, 21.0, accuracy = 7.0, speed = 1.3))!!
        assertTrue("the first moved it ${-east(first.point)} m", -east(first.point) < 4.0)
        for (second in 22..25) filter.update(reading(second * 1.3, -20.0, second.toDouble(), accuracy = 7.0, speed = 1.3))
        var back: SteadyPosition? = null
        for (second in 26..28) back = filter.update(reading(second * 1.3, 0.0, second.toDouble(), speed = 1.3))
        assertEquals(PositionQuality.USED, back!!.quality)
        assertTrue("back within ${-east(back.point)} m of the walkway", -east(back.point) < 4.0)
    }

    @Test
    fun readingsThatStayAtANewPlaceAreBelievedAfterAWhile() {
        val filter = PositionFilter()
        filter.walkNorth(10)
        var last: SteadyPosition? = null
        for (second in 11..16) {
            last = filter.update(reading(13.0, 40.0, second.toDouble(), accuracy = 6.0))!!
            assertEquals("held for the first seconds", PositionQuality.HELD_JUMP, last.quality)
        }
        last = filter.update(reading(13.0, 40.0, 17.0, accuracy = 6.0))!!
        assertEquals(PositionQuality.USED, last.quality)
        assertEquals(0.0, apart(last.point, at(13.0, 40.0)), 0.5)
    }

    @Test
    fun aFarPlaceIsNotBelievedWhileThePhoneSaysItHardlyMoved() {
        val filter = PositionFilter()
        val before = filter.walkNorth(10)
        // Beside a tall building the readings sit 32 m away for a while, yet the phone's own
        // speed says the visitor is standing: a bounced signal, not a walk.
        for (second in 11..30) {
            val held = filter.update(reading(13.0, 32.0, second.toDouble(), accuracy = 6.0, speed = 0.1))!!
            assertEquals(PositionQuality.HELD_JUMP, held.quality)
            assertEquals(0.0, apart(held.point, before.point), 0.001)
        }
        // If it never ends, the readings are believed after all.
        assertEquals(PositionQuality.USED, filter.update(reading(13.0, 32.0, 31.0, accuracy = 6.0, speed = 0.1))!!.quality)
    }

    @Test
    fun roughReadingsAtANewPlaceNeedMuchLonger() {
        val filter = PositionFilter()
        val before = filter.walkNorth(10)
        for (second in 11..30) {
            // 90 m away, and only good to 30 m: not believed for a long while.
            val held = filter.update(reading(13.0, 90.0, second.toDouble(), accuracy = 30.0))!!
            assertEquals(PositionQuality.HELD_JUMP, held.quality)
            assertEquals(0.0, apart(held.point, before.point), 0.001)
        }
        // But a phone that only ever gets rough readings is not left behind for good.
        val believed = filter.update(reading(13.0, 90.0, 31.0, accuracy = 30.0))!!
        assertEquals(PositionQuality.USED, believed.quality)
        assertEquals(0.0, apart(believed.point, at(13.0, 90.0)), 0.5)
    }

    @Test
    fun weakReadingsHoldThePosition() {
        val filter = PositionFilter()
        val before = filter.walkNorth(10)
        val weak = filter.update(reading(100.0, 100.0, 11.0, accuracy = 80.0))!!
        assertEquals(PositionQuality.HELD_WEAK, weak.quality)
        assertEquals(0.0, apart(weak.point, before.point), 0.001)
        assertEquals(before.elapsedMillis, weak.elapsedMillis)
    }

    @Test
    fun walkingIsFollowedCloselyAndSmoothly() {
        val filter = PositionFilter()
        val wander = Wander(7L)
        var worst = 0.0
        var sideways = 0.0
        var rawSideways = 0.0
        for (second in 0..120) {
            val truth = second * 1.3
            val noiseEast = 4 * wander.next()
            val steady = filter.update(reading(truth + 3 * wander.next(), noiseEast, second.toDouble(), accuracy = 4.0, speed = 1.3))!!
            if (second >= 10) {
                worst = max(worst, apart(steady.point, at(truth)))
                sideways = max(sideways, abs(east(steady.point)))
                rawSideways = max(rawSideways, abs(noiseEast))
            }
            assertFalse(steady.still)
        }
        assertTrue("never more than a few meters from the visitor ($worst)", worst < 5.0)
        assertTrue("sideways wander $sideways is less than the readings' $rawSideways", sideways < rawSideways * 0.8)
    }

    @Test
    fun walkingOnAfterStandingStillIsFollowedAtOnce() {
        val filter = PositionFilter()
        for (second in 0..30) filter.update(reading(0.0, 0.0, second.toDouble(), speed = 0.0))
        var last: SteadyPosition? = null
        for (second in 31..36) last = filter.update(reading((second - 30) * 1.3, 0.0, second.toDouble(), speed = 1.3))
        assertFalse(last!!.still)
        assertTrue("after 6 steps (7.8 m) the arrow has gone ${north(last.point)} m", north(last.point) > 4.5)
    }

    @Test
    fun aPhoneThatWronglyReportsNoSpeedIsNotBelievedForLong() {
        val filter = PositionFilter()
        var last: SteadyPosition? = null
        // Walking at 1.3 m/s while the phone keeps reporting a speed of zero.
        for (second in 0..60) last = filter.update(reading(second * 1.3, 0.0, second.toDouble(), speed = 0.0))
        assertFalse(last!!.still)
        assertTrue("the arrow keeps up (${78.0 - north(last.point)} m behind)", 78.0 - north(last.point) < 5.0)
    }

    @Test
    fun comingOutOfACoveredWalkIsFollowedAtOnce() {
        val filter = PositionFilter()
        val before = filter.walkNorth(20)
        // No readings at all for 20 seconds, then the visitor reappears 26 m farther on.
        val after = filter.update(reading(52.0, 0.0, 41.0, speed = 1.3))!!
        assertEquals(PositionQuality.USED, after.quality)
        assertTrue(north(after.point) - north(before.point) > 20.0)
    }

    @Test
    fun weakReadingsUnderARoofDoNotBlockTheNextGoodOne() {
        val filter = PositionFilter()
        filter.walkNorth(20)
        for (second in 21..40) filter.update(reading(26.0, 0.0, second.toDouble(), accuracy = 70.0))
        val after = filter.update(reading(52.0, 0.0, 41.0, speed = 1.3))!!
        assertEquals(PositionQuality.USED, after.quality)
        assertTrue(north(after.point) > 45.0)
    }

    @Test
    fun aRideIsFollowed() {
        val filter = PositionFilter()
        var last: SteadyPosition? = null
        for (second in 0..40) last = filter.update(reading(second * 6.0, 0.0, second.toDouble(), speed = 6.0))
        assertEquals(PositionQuality.USED, last!!.quality)
        assertTrue(240.0 - north(last.point) < 4.0)

        // Even when the phone reports no speed at all.
        val silent = PositionFilter()
        for (second in 0..40) last = silent.update(reading(second * 6.0, 0.0, second.toDouble()))
        assertEquals(PositionQuality.USED, last!!.quality)
        assertTrue(240.0 - north(last.point) < 10.0)
    }

    @Test
    fun lateAndRepeatedReadingsChangeNothing() {
        val filter = PositionFilter()
        val before = filter.walkNorth(10)
        assertEquals(before, filter.update(reading(50.0, 50.0, 10.0)))
        assertEquals(before, filter.update(reading(50.0, 50.0, 4.0)))
    }

    @Test
    fun aLongSilenceStartsAfresh() {
        val filter = PositionFilter()
        filter.walkNorth(10)
        // The screen was closed for two minutes; the first reading back is a coarse one.
        assertNull(filter.update(reading(200.0, 0.0, 130.0, accuracy = 40.0)))
        val fresh = filter.update(reading(150.0, 0.0, 131.0, accuracy = 6.0))!!
        assertEquals(0.0, apart(fresh.point, at(150.0)), 0.01)
    }

    @Test
    fun aReadingWithoutItsOwnTimeTakesItsArrivalTime() {
        assertEquals(41_000L, readingTimeMillis(41_000_000_000L, 42_000L))
        assertEquals("no time at all", 42_000L, readingTimeMillis(0L, 42_000L))
        assertEquals("a time from the future", 42_000L, readingTimeMillis(50_000_000_000L, 42_000L))
    }

    @Test
    fun aReadingWithoutAccuracyIsUsedCautiously() {
        val filter = PositionFilter()
        val first = filter.update(reading(0.0, 0.0, 0.0, accuracy = null))!!
        assertEquals(PositionFilter.UNKNOWN_ACCURACY_METERS, first.accuracyMeters, 0.0001)
    }
}
