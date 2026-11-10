package ph.edu.isatu.visitor.ui

/*
 * The rules behind the sign-up and password forms, apart from the screens so they can be tested.
 */

internal data class PasswordRule(val label: String, val met: Boolean)

/** The rules the server checks first, as a list that ticks off while the password is typed. */
internal fun passwordRules(password: String, short: Boolean = false): List<PasswordRule> = listOf(
    PasswordRule(if (short) "10+ characters" else "At least 10 characters", password.length >= 10),
    PasswordRule("A letter", password.any { it.isLetter() }),
    PasswordRule("A number", password.any { it.isDigit() }),
)

internal fun passwordReady(password: String): Boolean = passwordRules(password).all { it.met }
