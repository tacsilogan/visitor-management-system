package ph.edu.isatu.visitor.ui

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class TermsNoticeTest {
    private val everyLine = TermsNotice.BLOCKS.flatMap(NoticeBlock::texts)
    private val readable = everyLine.flatMap(::noticeSpans).joinToString(" ") { it.text }

    @Test
    fun plainTextStaysPlain() {
        assertEquals(listOf(NoticeSpan("Just words.", NoticeStyle.PLAIN)), noticeSpans("Just words."))
    }

    @Test
    fun boldAndBlanksAreFoundInALine() {
        assertEquals(
            listOf(
                NoticeSpan("Account:", NoticeStyle.BOLD),
                NoticeSpan(" your name, until ", NoticeStyle.PLAIN),
                NoticeSpan("[date]", NoticeStyle.BLANK),
                NoticeSpan(".", NoticeStyle.PLAIN),
            ),
            noticeSpans("**Account:** your name, until [[date]]."),
        )
    }

    @Test
    fun noMarkupReachesTheVisitor() {
        everyLine.flatMap(::noticeSpans).forEach { span ->
            assertFalse("stray bold mark in: ${span.text}", span.text.contains("**"))
            assertFalse("stray blank mark in: ${span.text}", span.text.contains("[[") || span.text.contains("]]"))
        }
    }

    @Test
    fun everyBlankIsFilledIn() {
        // Since Version 4, ISATU's answers are in place of the highlighted blanks.
        assertEquals(emptyList<String>(), TermsNotice.blanks)
        listOf(
            "Version 6, effective ${TermsNotice.EFFECTIVE_DATE}",
            "If you are under 18, a parent or guardian must agree for you.",
            "mail@isatu.edu.ph",
        ).forEach { assertTrue("missing: $it", readable.contains(it)) }
    }

    @Test
    fun versionSixCoversThePeopleWithAVisitor() {
        listOf(
            // visit_people: a name and an 18-or-older flag for each, kept with the visit (GROUP_VISITS.md).
            "the people you add to your visit",
            "each adult with you shows one too",
            "tell them first that ISATU keeps their names with your visit",
            "Add someone under 18 only if their parent or guardian agrees.",
            "whether each is 18 or older",
            "(never its number)",
            // Only the visitor's phone shares a position.
            "The people with you are not tracked.",
            "Visits (with the names of the people with you)",
        ).forEach { assertTrue("missing: $it", readable.contains(it)) }
        assertEquals(3, TermsNotice.WHAT_CHANGED.size)
        TermsNotice.WHAT_CHANGED.forEach { (change, part) ->
            assertTrue("empty change", change.isNotBlank())
            assertTrue("no part named for: $change", part.isNotBlank())
        }
    }

    @Test
    fun versionFiveIsStillThere() {
        listOf(
            // Profile → Delete my account (account_delete.php).
            "delete your account there at any time",
            "deleted right away when you delete it in Profile",
            "bookings that haven't happened yet are cancelled",
            "no longer linked to you",
            // Monitoring is Security's alone (permissions.php).
            "Administrators:",
            "They can't see your position on a map.",
        ).forEach { assertTrue("missing: $it", readable.contains(it)) }
        assertEquals("I am 18 or older, or my parent or guardian agrees for me.", TermsNotice.AGE_PART)
    }

    @Test
    fun itSaysWhatThePromisesAreBuiltOn() {
        listOf(
            "Stop sharing my location",
            "30 minutes",
            "15 seconds",
            "25 meters",
            "90 days",
            // record_retention_service.php deletes what this says.
            "1 year after the visit or activity",
            "after 1 year without use",
            "Republic Act 10173",
            "National Privacy Commission",
            "Railway",
            "Brevo",
            "OpenFreeMap",
            "Firebase",
        ).forEach { assertTrue("missing: $it", readable.contains(it)) }
    }

    @Test
    fun theSummaryComesFirstAndBothPartsAreThere() {
        assertTrue(TermsNotice.BLOCKS[1] is NoticeBlock.Summary)
        assertEquals(
            listOf("Terms of use", "Privacy notice"),
            TermsNotice.BLOCKS.filterIsInstance<NoticeBlock.Part>().map { it.title },
        )
    }
}
