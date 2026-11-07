package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.requiredSize
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.layout.layout
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ph.edu.isatu.visitor.R

/*
 * The pieces every screen is built from since 0.9.0: ISATU navy at the top with a gold edge, as on
 * the seal; white below. Gold also marks progress and the selected tab.
 */

/** The gold line under ISATU navy. */
@Composable
fun GoldEdge(modifier: Modifier = Modifier, height: Dp = 3.dp) {
    Box(modifier.fillMaxWidth().height(height).background(IsatuYellow))
}

/** Pulls this up over whatever is above it by [overlap], and takes up that much less room. */
fun Modifier.liftedOver(overlap: Dp): Modifier = layout { measurable, constraints ->
    val placeable = measurable.measure(constraints)
    val shift = overlap.roundToPx()
    layout(placeable.width, (placeable.height - shift).coerceAtLeast(0)) {
        placeable.place(0, -shift)
    }
}

/**
 * The navy band at the top of Home and the sign-in screens: the seal faint at its right edge and a
 * gold edge below. [bottomSpace] is room for a card lifted over it ([liftedOver]).
 */
@Composable
fun NavyBand(
    modifier: Modifier = Modifier,
    bottomSpace: Dp = 60.dp,
    underStatusBar: Boolean = false,
    content: @Composable ColumnScope.() -> Unit,
) {
    Box(modifier.fillMaxWidth().clipToBounds().background(IsatuBlueDark)) {
        // Sized by the band's content: the seal only shows through, it never makes the band taller.
        Box(Modifier.matchParentSize()) {
            Image(
                painter = painterResource(R.drawable.isatu_seal),
                contentDescription = null,
                modifier = Modifier.align(Alignment.TopEnd).offset(x = 58.dp, y = (-20).dp).requiredSize(200.dp).alpha(0.08f),
            )
        }
        Column(
            Modifier
                .fillMaxWidth()
                .then(if (underStatusBar) Modifier.statusBarsPadding() else Modifier)
                .padding(start = 20.dp, end = 20.dp, top = 14.dp, bottom = bottomSpace),
            content = content,
        )
        GoldEdge(Modifier.align(Alignment.BottomCenter), height = 4.dp)
    }
}

/** The title bar of every screen but Home: navy, white words, gold edge. It runs under the status bar. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NavyTopBar(
    title: @Composable () -> Unit,
    onBack: (() -> Unit)? = null,
    actions: @Composable RowScope.() -> Unit = {},
    goldEdge: Boolean = true,
) {
    Column(Modifier.fillMaxWidth()) {
        TopAppBar(
            title = title,
            navigationIcon = {
                if (onBack != null) {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back")
                    }
                }
            },
            actions = actions,
            colors = TopAppBarDefaults.topAppBarColors(
                containerColor = IsatuBlueDark,
                titleContentColor = Color.White,
                navigationIconContentColor = Color.White,
                actionIconContentColor = Color.White,
            ),
        )
        if (goldEdge) GoldEdge()
    }
}

/** "Step 2 of 4", with a gold bar for each step reached. */
@Composable
fun FormStepProgress(step: Int, total: Int, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            repeat(total) { index ->
                Box(
                    Modifier.weight(1f).height(6.dp).clip(RoundedCornerShape(3.dp))
                        .background(if (index < step) IsatuYellow else Color(0xFFE3E8EF)),
                )
            }
        }
        Text(
            "Step $step of $total",
            style = MaterialTheme.typography.labelMedium,
            fontWeight = FontWeight.SemiBold,
            color = MutedInk,
        )
    }
}

/** A screen's own title inside the page, in ISATU navy. */
@Composable
fun StepHeading(title: String, subtitle: String? = null, center: Boolean = false) {
    Column(
        Modifier.fillMaxWidth(),
        horizontalAlignment = if (center) Alignment.CenterHorizontally else Alignment.Start,
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(
            title,
            modifier = Modifier.semantics { heading() },
            style = MaterialTheme.typography.headlineMedium.copy(fontSize = 22.sp, lineHeight = 28.sp),
            color = IsatuBlueDark,
            textAlign = if (center) TextAlign.Center else TextAlign.Start,
        )
        subtitle?.let {
            Text(
                it,
                style = MaterialTheme.typography.bodyMedium,
                color = MutedInk,
                textAlign = if (center) TextAlign.Center else TextAlign.Start,
            )
        }
    }
}

/** One box per character, in [groups] with a dash between them, like the code on a card. */
@Composable
private fun CharBoxes(chars: String, groups: List<Int>, focused: Boolean, wideLast: Boolean, boxHeight: Dp, fontSize: TextUnit) {
    val starts = groups.runningFold(0) { sum, count -> sum + count }
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
        groups.forEachIndexed { group, count ->
            if (group > 0) {
                Text("–", color = MutedInk, fontWeight = FontWeight.Bold)
            }
            repeat(count) { inGroup ->
                val index = starts[group] + inGroup
                val char = chars.getOrNull(index)
                val current = focused && index == chars.length
                val shape = RoundedCornerShape(10.dp)
                Box(
                    Modifier
                        .weight(if (wideLast && group == groups.lastIndex) 1.3f else 1f)
                        .height(boxHeight)
                        .clip(shape)
                        .background(if (char != null) Color(0xFFF5F9FF) else Color.White)
                        .border(
                            if (current) 2.dp else 1.5.dp,
                            when {
                                current -> IsatuBlue
                                char != null -> Color(0xFFAFC3E0)
                                else -> BorderSoft
                            },
                            shape,
                        ),
                    contentAlignment = Alignment.Center,
                ) {
                    if (char != null) Text(char.toString(), fontSize = fontSize, fontWeight = FontWeight.SemiBold, color = Ink)
                }
            }
        }
    }
}

/**
 * The Student ID as boxes: 4 digits, a dash, 4 digits, a dash, a letter. [chars] is what the boxes
 * hold ("20234363I"); the dashes are drawn, never typed. The keyboard shows numbers, then letters for
 * the last box.
 */
@Composable
fun StudentIdField(chars: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, onDone: () -> Unit = {}) {
    val letterTime = chars.length >= STUDENT_ID_DIGITS
    val focusRequester = remember { FocusRequester() }
    var focused by remember { mutableStateOf(false) }
    var typing by remember { mutableStateOf(false) }
    // A keyboard can't change from numbers to letters inside one input session, so the field is made
    // again, and focused again, when the last box is reached or left.
    key(letterTime) {
        BasicTextField(
            value = TextFieldValue(chars, TextRange(chars.length)),
            onValueChange = {
                typing = true
                onChange(studentIdChars(it.text))
            },
            modifier = modifier
                .fillMaxWidth()
                .focusRequester(focusRequester)
                .onFocusChanged { focused = it.isFocused }
                .semantics { contentDescription = "Student ID, like 2023-4363-I" },
            singleLine = true,
            keyboardOptions = KeyboardOptions(
                capitalization = KeyboardCapitalization.Characters,
                autoCorrectEnabled = false,
                keyboardType = if (letterTime) KeyboardType.Text else KeyboardType.Number,
                imeAction = ImeAction.Done,
            ),
            keyboardActions = KeyboardActions(onDone = { onDone() }),
            cursorBrush = SolidColor(Color.Transparent),
            decorationBox = { CharBoxes(chars, listOf(4, 4, 1), focused, wideLast = true, boxHeight = 54.dp, fontSize = 20.sp) },
        )
    }
    LaunchedEffect(letterTime) {
        if (typing) runCatching { focusRequester.requestFocus() }
    }
}

/** The 6-digit code from the email, as boxes. */
@Composable
fun CodeField(code: String, onChange: (String) -> Unit, modifier: Modifier = Modifier, autoFocus: Boolean = true) {
    val focusRequester = remember { FocusRequester() }
    var focused by remember { mutableStateOf(false) }
    BasicTextField(
        value = TextFieldValue(code, TextRange(code.length)),
        onValueChange = { onChange(it.text.filter(Char::isDigit).take(6)) },
        modifier = modifier
            .fillMaxWidth()
            .focusRequester(focusRequester)
            .onFocusChanged { focused = it.isFocused }
            .semantics { contentDescription = "6-digit code" },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword, imeAction = ImeAction.Done),
        cursorBrush = SolidColor(Color.Transparent),
        decorationBox = { CharBoxes(code, listOf(6), focused, wideLast = false, boxHeight = 58.dp, fontSize = 24.sp) },
    )
    LaunchedEffect(Unit) {
        if (autoFocus) runCatching { focusRequester.requestFocus() }
    }
}

/** A short set of choices as pills; the chosen one is ISATU navy. */
@OptIn(ExperimentalLayoutApi::class, ExperimentalMaterial3Api::class)
@Composable
fun ChoiceChips(options: List<Pair<String, String>>, selected: String?, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    FlowRow(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { (value, label) ->
            val on = value == selected
            Surface(
                selected = on,
                onClick = { onSelect(value) },
                shape = RoundedCornerShape(50),
                color = if (on) IsatuBlueDark else Color.White,
                contentColor = if (on) Color.White else Ink,
                border = if (on) null else BorderStroke(1.5.dp, BorderSoft),
            ) {
                Text(label, modifier = Modifier.padding(horizontal = 15.dp, vertical = 9.dp), style = MaterialTheme.typography.labelLarge)
            }
        }
    }
}

/** The password rules, ticking off while the password is typed. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun PasswordChecks(password: String, compact: Boolean = false) {
    val rules = passwordRules(password, short = compact)
    val rule: @Composable (PasswordRule) -> Unit = { item ->
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(
                if (item.met) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked,
                contentDescription = if (item.met) "Done" else "Not yet",
                tint = if (item.met) IsatuGreen else Color(0xFFB4BFCC),
                modifier = Modifier.size(17.dp),
            )
            Text(item.label, style = MaterialTheme.typography.bodySmall, color = if (item.met) IsatuGreen else MutedInk)
        }
    }
    if (compact) {
        FlowRow(Modifier.padding(start = 2.dp), horizontalArrangement = Arrangement.spacedBy(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            rules.forEach { rule(it) }
        }
    } else {
        Column(Modifier.padding(start = 2.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            rules.forEach { rule(it) }
        }
    }
}

/** A part of the summary before sending: what was entered, and Edit to go back to it. */
@Composable
fun ReviewSection(title: String, rows: List<Pair<String, String>>, onEdit: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Column(Modifier.fillMaxWidth().clip(shape).border(1.dp, BorderSoft, shape)) {
        Row(
            Modifier.fillMaxWidth().background(AppBackground).padding(start = 14.dp, end = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(title, style = MaterialTheme.typography.labelLarge, color = IsatuBlueDark, modifier = Modifier.weight(1f))
            TextButton(onClick = onEdit, modifier = Modifier.semantics { contentDescription = "Edit ${title.lowercase()}" }) {
                Text("Edit")
            }
        }
        Column(Modifier.padding(horizontal = 14.dp, vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            rows.forEach { (label, value) ->
                // A long value, like a school email, goes under its label instead of beside it.
                if (value.length > 22) {
                    Column {
                        Text(label, style = MaterialTheme.typography.bodySmall, color = MutedInk)
                        Text(value, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = Ink)
                    }
                } else {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(label, style = MaterialTheme.typography.bodyMedium, color = MutedInk, modifier = Modifier.weight(1f))
                        Text(value, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold, color = Ink, textAlign = TextAlign.End)
                    }
                }
            }
        }
    }
}
