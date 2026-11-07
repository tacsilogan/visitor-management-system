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
    fun theBlanksForIsatuAreStillThere() {
        // When ISATU answers, its words replace these and this list gets shorter.
        assertEquals(
            listOf(
                "[date]",
                "[If you are under 18, a parent or guardian must agree for you. ISATU to confirm.]",
                "[period for ISATU to set]",
                "[Email and phone: ISATU to fill in]",
            ),
            TermsNotice.blanks,
        )
    }

    @Test
    fun itSaysWhatThePromisesAreBuiltOn() {
        listOf(
            "Stop sharing my location",
            "30 minutes",
            "15 seconds",
            "25 meters",
            "90 days",
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
