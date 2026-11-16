package ph.edu.isatu.visitor.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import ph.edu.isatu.visitor.data.AppointmentDto
import ph.edu.isatu.visitor.data.OfficeSummaryDto
import ph.edu.isatu.visitor.data.VisitSummaryDto
import java.time.LocalDateTime

/** What Profile → Delete my account says before the server does it (account_removal_service.php). */
class AccountDeletionTest {
    private val now = LocalDateTime.of(2026, 11, 17, 10, 0)

    private fun booking(
        id: Long,
        status: String,
        office: String = "IT",
        end: String = "2026-11-20 09:30:00",
        visit: VisitSummaryDto? = null,
    ) = AppointmentDto(id = id, status = status, office = OfficeSummaryDto(office, "$office Office"), scheduledEndAt = end, visit = visit)

    @Test
    fun aSingleVisitCheckedInIsOnCampus() {
        assertTrue(onCampusNow(listOf(booking(1, "checked_in"))))
        assertFalse(onCampusNow(listOf(booking(1, "approved"), booking(2, "completed"))))
        assertFalse(onCampusNow(emptyList()))
    }

    @Test
    fun aMultiStopVisitIsOnCampusByTheVisitNotTheStop() {
        val inside = VisitSummaryDto(id = 7, status = "checked_in")
        val left = VisitSummaryDto(id = 8, status = "completed")
        // The next stop is still "approved" while the visitor walks there.
        assertTrue(onCampusNow(listOf(booking(1, "completed", visit = inside), booking(2, "approved", visit = inside))))
        // A stop left "checked_in" after the visit ended doesn't keep the visitor inside.
        assertFalse(onCampusNow(listOf(booking(3, "checked_in", visit = left))))
    }

    @Test
    fun upcomingCountsVisitsAsTheListDoesAndEveryOffice() {
        val trip = VisitSummaryDto(id = 9, status = "open")
        val upcoming = upcomingBookings(
            listOf(
                booking(1, "approved"),
                booking(2, "pending_approval", office = "REG", visit = trip),
                booking(3, "reschedule_proposed", office = "CAS", visit = trip),
                booking(4, "completed"),
                booking(5, "cancelled"),
                booking(6, "rejected"),
            ),
            now,
        )
        assertEquals(UpcomingBookings(visits = 2, offices = 3), upcoming)
    }

    @Test
    fun aBookingWhoseTimeIsOverIsNotUpcoming() {
        val upcoming = upcomingBookings(listOf(booking(1, "approved", end = "2026-11-17 09:59:00")), now)
        assertEquals(UpcomingBookings(visits = 0, offices = 0), upcoming)
        // A time the app can't read still counts: the server cancels it either way.
        assertEquals(1, upcomingBookings(listOf(booking(2, "approved", end = "")), now).visits)
    }

    @Test
    fun theSheetSaysHowManyAndWhoIsTold() {
        assertNull(upcomingLine(UpcomingBookings(0, 0)))
        assertEquals("Your upcoming booking is cancelled, and the office is told.", upcomingLine(UpcomingBookings(1, 1)))
        assertEquals("Your upcoming booking is cancelled, and the offices are told.", upcomingLine(UpcomingBookings(1, 2)))
        assertEquals("Your 2 upcoming bookings are cancelled, and the office is told.", upcomingLine(UpcomingBookings(2, 1)))
        assertEquals("Your 3 upcoming bookings are cancelled, and the offices are told.", upcomingLine(UpcomingBookings(3, 2)))
    }
}
