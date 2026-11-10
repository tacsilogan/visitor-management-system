package ph.edu.isatu.visitor

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import ph.edu.isatu.visitor.ui.VisitorDraft
import ph.edu.isatu.visitor.ui.passwordReady
import ph.edu.isatu.visitor.ui.passwordRules

/** The rules behind the sign-up and password screens. */
class SignupRulesTest {
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
    fun aVisitorNeedsTheOneAgreement() {
        val draft = VisitorDraft().apply {
            name = "Maria Santos"
            email = "maria.santos@gmail.com"
            password = "Campus-Visit-2026"
        }
        assertFalse(draft.ready)
        draft.agreed = true
        assertTrue(draft.ready)
    }

    @Test
    fun anyEmailCanMakeAVisitorAccount() {
        // Visitors are the only people who sign up, so a school email signs up as a visitor too.
        val draft = VisitorDraft().apply {
            name = "Mika Dela Cruz"
            email = "mika.cruz@students.isatu.edu.ph"
            password = "Campus-Visit-2026"
            agreed = true
        }
        assertTrue(draft.ready)
    }

    @Test
    fun aVisitorNeedsANameAndAGoodPassword() {
        val draft = VisitorDraft().apply {
            name = "M"
            email = "maria.santos@gmail.com"
            password = "Campus-Visit-2026"
            agreed = true
        }
        assertFalse("a name has at least 2 letters", draft.ready)
        draft.name = "Maria Santos"
        draft.password = "short1"
        assertFalse("the password rules must all be met", draft.ready)
        draft.password = "Campus-Visit-2026"
        assertTrue(draft.ready)
    }
}
