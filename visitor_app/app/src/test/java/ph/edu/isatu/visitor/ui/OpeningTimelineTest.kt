package ph.edu.isatu.visitor.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class OpeningTimelineTest {
    private val beats = listOf(440L, 820L, 1200L, 1580L)

    @Test
    fun lastsBetweenThreeAndFourSeconds() {
        assertTrue(OpeningTimeline.TOTAL_MS in 3000L..4000L)
    }

    @Test
    fun arrivesSmallAndWholeThenWindsBackClosed() {
        val first = OpeningTimeline.at(0)
        assertTrue(first.growth < 0.5f)
        assertEquals(0f, first.turnDegrees, 0.001f)
        assertEquals(OpeningTimeline.FULL_APERTURE, first.aperture, 0.001f)
        assertEquals(1f, first.flameAlpha, 0.001f)

        val wound = OpeningTimeline.at(OpeningTimeline.WIND_MS)
        assertEquals(1f, wound.growth, 0.001f)
        assertEquals(-90f, wound.turnDegrees, 0.001f)
        assertEquals(0f, wound.aperture, 0.001f)
        assertEquals(0f, wound.flameAlpha, 0.001f)
    }

    @Test
    fun eachBeatTurnsOneToothAndOpensTheCentre() {
        val turns = listOf(-67.5f, -45f, -22.5f, 0f)
        val apertures = listOf(0.14f, 0.225f, 0.29f, 0.35f)
        beats.forEachIndexed { index, beat ->
            val rest = OpeningTimeline.at(beat + OpeningTimeline.CLICK_MS)
            assertEquals("turn after beat $index", turns[index], rest.turnDegrees, 0.001f)
            assertEquals("centre after beat $index", apertures[index], rest.aperture, 0.001f)
        }
    }

    @Test
    fun aClickOvershootsAndSettles() {
        val turnsDuringClick = (0..30).map { OpeningTimeline.at(440L + it * 10).turnDegrees }
        assertTrue("it swings past the next tooth", turnsDuringClick.max() > -67.5f)
        assertEquals(-67.5f, turnsDuringClick.last(), 0.001f)
    }

    @Test
    fun theCentreIsNeverAnImpossibleSize() {
        for (ms in 0L..OpeningTimeline.TOTAL_MS step 5) {
            val frame = OpeningTimeline.at(ms)
            assertTrue("aperture at $ms", frame.aperture >= 0f)
            assertTrue("words at $ms", frame.wordsAlpha in 0f..1f)
            assertTrue("glow at $ms", frame.glowAlpha in 0f..1f)
            assertTrue("flame at $ms", frame.flameAlpha in 0f..1f)
            assertTrue("flight at $ms", frame.flight in 0f..1f)
        }
    }

    @Test
    fun theTorchLightsWithTheFourthTooth() {
        assertEquals(0f, OpeningTimeline.at(1600).flameAlpha, 0.001f)
        assertEquals(0f, OpeningTimeline.at(1600).glowAlpha, 0.001f)
        val burning = OpeningTimeline.at(1900)
        assertEquals(1f, burning.flameAlpha, 0.001f)
        assertTrue(burning.glowAlpha > 0.5f)
        val settled = OpeningTimeline.at(2600)
        assertEquals(1f, settled.flameScaleX, 0.001f)
        assertEquals(1f, settled.flameScaleY, 0.001f)
        assertEquals(0f, settled.glowAlpha, 0.001f)
    }

    @Test
    fun theGreetingHoldsLongEnoughToRead() {
        assertEquals(0f, OpeningTimeline.at(OpeningTimeline.WORDS_AT - 1).wordsAlpha, 0.001f)
        assertEquals(1f, OpeningTimeline.at(OpeningTimeline.WORDS_AT + 220).wordsAlpha, 0.001f)
        assertEquals(1f, OpeningTimeline.at(OpeningTimeline.LEAVE_AT).wordsAlpha, 0.001f)
        // As long as the web's greeting, give or take: the user found a short one too quick.
        assertTrue(OpeningTimeline.LEAVE_AT - OpeningTimeline.WORDS_AT >= 1500L)
        assertEquals(0f, OpeningTimeline.at(OpeningTimeline.LEAVE_AT + 140).wordsAlpha, 0.001f)
    }

    @Test
    fun itLandsWhole() {
        assertEquals(0f, OpeningTimeline.at(OpeningTimeline.LEAVE_AT).flight, 0.001f)
        val landed = OpeningTimeline.at(OpeningTimeline.TOTAL_MS)
        assertEquals(1f, landed.flight, 0.001f)
        assertEquals(0f, landed.turnDegrees, 0.001f)
        assertEquals(OpeningTimeline.FULL_APERTURE, landed.aperture, 0.001f)
        assertEquals(1f, landed.flameAlpha, 0.001f)
    }

    @Test
    fun aTapSendsItHomeFromWhereverItIsAndItStillLandsWhole() {
        val tappedAt = 700L
        val atTap = OpeningTimeline.at(tappedAt, leaveAt = tappedAt)
        assertEquals(0f, atTap.flight, 0.001f)
        assertEquals(-67.5f, atTap.turnDegrees, 0.5f)

        val landed = OpeningTimeline.at(tappedAt + OpeningTimeline.LEAVE_MS, leaveAt = tappedAt)
        assertEquals(1f, landed.flight, 0.001f)
        assertEquals(0f, landed.turnDegrees, 0.001f)
        assertEquals(OpeningTimeline.FULL_APERTURE, landed.aperture, 0.001f)
        assertEquals(1f, landed.flameAlpha, 0.001f)
        assertEquals(1f, landed.growth, 0.001f)
        assertEquals(0f, landed.wordsAlpha, 0.001f)
    }

    @Test
    fun theMotionHasNoJumps() {
        var previous = OpeningTimeline.at(OpeningTimeline.WIND_MS)
        for (ms in OpeningTimeline.WIND_MS + 5..OpeningTimeline.TOTAL_MS step 5) {
            val frame = OpeningTimeline.at(ms)
            assertTrue("gear jumped at $ms", kotlin.math.abs(frame.turnDegrees - previous.turnDegrees) < 2.5f)
            assertTrue("centre jumped at $ms", kotlin.math.abs(frame.aperture - previous.aperture) < 0.02f)
            previous = frame
        }
        assertFalse(previous.flight < 1f)
    }

    @Test
    fun theGreetingKnowsWhoItIsTalkingTo() {
        assertEquals("Welcome back, Juan", greetingFor(signedIn = true, fullName = " Juan Dela Cruz "))
        assertEquals("Welcome back, Cher", greetingFor(signedIn = true, fullName = "Cher"))
        assertEquals("Welcome back", greetingFor(signedIn = true, fullName = null))
        assertEquals("Welcome back", greetingFor(signedIn = true, fullName = "   "))
        assertEquals("Welcome to ISATU", greetingFor(signedIn = false, fullName = null))
        // A name left behind must not greet someone who has signed out.
        assertEquals("Welcome to ISATU", greetingFor(signedIn = false, fullName = "Juan Dela Cruz"))
    }
}
