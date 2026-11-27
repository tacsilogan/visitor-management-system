package ph.edu.isatu.visitor.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ph.edu.isatu.visitor.data.PersonDto
import ph.edu.isatu.visitor.data.PersonRequest

/*
 * The people who come with a visitor (GROUP_VISITS.md). The visitor adds each one by the name on
 * their ID and says whether they are 18 or older; at the gate the guard checks each adult's ID.
 * Only the visitor needs the app, and only the visitor's phone is tracked.
 */

/** Up to this many people with one visitor (the server's VISIT_PEOPLE_MAX). */
const val MAX_PEOPLE_WITH_VISITOR = 10

private val SheetScrim = Color(0x800F172A)
private val KidSurface = YellowSurface
private val KidInk = Color(0xFF7A5B00)

fun peopleCountText(count: Int): String = if (count == 1) "1 person" else "$count people"

/** "Elena Dela Cruz and Miguel Dela Cruz (under 18)" */
fun peopleNamesText(people: List<Pair<String, Boolean>>): String {
    val names = people.map { (name, adult) -> if (adult) name else "$name (under 18)" }
    return when (names.size) {
        0 -> ""
        1 -> names[0]
        else -> names.dropLast(1).joinToString(", ") + " and " + names.last()
    }
}

/** A person's name, cleaned the way the server cleans it. */
private fun cleanName(value: String): String = value.trim().replace(Regex("\\s+"), " ")

private fun validName(value: String): Boolean = cleanName(value).let { it.length >= 2 && it.any(Char::isLetter) }

/** Booking: "Anyone coming with you?", under the visit type. */
@Composable
fun PeopleWithYouCard(people: List<PersonRequest>, onAdd: () -> Unit, onRemove: (Int) -> Unit) {
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(14.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text("Anyone coming with you?", fontWeight = FontWeight.SemiBold, fontSize = 15.sp, color = Ink)
                Text(
                    "Add each person by the name on their ID. Only you need the app.",
                    color = MutedInk,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            if (people.isNotEmpty()) {
                PeopleList(people, onRemove)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                AddPersonButton(enabled = people.size < MAX_PEOPLE_WITH_VISITOR, onClick = onAdd)
                Spacer(Modifier.weight(1f))
                Text(
                    if (people.isEmpty()) "Up to $MAX_PEOPLE_WITH_VISITOR" else "${people.size} of $MAX_PEOPLE_WITH_VISITOR",
                    color = MutedInk,
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            if (people.isNotEmpty()) {
                KeepNamesNote()
            }
        }
    }
}

@Composable
private fun AddPersonButton(enabled: Boolean, onClick: () -> Unit) {
    OutlinedButton(onClick = onClick, enabled = enabled, shape = RoundedCornerShape(22.dp), modifier = Modifier.height(44.dp)) {
        Icon(Icons.Rounded.Add, contentDescription = null, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(6.dp))
        Text("Add a person", fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun KeepNamesNote() {
    Row(
        Modifier.fillMaxWidth().background(BlueSurface, RoundedCornerShape(10.dp)).padding(horizontal = 12.dp, vertical = 10.dp),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(Icons.Rounded.Info, contentDescription = null, tint = IsatuBlueDark, modifier = Modifier.size(18.dp))
        Text(
            "ISATU keeps their names with your visit for campus safety. Let them know.",
            color = IsatuBlueDark,
            style = MaterialTheme.typography.bodySmall,
        )
    }
}

/** One row per person: initials, name, age, and ✕ to remove. */
@Composable
private fun PeopleList(people: List<PersonRequest>, onRemove: (Int) -> Unit) {
    Column(Modifier.fillMaxWidth().border(1.dp, BorderSoft, RoundedCornerShape(12.dp))) {
        people.forEachIndexed { index, person ->
            if (index > 0) HorizontalDivider(color = BorderSoft)
            Row(
                Modifier.fillMaxWidth().padding(start = 12.dp, top = 8.dp, bottom = 8.dp, end = 2.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Initials(person.fullName, person.isAdult)
                Column(Modifier.weight(1f)) {
                    Text(person.fullName, fontWeight = FontWeight.SemiBold, color = Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(
                        if (person.isAdult) "18 or older · shows an ID at the gate" else "Under 18 · no ID needed",
                        color = MutedInk,
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                IconButton(onClick = { onRemove(index) }) {
                    Icon(Icons.Rounded.Close, contentDescription = "Remove ${person.fullName}", tint = MutedInk)
                }
            }
        }
    }
}

@Composable
private fun Initials(name: String, adult: Boolean) {
    val parts = name.trim().split(Regex("\\s+")).filter(String::isNotBlank)
    val letters = (parts.getOrNull(0)?.take(1).orEmpty() + parts.getOrNull(1)?.take(1).orEmpty()).uppercase()
    Box(
        Modifier.size(36.dp).background(if (adult) BlueSurface else KidSurface, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Text(letters, color = if (adult) IsatuBlueDark else KidInk, fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
    }
}

/** The two questions: the name as on their ID, and the age. */
@Composable
private fun AddPersonForm(onAdd: (PersonRequest) -> Unit, onCancel: () -> Unit) {
    var name by rememberSaveable { mutableStateOf("") }
    var adult by rememberSaveable { mutableStateOf(true) }
    val valid = validName(name)
    val add = { if (valid) onAdd(PersonRequest(cleanName(name), adult)) }
    OutlinedTextField(
        value = name,
        onValueChange = { name = it.take(150) },
        label = { Text("Full name, as on their ID") },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
        shape = RoundedCornerShape(10.dp),
        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words, imeAction = ImeAction.Done),
        keyboardActions = KeyboardActions(onDone = { add() }),
    )
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Age", fontWeight = FontWeight.SemiBold, color = Ink)
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            AgeChoice("18 or older", adult, Modifier.weight(1f)) { adult = true }
            AgeChoice("Under 18", !adult, Modifier.weight(1f)) { adult = false }
        }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
        Icon(Icons.Rounded.Badge, contentDescription = null, tint = IsatuBlue, modifier = Modifier.size(18.dp))
        Text(
            if (adult) "They show a valid ID with this name at the gate." else "Children don't need an ID. They come in with you.",
            color = MutedInk,
            style = MaterialTheme.typography.bodySmall,
        )
    }
    PrimaryButton(text = "Add", onClick = add, modifier = Modifier.fillMaxWidth(), enabled = valid)
    TextButton(onClick = onCancel, modifier = Modifier.fillMaxWidth()) { Text("Cancel", fontWeight = FontWeight.SemiBold) }
}

@Composable
private fun AgeChoice(text: String, selected: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    OutlinedButton(
        onClick = onClick,
        modifier = modifier.height(52.dp),
        shape = RoundedCornerShape(10.dp),
        border = BorderStroke(1.dp, if (selected) IsatuBlue else BorderSoft),
        contentPadding = PaddingValues(horizontal = 10.dp),
    ) {
        RadioButton(selected = selected, onClick = null)
        Text(text, color = if (selected) IsatuBlueDark else Ink, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** A sheet from the bottom, over the whole screen, like Delete my account. */
@Composable
private fun BoxScope.BottomSheetFrame(title: String, onDismiss: () -> Unit, content: @Composable () -> Unit) {
    BackHandler(onBack = onDismiss)
    Box(
        Modifier.matchParentSize().background(SheetScrim)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, role = Role.Button, onClickLabel = "Close", onClick = onDismiss),
    )
    Surface(
        modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding(),
        shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp),
        color = Color.White,
    ) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).navigationBarsPadding()
                .padding(start = 20.dp, end = 20.dp, top = 10.dp, bottom = 12.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Box(Modifier.align(Alignment.CenterHorizontally).size(width = 34.dp, height = 4.dp).background(Color(0xFFC4C9D2), RoundedCornerShape(2.dp)))
            Text(title, style = MaterialTheme.typography.titleLarge, color = IsatuBlueDark)
            content()
        }
    }
}

/** Booking: add one person. */
@Composable
internal fun BoxScope.AddPersonSheet(onAdd: (PersonRequest) -> Unit, onDismiss: () -> Unit) {
    BottomSheetFrame("Add a person", onDismiss) {
        AddPersonForm(onAdd = onAdd, onCancel = onDismiss)
    }
}

/**
 * Change who's coming, until the guard checks the visitor in. Starts from the people still
 * expected; Save sends the whole new list, and the office sees it.
 */
@Composable
internal fun BoxScope.ChangePeopleSheet(
    initial: List<PersonDto>,
    saving: Boolean,
    error: String?,
    onSave: (List<PersonRequest>) -> Unit,
    onDismiss: () -> Unit,
) {
    val draft = remember(initial) {
        mutableStateListOf<PersonRequest>().apply {
            addAll(initial.filter { it.gateStatus == "expected" }.map { PersonRequest(it.fullName, it.isAdult) })
        }
    }
    var adding by remember { mutableStateOf(false) }
    BottomSheetFrame(if (adding) "Add a person" else "Who's coming with you?", onDismiss = { if (adding) adding = false else onDismiss() }) {
        if (adding) {
            AddPersonForm(onAdd = { draft.add(it); adding = false }, onCancel = { adding = false })
        } else {
            Text("The office sees the changes. Only you need the app.", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
            if (draft.isEmpty()) {
                Text("You're coming alone.", color = Ink, fontWeight = FontWeight.SemiBold)
            } else {
                PeopleList(draft, onRemove = { draft.removeAt(it) })
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                AddPersonButton(enabled = draft.size < MAX_PEOPLE_WITH_VISITOR, onClick = { adding = true })
                Spacer(Modifier.weight(1f))
                Text("${draft.size} of $MAX_PEOPLE_WITH_VISITOR", color = MutedInk, style = MaterialTheme.typography.bodySmall)
            }
            if (draft.isNotEmpty()) {
                KeepNamesNote()
            }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }
            PrimaryButton(
                text = if (saving) "Saving…" else "Save",
                onClick = { onSave(draft.toList()) },
                modifier = Modifier.fillMaxWidth(),
                enabled = !saving,
            )
            TextButton(onClick = onDismiss, enabled = !saving, modifier = Modifier.fillMaxWidth()) {
                Text("Cancel", fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/** The people still coming with the visitor, or who came in with them and haven't left. */
fun peopleWithVisitor(people: List<PersonDto>): List<PersonDto> =
    people.filter { it.gateStatus == "expected" || (it.gateStatus == "in" && it.leftAt == null) }

/** On the pass, under the visitor's name: who comes in with this pass, and Change while it can. */
@Composable
fun PassPeopleBox(people: List<PersonDto>, onChange: (() -> Unit)?) {
    val withYou = peopleWithVisitor(people)
    if (withYou.isEmpty() && onChange == null) return
    Column(
        Modifier.fillMaxWidth().background(BlueSurface, RoundedCornerShape(14.dp)).padding(start = 14.dp, end = 6.dp, top = 4.dp, bottom = 10.dp),
        verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Groups, contentDescription = null, tint = IsatuBlueDark, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(
                if (withYou.isEmpty()) "Only you" else "With you · ${withYou.size}",
                color = IsatuBlueDark,
                fontWeight = FontWeight.SemiBold,
                fontSize = 13.sp,
                modifier = Modifier.weight(1f),
            )
            if (onChange != null) {
                TextButton(onClick = onChange) { Text(if (withYou.isEmpty()) "Add people" else "Change", fontWeight = FontWeight.SemiBold) }
            } else {
                Spacer(Modifier.height(40.dp))
            }
        }
        withYou.forEach { person ->
            Text(
                buildAnnotatedString {
                    append(person.fullName)
                    if (!person.isAdult) {
                        withStyle(SpanStyle(color = MutedInk, fontWeight = FontWeight.Normal)) { append(" · under 18") }
                    }
                },
                color = Ink,
                fontWeight = FontWeight.SemiBold,
                fontSize = 14.sp,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

/** Visit details: everyone on the list, and what happened at the gate. */
@Composable
fun PeopleDetailsCard(people: List<PersonDto>, editable: Boolean, onChange: () -> Unit) {
    if (people.isEmpty() && !editable) return
    Card(
        colors = CardDefaults.cardColors(containerColor = Color.White),
        border = BorderStroke(1.dp, BorderSoft),
        shape = RoundedCornerShape(16.dp),
    ) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Groups, contentDescription = null, tint = IsatuBlue)
                Spacer(Modifier.width(8.dp))
                Text(
                    if (people.isEmpty()) "People with you" else "People with you · ${people.size}",
                    style = MaterialTheme.typography.titleMedium,
                    color = Ink,
                )
            }
            if (people.isEmpty()) {
                Text("You're coming alone. Anyone coming with you? Add them by the name on their ID.", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
            }
            people.forEach { person ->
                val status = when {
                    person.gateStatus == "absent" -> "didn't come"
                    person.gateStatus == "refused" -> "not let in at the gate"
                    person.leftAt != null -> "left early"
                    person.gateStatus == "in" -> "came in"
                    else -> ""
                }
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Initials(person.fullName, person.isAdult)
                    Column(Modifier.weight(1f)) {
                        Text(person.fullName, fontWeight = FontWeight.SemiBold, color = Ink)
                        Text(
                            listOf(if (person.isAdult) "18 or older" else "Under 18", status).filter(String::isNotBlank).joinToString(" · "),
                            color = MutedInk,
                            style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
            }
            if (editable) {
                OutlinedButton(onClick = onChange, modifier = Modifier.fillMaxWidth().height(46.dp), shape = RoundedCornerShape(12.dp)) {
                    Text(if (people.isEmpty()) "Add people" else "Change who's coming", fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}
