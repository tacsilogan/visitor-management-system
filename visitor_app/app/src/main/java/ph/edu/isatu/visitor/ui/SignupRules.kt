package ph.edu.isatu.visitor.ui

/*
 * The rules behind the sign-up forms, apart from the screens so they can be tested.
 */

/** An ISATU Student ID is 8 digits and a letter, printed as 2023-4363-I (SCHOOL_ACCOUNTS.md). */
internal const val STUDENT_ID_DIGITS = 8
internal const val STUDENT_ID_LENGTH = 9

/**
 * What the Student ID boxes hold, from whatever was typed or pasted: up to 8 digits, then one
 * capital letter. Dashes and spaces are dropped, because the boxes draw them, and a character that
 * doesn't fit where it would go is ignored. So "2023-4363-i" and "20234363I" both give "20234363I".
 */
internal fun studentIdChars(typed: String): String {
    val chars = StringBuilder()
    for (c in typed.uppercase()) {
        if (chars.length == STUDENT_ID_LENGTH) break
        val wantsDigit = chars.length < STUDENT_ID_DIGITS
        if ((wantsDigit && c in '0'..'9') || (!wantsDigit && c in 'A'..'Z')) chars.append(c)
    }
    return chars.toString()
}

internal fun isCompleteStudentId(chars: String): Boolean = Regex("""\d{8}[A-Z]""").matches(chars)

/** "2023-4363-I" from the boxes' "20234363I". A part of an ID gets the dashes it has reached. */
internal fun formatStudentId(chars: String): String = buildString {
    chars.forEachIndexed { index, c ->
        if (index == 4 || index == 8) append('-')
        append(c)
    }
}

internal data class PasswordRule(val label: String, val met: Boolean)

/** The rules the server checks first, as a list that ticks off while the password is typed. */
internal fun passwordRules(password: String, short: Boolean = false): List<PasswordRule> = listOf(
    PasswordRule(if (short) "10+ characters" else "At least 10 characters", password.length >= 10),
    PasswordRule("A letter", password.any { it.isLetter() }),
    PasswordRule("A number", password.any { it.isDigit() }),
)

internal fun passwordReady(password: String): Boolean = passwordRules(password).all { it.met }
