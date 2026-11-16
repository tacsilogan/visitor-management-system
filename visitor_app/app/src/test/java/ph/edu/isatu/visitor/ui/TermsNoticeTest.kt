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
            "Version 5, effective November 17, 2026",
            "If you are under 18, a parent or guardian must agree for you.",
            "mail@isatu.edu.ph",
        ).forEach { assertTrue("missing: $it", readable.contains(it)) }
    }

    @Test
    fun versionFiveSaysWhatChanged() {
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
