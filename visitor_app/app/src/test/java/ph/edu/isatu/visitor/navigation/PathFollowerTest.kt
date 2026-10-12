package ph.edu.isatu.visitor.navigation

import kotlin.math.PI
import kotlin.math.cos
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test

/** Keeping the arrow on the blue line: a walkway from the gate 60 m north, then 40 m east to the office. */
class PathFollowerTest {
    private val gate = GeoPoint(10.7177, 122.5559)

    /** A point [north] and [east] meters from the gate. */
    private fun at(north: Double, east: Double = 0.0) = GeoPoint(
        gate.latitude + north / 111_194.93,
        gate.longitude + east / (111_194.93 * cos(gate.latitude * PI / 180)),
    )

    private val office = at(60.0, 40.0)
    private val planner = WalkwayPlanner(listOf(Walkway(1, "IT", "Main Gate → IT Department", listOf(at(0.0), at(60.0), office))))

    /** The path to the office for a visitor GPS places at [north], [east]. */
    private fun path(north: Double, east: Double, to: GeoPoint = office) = planner.plan(at(north, east), to, targetOffice = "IT")!!

    /** A follower that already has the visitor on the path, 20 m up the walkway. */
    private fun onThePath(): PathFollower = PathFollower().also {
        it.follows(path(19.0, 0.0), 5.0, -2)
        assertTrue(it.follows(path(20.0, 0.0), 5.0, -1))
    }

    @Test
    fun gpsWanderBesideTheWalkwayCountsAsBeingOnIt() {
        val follower = PathFollower()
        assertFalse("one reading is not enough", follower.follows(path(20.0, 5.0), 5.0, 1))
        assertTrue(follower.follows(path(21.0, 6.0), 5.0, 2))
        assertTrue("9 m out is still GPS error, not leaving", follower.follows(path(25.0, -9.0), 5.0, 3))
        assertTrue(follower.follows(path(30.0, 11.0), 5.0, 4))
        assertTrue(follower.follows(path(31.0, 3.0), 5.0, 5))
    }

    @Test
    fun aVisitorWellAwayFromTheWalkwayIsNotPutOnIt() {
        val follower = PathFollower()
        assertFalse(follower.follows(path(20.0, -15.0), 5.0, 1))
        assertFalse(follower.follows(path(21.0, -12.0), 5.0, 2))
        assertFalse(follower.follows(path(22.0, -9.0), 5.0, 3))
        assertTrue("within 10 m twice, they have reached it", follower.follows(path(23.0, -7.0), 5.0, 4))
    }

    @Test
    fun leavingThePathTakesThreeReadingsInARow() {
        val follower = onThePath()
        assertTrue(follower.follows(path(30.0, -14.0), 5.0, 2))
        assertTrue(follower.follows(path(30.0, -15.0), 5.0, 3))
        assertFalse("14 m away three times is a visitor who left", follower.follows(path(30.0, -16.0), 5.0, 4))
        // Coming back, they are on it again once GPS has them close twice.
        assertFalse(follower.follows(path(30.0, -11.0), 5.0, 5))
        assertFalse(follower.follows(path(30.0, -9.0), 5.0, 6))
        assertTrue(follower.follows(path(30.0, -8.0), 5.0, 7))
    }

    @Test
    fun oneReadingBackNearThePathStartsTheCountAgain() {
        val follower = onThePath()
        follower.follows(path(30.0, -15.0), 5.0, 2)
        follower.follows(path(30.0, -15.0), 5.0, 3)
        assertTrue(follower.follows(path(30.0, -8.0), 5.0, 4))
        assertTrue(follower.follows(path(30.0, -15.0), 5.0, 5))
        assertTrue(follower.follows(path(30.0, -15.0), 5.0, 6))
        assertFalse(follower.follows(path(30.0, -15.0), 5.0, 7))
    }

    @Test
    fun theSameReadingIsNeverCountedTwice() {
        val follower = onThePath()
        repeat(6) { assertTrue(follower.follows(path(30.0, -15.0), 5.0, 2)) }
        val joining = PathFollower()
        repeat(6) { assertFalse(joining.follows(path(20.0, 3.0), 5.0, 1)) }
    }

    @Test
    fun roughReadingsAreGivenMoreRoomButNeverMoreThanTheVoiceRule() {
        assertEquals(12.0, PathFollower.leaveLimit(5.0), 0.0)
        assertEquals(12.0, PathFollower.leaveLimit(null), 0.0)
        assertEquals(16.0, PathFollower.leaveLimit(10.0), 0.0)
        assertEquals(GuidanceEngine.OFF_PATH_METERS, PathFollower.leaveLimit(30.0), 0.0)

        val follower = onThePath()
        repeat(6) { reading -> assertTrue(follower.follows(path(30.0, -17.0), 12.0, 2L + reading)) }
        val leaving = onThePath()
        leaving.follows(path(30.0, -24.0), 30.0, 2)
        leaving.follows(path(30.0, -24.0), 30.0, 3)
        assertFalse("beyond 20 m no reading keeps the arrow on the line", leaving.follows(path(30.0, -24.0), 30.0, 4))
    }

    @Test
    fun aPathToAnotherPlaceStartsOver() {
        val follower = onThePath()
        assertTrue(follower.follows(path(20.0, -11.0), 5.0, 2))
        // 11 m from the walkway is too far to be put on a path the visitor has not started.
        assertFalse(follower.follows(path(20.0, -11.0, to = at(60.0)), 5.0, 3))
        assertFalse(follower.follows(null, 5.0, 4))
    }

    @Test
    fun closeToTheOfficeTheArrowShowsTheVisitorsOwnPosition() {
        val follower = onThePath()
        assertTrue(follower.follows(path(60.0, 25.0), 5.0, 2))
        assertFalse("9 m from the pin", follower.follows(path(60.0, 31.0), 5.0, 3))
        assertFalse(follower.follows(path(62.0, 36.0), 5.0, 4))
        // A rougher reading keeps its distance from the pin too.
        val rough = onThePath()
        assertFalse(rough.follows(path(60.0, 25.0), 16.0, 2))
    }

    @Test
    fun pastTheEndOfTheWalkwayThereIsNoLineToStandOn() {
        // The office pin is 20 m beyond where the walkway ends.
        val farOffice = at(60.0, 60.0)
        val follower = PathFollower()
        follower.follows(path(30.0, 0.0, to = farOffice), 5.0, 1)
        assertTrue(follower.follows(path(31.0, 0.0, to = farOffice), 5.0, 2))
        assertFalse("the walkway is behind them", follower.follows(path(60.0, 45.0, to = farOffice), 5.0, 3))
    }

    @Test
    fun onTheWalkwayThePathIsMeasuredFromTheLine() {
        // GPS has the visitor 5 m east of the walkway, 20 m up it.
        val planned = path(20.0, 5.0)
        assertEquals(5.0, planned.joinMeters, 0.1)
        val onLine = planned.onWalkway()
        assertEquals(0.0, onLine.joinMeters, 0.0)
        assertEquals(0, onLine.walkwayStart)
        assertEquals("starts at the line's own point", 0.0, GeoMath.distanceMeters(onLine.points.first(), at(20.0)), 0.1)
        assertEquals("40 m up, 40 m across", 80.0, onLine.lengthMeters, 0.5)
        assertEquals(planned.lengthMeters - planned.joinMeters, onLine.lengthMeters, 0.0001)
        assertEquals(TurnDirection.RIGHT, onLine.turns.single().direction)
        assertEquals(40.0, onLine.turns.single().atMeters, 1.0)
        assertEquals(planned.target, onLine.target)
        assertEquals(planned.finishMeters, onLine.finishMeters, 0.0001)
        assertEquals(0.0, GeoMath.distanceMeters(onLine.pointAt(6.0), at(26.0)), 0.1)

        val already = path(20.0, 0.0)
        assertSame("a visitor exactly on the line keeps the same path", already, already.onWalkway())
    }
}
