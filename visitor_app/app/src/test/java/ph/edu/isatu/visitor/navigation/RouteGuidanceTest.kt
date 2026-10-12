package ph.edu.isatu.visitor.navigation

import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Directions along a recorded walkway: from the gate 60 m north, then 40 m east to the office. */
class RouteGuidanceTest {
    private val gate = GeoPoint(10.7177, 122.5559)

    /** A point [north] and [east] meters from the gate. */
    private fun at(north: Double, east: Double = 0.0) = GeoPoint(
        gate.latitude + north / 111_194.93,
        gate.longitude + east / (111_194.93 * cos(gate.latitude * PI / 180)),
    )

    private val office = at(60.0, 40.0)
    private val itOffice = GuidanceTarget("office:1:IT", "IT Department", TargetKind.OFFICE, office, appointmentId = 1, officeCode = "IT")
    private val planner = WalkwayPlanner(listOf(Walkway(1, "IT", "Main Gate → IT Department", listOf(at(0.0), at(60.0), office))))

    private fun GuidanceEngine.walk(
        north: Double,
        east: Double,
        second: Long,
        heading: Double = 0.0,
        accuracy: Double = 5.0,
        target: GuidanceTarget = itOffice,
        walkways: WalkwayPlanner = planner,
    ): GuidanceUpdate {
        val fix = LocationFix(at(north, east), accuracy, second * 1000)
        val path = walkways.plan(fix.point, target.point!!, targetOffice = target.officeCode)
        return update(fix, heading, target, null, DistanceUnit.METERS, ArrivalRule(3.0, 8.0), path)
    }

    private fun GuidanceUpdate.said() = prompts.map { it.text }

    @Test
    fun opensWithTheWalkingDistanceAlongThePath() {
        val update = GuidanceEngine().walk(0.0, 0.0, 0)
        assertEquals(listOf("Heading to IT Department. Follow the blue line, 100 meters to walk."), update.said())
        val route = update.snapshot.route!!
        assertEquals(100.0, route.remainingMeters, 0.5)
        assertEquals(TurnDirection.RIGHT, route.nextTurn)
        assertEquals(60.0, route.nextTurnMeters!!, 1.0)
        assertEquals("straight-line distance is still the pin's", 72.1, update.snapshot.distanceMeters!!, 0.2)
        assertEquals("the arrow points along the path (north), not at the office", 0.0, update.snapshot.bearingDegrees!!, 1.0)
        assertEquals(RelativeSide.AHEAD, update.snapshot.side)
    }

    @Test
    fun announcesEachTurnAheadAndAtTheTurnThenTheEnd() {
        val engine = GuidanceEngine()
        engine.walk(0.0, 0.0, 0)
        assertTrue(engine.walk(20.0, 0.0, 15).said().isEmpty())
        assertEquals(listOf("In 25 meters, turn right."), engine.walk(35.0, 0.0, 30).said())
        val atTurn = engine.walk(54.0, 0.0, 40)
        assertEquals(listOf("Turn right now."), atTurn.said())
        assertTrue("turn prompts interrupt", atTurn.prompts.single().urgent)
        assertTrue("never twice", engine.walk(56.0, 0.0, 42).said().isEmpty())
        assertEquals(listOf("35 meters to IT Department."), engine.walk(60.0, 6.0, 50, heading = 90.0).said())
        assertEquals(listOf("IT Department is just ahead."), engine.walk(60.0, 18.0, 58, heading = 90.0).said())

        // Arrival is still judged at the pin itself, from accurate readings in a row.
        engine.walk(60.0, 39.0, 70, heading = 90.0)
        engine.walk(60.0, 39.2, 71, heading = 90.0)
        val arrived = engine.walk(60.0, 39.5, 72, heading = 90.0)
        assertEquals(GuidancePhase.ARRIVED, arrived.snapshot.phase)
        assertEquals(listOf("You have arrived at IT Department."), arrived.said())
        assertNotNull(arrived.arrival)
    }

    @Test
    fun pointsOutLeavingThePathAndComingBack() {
        val engine = GuidanceEngine()
        engine.walk(0.0, 0.0, 0)
        assertTrue(engine.walk(30.0, -25.0, 20).said().isEmpty())
        assertTrue(engine.walk(30.0, -25.0, 21).said().isEmpty())
        val off = engine.walk(30.0, -25.0, 22)
        assertEquals(listOf("You're off the path. The path is 25 meters to your right."), off.said())
        assertEquals(25.0, off.snapshot.route!!.offPathMeters, 0.5)
        assertEquals("Go back to the path, 25 m away", routeInstruction(off.snapshot.route!!, DistanceUnit.METERS))
        assertTrue("said once", engine.walk(30.0, -25.0, 40).said().isEmpty())
        assertEquals(listOf("You're back on the path."), engine.walk(32.0, -3.0, 50).said())
    }

    @Test
    fun roughReadingsNeverCountAsOffThePath() {
        val engine = GuidanceEngine()
        engine.walk(0.0, 0.0, 0)
        repeat(4) { second -> assertTrue(engine.walk(30.0, -24.0, 20L + second, accuracy = 18.0).said().isEmpty()) }
    }

    @Test
    fun walkingBackAlongThePathIsPointedOut() {
        val engine = GuidanceEngine()
        engine.walk(0.0, 0.0, 0)
        assertEquals(listOf("In 20 meters, turn right."), engine.walk(40.0, 0.0, 20).said())
        assertEquals(
            listOf("You're going the wrong way. Turn around and follow the blue line."),
            engine.walk(10.0, 0.0, 40, heading = 180.0).said(),
        )
    }

    @Test
    fun slightBendsAreShownButNotSpoken() {
        val bend = GeoPoint(at(50.0).latitude, at(50.0).longitude)
        val end = at(50.0 + 30 * cos(40 * PI / 180), 30 * sin(40 * PI / 180))
        val target = itOffice.copy(point = end)
        val walkways = WalkwayPlanner(listOf(Walkway(1, "IT", "Main Gate → IT Department", listOf(at(0.0), bend, end))))
        val engine = GuidanceEngine()
        engine.walk(0.0, 0.0, 0, target = target, walkways = walkways)
        val near = engine.walk(25.0, 0.0, 20, target = target, walkways = walkways)
        assertTrue(near.said().isEmpty())
        assertEquals(TurnDirection.SLIGHT_RIGHT, near.snapshot.route!!.nextTurn)
        assertEquals("Keep slightly right in 25 m", routeInstruction(near.snapshot.route!!, DistanceUnit.METERS))
    }

    @Test
    fun aPathToAnotherPlaceIsIgnored() {
        val fix = LocationFix(at(0.0), 5.0, 0)
        val elsewhere = planner.plan(fix.point, at(60.0))
        val update = GuidanceEngine().update(fix, 0.0, itOffice, null, DistanceUnit.METERS, ArrivalRule(), elsewhere)
        assertNull(update.snapshot.route)
        assertEquals(listOf("Heading to IT Department. It's 70 meters away, slightly to your right."), update.said())
    }

    @Test
    fun onTheWalkwayDirectionsFollowTheLineNotGpsWander() {
        // GPS places the visitor 5 m beside the walkway, 20 m up it; they count as on it.
        val fix = LocationFix(at(20.0, 5.0), 5.0, 0)
        val path = planner.plan(fix.point, office, targetOffice = "IT")!!.onWalkway()
        val update = GuidanceEngine().update(fix, 0.0, itOffice, null, DistanceUnit.METERS, ArrivalRule(3.0, 8.0), path)
        val bearing = update.snapshot.bearingDegrees!!
        assertEquals("the arrow points up the walkway, not 40° across it", 0.0, GeoMath.signedDifference(bearing, 0.0), 1.0)
        val route = update.snapshot.route!!
        assertEquals(0.0, route.offPathMeters, 0.0)
        assertEquals(80.0, route.remainingMeters, 0.5)
        assertEquals(40.0, route.nextTurnMeters!!, 1.0)
        assertEquals(listOf("Heading to IT Department. Follow the blue line, 80 meters to walk."), update.said())
        assertEquals("Turn right in 40 m", routeInstruction(route, DistanceUnit.METERS))
    }

    @Test
    fun bannerInstructions() {
        val m = DistanceUnit.METERS
        assertEquals("Turn right in 25 m", routeInstruction(RouteGuidance(100.0, 0.0, TurnDirection.RIGHT, 25.0), m))
        assertEquals("Turn right now", routeInstruction(RouteGuidance(46.0, 0.0, TurnDirection.RIGHT, 6.0), m))
        assertEquals("Make a sharp left in 40 m", routeInstruction(RouteGuidance(90.0, 2.0, TurnDirection.SHARP_LEFT, 40.0), m))
        assertEquals("Keep slightly left", routeInstruction(RouteGuidance(100.0, 0.0, TurnDirection.SLIGHT_LEFT, 5.0), m))
        assertEquals("Continue on the path", routeInstruction(RouteGuidance(200.0, 0.0, TurnDirection.LEFT, 90.0), m))
        assertEquals("Almost there", routeInstruction(RouteGuidance(20.0, 0.0), m))
        assertEquals("Turn left in 65 ft", routeInstruction(RouteGuidance(100.0, 0.0, TurnDirection.LEFT, 20.0), DistanceUnit.FEET))
        assertEquals(2, walkingMinutesAlongPath(100.0))
        assertEquals(1, walkingMinutesAlongPath(10.0))
    }
}
