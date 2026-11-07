package ph.edu.isatu.visitor

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import ph.edu.isatu.visitor.ui.STUDENT_STEPS
import ph.edu.isatu.visitor.ui.StudentDraft
import ph.edu.isatu.visitor.ui.VisitorDraft
import ph.edu.isatu.visitor.ui.formatStudentId
import ph.edu.isatu.visitor.ui.isCompleteStudentId
import ph.edu.isatu.visitor.ui.passwordReady
import ph.edu.isatu.visitor.ui.passwordRules
import ph.edu.isatu.visitor.ui.studentIdChars

/** The rules behind the 0.9.0 sign-up screens: the Student ID boxes, passwords, and the student's steps. */
class SignupRulesTest {
    @Test
    fun theBoxesTakeEightDigitsThenALetter() {
        assertEquals("20234363I", studentIdChars("2023-4363-I"))
        assertEquals("20234363I", studentIdChars("20234363i"))
        assertEquals("20234363I", studentIdChars(" 2023 4363 I "))
        assertEquals("2023", studentIdChars("2023"))
    }

    @Test
    fun aCharacterThatDoesNotFitItsBoxIsIgnored() {
        // A letter where a digit goes, a digit where the letter goes, and anything after the ninth box.
        assertEquals("2023", studentIdChars("I2023"))
        assertEquals("20234363", studentIdChars("2023-4363-1"))
        assertEquals("20234363I", studentIdChars("2023-4363-IA"))
        assertEquals("20230000", studentIdChars("2023-00001"))
        assertEquals("", studentIdChars("ñ-é"))
    }

    @Test
    fun onlyAFullIdIsComplete() {
        assertTrue(isCompleteStudentId("20234363I"))
        assertFalse(isCompleteStudentId("20234363"))
        assertFalse(isCompleteStudentId("2023-4363-I"))
        assertFalse(isCompleteStudentId(""))
    }

    @Test
    fun theDashesAreAddedAsTheIdIsTyped() {
        assertEquals("2023-4363-I", formatStudentId("20234363I"))
        assertEquals("2023-43", formatStudentId("202343"))
        assertEquals("2023", formatStudentId("2023"))
        assertEquals("", formatStudentId(""))
    }

    @Test
    fun thePasswordRulesTickOffOneByOne() {
        assertEquals(listOf(false, false, false), passwordRules("").map { it.met })
        assertEquals(listOf(false, true, false), passwordRules("abc").map { it.met })
        assertEquals(listOf(true, true, false), passwordRules("abcdefghij").map { it.met })
        assertEquals(listOf(true, true, true), passwordRules("Notebook-4410").map { it.met })
        assertTrue(passwordReady("Notebook-4410"))
        assertFalse(passwordReady("1234567890"))
        assertEquals("10+ characters", passwordRules("", short = true).first().label)
    }

    @Test
    fun eachStudentStepNeedsItsOwnFields() {
        val draft = StudentDraft()
        assertFalse(draft.stepReady(1))
        draft.name = "Mika Dela Cruz"
        draft.studentId = "20234363"
        assertFalse("the ID still needs its letter", draft.stepReady(1))
        draft.studentId = "20234363I"
        assertTrue(draft.stepReady(1))

        assertFalse(draft.stepReady(2))
        draft.courseCode = "IT"
        draft.year = 3
        assertFalse("a section is needed", draft.stepReady(2))
        draft.section = "B"
        assertTrue(draft.stepReady(2))

        draft.email = "mika@gmail.com"
        draft.password = "Notebook-4410"
        assertFalse("only a school email", draft.stepReady(3))
        draft.email = "mika.cruz@students.isatu.edu.ph"
        assertTrue(draft.stepReady(3))

        assertFalse("the summary needs the agreement", draft.stepReady(STUDENT_STEPS))
        draft.agreed = true
        assertTrue(draft.stepReady(STUDENT_STEPS))
    }

    @Test
    fun aCourseThatIsNotListedIsTypedIn() {
        val draft = StudentDraft().apply {
            year = 1
            section = "A"
            courseNotListed = true
        }
        assertFalse(draft.stepReady(2))
        draft.typedCourse = "BSEd Math"
        assertTrue(draft.stepReady(2))
    }

    @Test
    fun aVisitorNeedsTheOneAgreementAndNotAStudentEmail() {
        val draft = VisitorDraft().apply {
            name = "Maria Santos"
            email = "maria.santos@gmail.com"
            password = "Campus-Visit-2026"
        }
        assertFalse(draft.ready)
        draft.agreed = true
        assertTrue(draft.ready)
        draft.email = "maria@students.isatu.edu.ph"
        assertFalse("a student signs up as a student", draft.ready)
    }
}
