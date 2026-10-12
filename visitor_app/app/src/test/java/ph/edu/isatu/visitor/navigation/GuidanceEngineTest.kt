package ph.edu.isatu.visitor.navigation

import kotlin.math.PI
import kotlin.math.cos
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class GuidanceEngineTest {
    private val office = GeoPoint(10.7177, 122.5559)
    private val itOffice = GuidanceTarget(
        key = "office:1:IT",
        name = "IT Department",
        kind = TargetKind.OFFICE,
        point = office,
        detail = "CCI building, room 201",
        appointmentId = 1,
    )

    /** A point [north] and [east] meters away from the office. */
    private fun at(north: Double, east: Double = 0.0) = GeoPoint(
        office.latitude + north / 111_194.93,
        office.longitude + east / (111_194.93 * cos(office.latitude * PI / 180)),
    )

    private fun fix(north: Double, seconds: Long, accuracy: Double = 5.0, east: Double = 0.0) =
        LocationFix(at(north, east), accuracy, seconds * 1000)

    private fun GuidanceEngine.step(
        fix: LocationFix?,
        heading: Double? = 0.0,
        target: GuidanceTarget? = itOffice,
        campus: CampusArea? = null,
        unit: DistanceUnit = DistanceUnit.METERS,
    ) = update(fix, heading, target, campus, unit, ArrivalRule(distanceMeters = 3.0, maxAccuracyMeters = 8.0))

    @Test
    fun firstPromptGivesDistanceAndSide() {
        val update = GuidanceEngine().step(fix(-150.0, 0))
        assertEquals(GuidancePhase.NAVIGATING, update.snapshot.phase)
        assertEquals(RelativeSide.AHEAD, update.snapshot.side)
        assertEquals(listOf("Heading to IT Department. It's 150 meters away, straight ahead."), update.prompts.map { it.text })
    }

    @Test
    fun milestonesLeadToAnExactArrival() {
        val engine = GuidanceEngine()
        engine.step(fix(-150.0, 0))
        assertTrue(engine.step(fix(-120.0, 10)).prompts.isEmpty())
        assertEquals(listOf("IT Department is 100 meters away, straight ahead."), engine.step(fix(-98.0, 20)).prompts.map { it.text })
        assertTrue("GPS jitter must not repeat a milestone", engine.step(fix(-99.0, 27)).prompts.isEmpty())
        assertEquals(listOf("IT Department is 50 meters away, straight ahead."), engine.step(fix(-49.0, 40)).prompts.map { it.text })
        assertEquals(listOf("IT Department is just ahead."), engine.step(fix(-24.0, 50)).prompts.map { it.text })
        assertEquals(listOf("IT Department is 10 meters away, straight ahead."), engine.step(fix(-9.6, 60)).prompts.map { it.text })

        // Inside the reading's own uncertainty but not yet within 3 m: very close, not arrived.
        val close = engine.step(fix(-4.0, 70))
        assertEquals(GuidancePhase.VERY_CLOSE, close.snapshot.phase)
        assertTrue(close.prompts.isEmpty())
        assertEquals(GuidancePhase.VERY_CLOSE, engine.step(fix(-2.5, 71)).snapshot.phase)
        val confirming = engine.step(fix(-2.0, 72))
        assertEquals("two readings within 3 m are not enough", GuidancePhase.VERY_CLOSE, confirming.snapshot.phase)
        assertTrue("no 'very close' prompt while GPS is confirming the arrival", confirming.prompts.isEmpty())

        val arrival = engine.step(fix(-1.5, 73))
        assertEquals(GuidancePhase.ARRIVED, arrival.snapshot.phase)
        assertEquals(ArrivalMethod.GPS, arrival.snapshot.arrivedBy)
        assertEquals(listOf("You have arrived at IT Department. It's at CCI building, room 201."), arrival.prompts.map { it.text })
        assertTrue(arrival.prompts.single().urgent)
        val event = arrival.arrival!!
        assertEquals(ArrivalMethod.GPS, event.method)
        assertEquals(1L, event.target.appointmentId)
        assertEquals(1.5, event.distanceMeters!!, 0.05)
        assertEquals(5.0, event.accuracyMeters!!, 0.0001)

        val drift = engine.step(fix(-80.0, 90))
        assertEquals("indoor drift never restarts directions", GuidancePhase.ARRIVED, drift.snapshot.phase)
        assertTrue(drift.prompts.isEmpty())
        assertNull(drift.arrival)
    }

    @Test
    fun weakGpsNeverAnnouncesAnArrivalFromNinetyFeet() {
        // The reported bug: about 90 ft (27 m) away with GPS accurate to only ±30 m.
        val engine = GuidanceEngine()
        repeat(6) { second ->
            val update = engine.step(fix(-27.4, second.toLong(), accuracy = 30.0))
            assertNotEquals(GuidancePhase.ARRIVED, update.snapshot.phase)
            assertNotEquals(GuidancePhase.VERY_CLOSE, update.snapshot.phase)
            assertTrue(update.prompts.isEmpty())
            assertNull(update.arrival)
        }
    }

    @Test
    fun gpsThatCannotConfirmSaysVeryCloseOnce() {
        val engine = GuidanceEngine()
        assertTrue(engine.step(fix(-2.0, 0, accuracy = 10.0)).prompts.isEmpty())
        assertTrue(engine.step(fix(-2.0, 1, accuracy = 10.0)).prompts.isEmpty())
        val third = engine.step(fix(-2.0, 2, accuracy = 10.0))
        assertEquals(GuidancePhase.VERY_CLOSE, third.snapshot.phase)
        assertEquals(
            listOf("You're very close to IT Department. It's at CCI building, room 201. Tap I'm here when you reach it."),
            third.prompts.map { it.text },
        )
        assertNull("±10 m can't confirm a 3 m arrival", third.arrival)
        assertTrue(engine.step(fix(-2.0, 10, accuracy = 10.0)).prompts.isEmpty())
    }

    @Test
    fun arrivalNeedsThreeReadingsInARow() {
        val engine = GuidanceEngine()
        engine.step(fix(-2.0, 0))
        engine.step(fix(-2.0, 1))
        assertNotEquals(GuidancePhase.ARRIVED, engine.step(fix(-6.0, 2)).snapshot.phase)
        assertNotEquals(GuidancePhase.ARRIVED, engine.step(fix(-2.0, 3)).snapshot.phase)
        assertNotEquals(GuidancePhase.ARRIVED, engine.step(fix(-2.0, 4)).snapshot.phase)
        assertEquals(GuidancePhase.ARRIVED, engine.step(fix(-2.0, 5)).snapshot.phase)
    }

    @Test
    fun arrivalIsJudgedFromTheReadingNotTheSteadiedPosition() {
        val engine = GuidanceEngine()
        val rule = ArrivalRule(distanceMeters = 3.0, maxAccuracyMeters = 8.0)
        // The steadied position sits on the pin, but GPS itself still reads 9 m away.
        repeat(5) { second ->
            val update = engine.update(
                fix(-1.0, second.toLong()), 0.0, itOffice, null, DistanceUnit.METERS, rule,
                reading = fix(-9.0, second.toLong()),
            )
            assertNotEquals(GuidancePhase.ARRIVED, update.snapshot.phase)
            assertNull(update.arrival)
        }
        // GPS itself within 3 m three times in a row: arrived, reported with the reading's own numbers.
        engine.update(fix(-5.0, 10), 0.0, itOffice, null, DistanceUnit.METERS, rule, reading = fix(-2.0, 10))
        engine.update(fix(-5.0, 11), 0.0, itOffice, null, DistanceUnit.METERS, rule, reading = fix(-2.0, 11))
        val arrival = engine.update(
            fix(-5.0, 12), 0.0, itOffice, null, DistanceUnit.METERS, rule,
            reading = fix(-2.0, 12, accuracy = 6.0),
        )
        assertEquals(GuidancePhase.ARRIVED, arrival.snapshot.phase)
        assertEquals(2.0, arrival.arrival!!.distanceMeters!!, 0.05)
        assertEquals(6.0, arrival.arrival!!.accuracyMeters!!, 0.0001)
    }

    @Test
    fun aRoughReadingNeverConfirmsAnArrivalWhateverTheSteadiedPositionSays() {
        val engine = GuidanceEngine()
        val rule = ArrivalRule(distanceMeters = 3.0, maxAccuracyMeters = 8.0)
        repeat(6) { second ->
            val update = engine.update(
                fix(-1.0, second.toLong(), accuracy = 5.0), 0.0, itOffice, null, DistanceUnit.METERS, rule,
                reading = fix(-1.0, second.toLong(), accuracy = 12.0),
            )
            assertNotEquals(GuidancePhase.ARRIVED, update.snapshot.phase)
            assertNull(update.arrival)
        }
    }

    @Test
    fun visitorCanConfirmTheirArrival() {
        val engine = GuidanceEngine()
        engine.step(fix(-40.0, 0))
        val confirmed = engine.confirmArrival(fix(-40.0, 5), itOffice, 5_000)!!
        assertEquals(GuidancePhase.ARRIVED, confirmed.snapshot.phase)
        assertEquals(ArrivalMethod.VISITOR, confirmed.snapshot.arrivedBy)
        assertEquals(listOf("Arrival confirmed at IT Department."), confirmed.prompts.map { it.text })
        assertEquals(ArrivalMethod.VISITOR, confirmed.arrival!!.method)
        assertEquals(40.0, confirmed.arrival!!.distanceMeters!!, 0.1)

        val after = engine.step(fix(-38.0, 10))
        assertEquals(GuidancePhase.ARRIVED, after.snapshot.phase)
        assertTrue(after.prompts.isEmpty())
        assertNull("a confirmed arrival can't be confirmed twice", engine.confirmArrival(fix(-38.0, 11), itOffice, 11_000))
    }

    @Test
    fun anArrivalAlreadyOnRecordIsShownQuietly() {
        val update = GuidanceEngine().step(fix(-60.0, 0), target = itOffice.copy(arrivedBy = ArrivalMethod.GPS))
        assertEquals(GuidancePhase.ARRIVED, update.snapshot.phase)
        assertTrue(update.prompts.isEmpty())
        assertNull(update.arrival)
    }

    @Test
    fun distancesAreOnlySpokenFromAccurateReadings() {
        val engine = GuidanceEngine()
        val rough = engine.step(fix(-150.0, 0, accuracy = 30.0))
        assertEquals(GuidancePhase.NAVIGATING, rough.snapshot.phase)
        assertTrue("±30 m is too rough to speak a distance", rough.prompts.isEmpty())
        assertEquals(GuidancePhase.WEAK_SIGNAL, engine.step(fix(-150.0, 1, accuracy = 60.0)).snapshot.phase)
        assertEquals(1, engine.step(fix(-150.0, 2)).prompts.size)
    }

    @Test
    fun promptsAreNeverCloserThanSixSeconds() {
        val engine = GuidanceEngine()
        engine.step(fix(-150.0, 0))
        assertTrue(engine.step(fix(-95.0, 3)).prompts.isEmpty())
        assertEquals(listOf("IT Department is 95 meters away, straight ahead."), engine.step(fix(-94.0, 7)).prompts.map { it.text })
    }

    @Test
    fun leavingTheCampusWarnsOnceAndReturningIsAcknowledged() {
        val campus = CampusArea(boundary = listOf(at(-200.0, -200.0), at(-200.0, 200.0), at(200.0, 200.0), at(200.0, -200.0)))
        val engine = GuidanceEngine()

        val outside = engine.step(fix(230.0, 0), campus = campus)
        assertEquals(GuidancePhase.OUTSIDE_CAMPUS, outside.snapshot.phase)
        assertEquals(0L, outside.snapshot.outsideSinceMillis)
        assertEquals(
            listOf("You are outside the campus. Your visit ends automatically if you stay outside for 5 minutes."),
            outside.prompts.map { it.text },
        )
        assertTrue(outside.prompts.single().urgent)
        assertTrue(engine.step(fix(240.0, 10), campus = campus).prompts.isEmpty())

        val back = engine.step(fix(100.0, 20), campus = campus)
        assertEquals(GuidancePhase.NAVIGATING, back.snapshot.phase)
        assertEquals("Welcome back to the campus.", back.prompts.first().text)
        assertEquals("Heading to IT Department. It's 100 meters away, behind you.", back.prompts.last().text)
    }

    @Test
    fun withinTheBoundaryBufferStillCountsAsInside() {
        val campus = CampusArea(boundary = listOf(at(-200.0, -200.0), at(-200.0, 200.0), at(200.0, 200.0), at(200.0, -200.0)))
        assertEquals(GuidancePhase.NAVIGATING, GuidanceEngine().step(fix(215.0, 0), campus = campus).snapshot.phase)
    }

    @Test
    fun inaccurateReadingsNeverCountAsLeaving() {
        val campus = CampusArea(boundary = listOf(at(-200.0, -200.0), at(-200.0, 200.0), at(200.0, 200.0), at(200.0, -200.0)))
        val update = GuidanceEngine().step(fix(260.0, 0, accuracy = 80.0), campus = campus)
        assertEquals(GuidancePhase.WEAK_SIGNAL, update.snapshot.phase)
        assertTrue(update.prompts.isEmpty())
    }

    @Test
    fun officeWithoutPinAsksForDirectionsOnce() {
        val engine = GuidanceEngine()
        val noPin = itOffice.copy(point = null)
        val first = engine.step(fix(-50.0, 0), target = noPin)
        assertEquals(GuidancePhase.NO_PIN, first.snapshot.phase)
        assertEquals(listOf("IT Department isn't on the campus map yet. Please ask a guard for directions."), first.prompts.map { it.text })
        assertTrue(engine.step(fix(-50.0, 30), target = noPin).prompts.isEmpty())
    }

    @Test
    fun aNewTargetStartsAgainWithItsOwnOpening() {
        val engine = GuidanceEngine()
        engine.step(fix(-5.0, 0))
        val deans = GuidanceTarget("office:2:DEANS", "Dean's Office", TargetKind.OFFICE, at(0.0, 60.0), startLine = "Next stop: Dean's Office")
        val next = engine.step(fix(0.0, 30), target = deans)
        assertEquals(listOf("Next stop: Dean's Office. It's 60 meters away, to your right."), next.prompts.map { it.text })
    }

    @Test
    fun gateArrivalAsksForTheCheckOutScan() {
        val gate = GuidanceTarget("gate:Main Gate", "Main Gate", TargetKind.GATE, office)
        val engine = GuidanceEngine()
        engine.step(fix(-1.0, 0), target = gate)
        engine.step(fix(-1.0, 1), target = gate)
        val update = engine.step(fix(-1.0, 2), target = gate)
        assertEquals(GuidancePhase.ARRIVED, update.snapshot.phase)
        assertEquals("You have arrived at Main Gate. Show your pass to the guard to check out.", update.prompts.single().text)
        assertNull("gates are not reported as office arrivals", update.arrival!!.target.appointmentId)
    }

    @Test
    fun feetAreSpokenWhenChosen() {
        val update = GuidanceEngine().step(fix(-150.0, 0), unit = DistanceUnit.FEET)
        assertEquals("Heading to IT Department. It's 490 feet away, straight ahead.", update.prompts.single().text)
    }

    @Test
    fun walkingAwayIsPointedOut() {
        val engine = GuidanceEngine()
        engine.step(fix(-150.0, 0))
        engine.step(fix(-120.0, 10))
        val away = engine.step(fix(-150.0, 20), heading = 180.0)
        assertEquals(listOf("You're moving away from IT Department. It's 150 meters away, behind you."), away.prompts.map { it.text })
    }

    @Test
    fun walkingDirectionBeatsTheCompassWhenMoving() {
        val moving = LocationFix(office, 5.0, 0, speedMetersPerSecond = 1.5, courseDegrees = 90.0)
        val standing = moving.copy(speedMetersPerSecond = 0.3)
        assertEquals(90.0, GuidanceEngine.chooseHeading(moving, 0.0)!!, 0.0001)
        assertEquals(0.0, GuidanceEngine.chooseHeading(standing, 0.0)!!, 0.0001)
    }

    @Test
    fun sidesFollowTheAngle() {
        assertEquals(RelativeSide.AHEAD, GuidanceEngine.sideFor(-15.0))
        assertEquals(RelativeSide.SLIGHT_LEFT, GuidanceEngine.sideFor(-45.0))
        assertEquals(RelativeSide.RIGHT, GuidanceEngine.sideFor(100.0))
        assertEquals(RelativeSide.BEHIND, GuidanceEngine.sideFor(170.0))
    }

    @Test
    fun distancesAreExactWhenClose() {
        assertEquals(3, roundedMeters(3.0))
        assertEquals(1, roundedMeters(0.3))
        assertEquals(13, roundedMeters(13.4))
        assertEquals(45, roundedMeters(44.0))
        assertEquals(160, roundedMeters(155.0))
        assertEquals("1 meter", spokenDistance(1.0, DistanceUnit.METERS))
        assertEquals("1.2 kilometers", spokenDistance(1234.0, DistanceUnit.METERS))
        assertEquals("45 m", displayDistance(44.0, DistanceUnit.METERS))
        assertEquals("1.2 km", displayDistance(1234.0, DistanceUnit.METERS))
        assertEquals("3 ft", displayDistance(1.0, DistanceUnit.FEET))
        assertEquals("1 foot", spokenDistance(0.3, DistanceUnit.FEET))
        assertEquals("±4 m", displayAccuracy(4.2, DistanceUnit.METERS))
        assertEquals("±14 ft", displayAccuracy(4.2, DistanceUnit.FEET))
        assertEquals(2, walkingMinutes(100.0))
        assertEquals(1, walkingMinutes(5.0))
    }
}
