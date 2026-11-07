package ph.edu.isatu.visitor.ui

import java.time.LocalDate
import java.time.ZoneId
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import ph.edu.isatu.visitor.data.TeacherDto

class TeacherVisitsTest {
    private val friday = LocalDate.of(2026, 11, 6)
    private val reyes = TeacherDto(code = "T1", name = "Prof. Elena Reyes", shortName = "Prof. Reyes", course = "IT", department = "CCI")

    @Test
    fun initialsLeaveOutTheTitle() {
        assertEquals("ER", teacherInitials("Prof. Elena Reyes"))
        assertEquals("MV", teacherInitials("Engr. Marco Antonio Villanueva"))
        assertEquals("EL", teacherInitials("Elena Lim"))
        assertEquals("A", teacherInitials("Dr. Ana"))
        assertEquals("?", teacherInitials("  "))
    }

    @Test
    fun theNextFreeTimeIsSaidTheShortestWay() {
        assertEquals("Today, 1:00 PM", formatNextFree("2026-11-06 13:00:00", friday))
        assertEquals("Tomorrow, 9:00 AM", formatNextFree("2026-11-07 09:00:00", friday))
        assertEquals("Mon, 9:30 AM", formatNextFree("2026-11-09 09:30:00", friday))
        assertEquals("Thu, 9:30 AM", formatNextFree("2026-11-12 09:30:00", friday))
        assertEquals("Nov 13, 9:30 AM", formatNextFree("2026-11-13 09:30:00", friday))
        assertEquals("not a time", formatNextFree("not a time", friday))
    }

    @Test
    fun availabilityNeverSaysWhy() {
        assertEquals("Available now", teacherAvailability(reyes.copy(availableNow = true), friday))
        assertEquals("Next free: Today, 2:00 PM", teacherAvailability(reyes.copy(nextFreeAt = "2026-11-06 14:00:00"), friday))
        assertEquals("Not available", teacherAvailability(reyes, friday))
    }

    @Test
    fun walkInIsOffForAReason() {
        assertNull(teacherWalkInNote(reyes.copy(availableNow = true, acceptsWalkIns = true, canWalkIn = true)))
        assertEquals("Not available now, so walk-in is off.", teacherWalkInNote(reyes.copy(availableNow = false)))
        assertEquals("Prof. Reyes takes appointments only.", teacherWalkInNote(reyes.copy(availableNow = true, acceptsWalkIns = false, canWalkIn = false)))
    }

    @Test
    fun theWalkInPromiseNamesTheOfficeOnlyWhenThereIsOne() {
        val withOffice = teacherWalkInPromise(reyes, "CCI IT Office")
        assertTrue(withOffice, withOffice.contains("only if Prof. Reyes accepts"))
        assertTrue(withOffice, withOffice.contains("go to the CCI IT Office"))
        assertFalse(teacherWalkInPromise(reyes, null).contains("go to"))
    }

    @Test
    fun theCountdownStaysWithinItsLimits() {
        val manila = ZoneId.of("Asia/Manila")
        val closes = LocalDate.of(2026, 11, 6).atTime(10, 10).atZone(manila).toInstant().toEpochMilli()
        assertEquals(582, walkInSecondsLeft("2026-11-06 10:10:00", closes - 582_000))
        assertEquals(0, walkInSecondsLeft("2026-11-06 10:10:00", closes + 5_000))
        assertEquals(WALK_IN_ANSWER_SECONDS, walkInSecondsLeft("2026-11-06 10:10:00", closes - 9_000_000))
        assertEquals(0, walkInSecondsLeft(null, closes))
        assertEquals("9:42", formatCountdown(582))
        assertEquals("0:05", formatCountdown(5))
        assertEquals("0:00", formatCountdown(-3))
    }

    @Test
    fun teachersAreFoundByNameAndCourse() {
        val tan = TeacherDto(code = "T2", name = "Prof. Rosa Tan", course = "IS")
        val salazar = TeacherDto(code = "T3", name = "Dr. Ana Salazar", course = "CS")
        val all = listOf(reyes, tan, salazar)
        assertEquals(all, filterTeachers(all, "", ""))
        assertEquals(listOf(tan), filterTeachers(all, "tan", ""))
        assertEquals(listOf(tan), filterTeachers(all, "  ROSA  tan ", ""))
        assertEquals(listOf(salazar), filterTeachers(all, "", "CS"))
        assertEquals(emptyList<TeacherDto>(), filterTeachers(all, "tan", "CS"))
    }
}
