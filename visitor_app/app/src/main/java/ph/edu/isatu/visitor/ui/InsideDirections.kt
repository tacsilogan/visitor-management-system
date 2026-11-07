package ph.edu.isatu.visitor.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.VolumeOff
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.Apartment
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import ph.edu.isatu.visitor.VisitorApplication
import ph.edu.isatu.visitor.navigation.OfficeDirections
import ph.edu.isatu.visitor.navigation.VoiceGuide

/*
 * The part of a visit GPS can't lead (INDOOR_DIRECTIONS.md): the question and description near
 * the building, one step at a time inside it, "I can't find it", and the way back out.
 */

/** What the office heard when the visitor asked for help. */
enum class HelpResult { TOLD, NO_STAFF, FAILED }

/** A step's photo from the phone, or from the server the first time. Null while it loads or if it can't. */
@Composable
private fun rememberDirectionPhoto(path: String): ImageBitmap? {
    val application = LocalContext.current.applicationContext as? VisitorApplication
    val photo by produceState<ImageBitmap?>(null, path) {
        value = null
        if (path.isNotBlank()) value = application?.directionPhotos?.load(path)?.asImageBitmap()
    }
    return photo
}

/** The card near the building: the description, and the question only the visitor can answer. */
@Composable
internal fun NearBuildingCard(
    directions: OfficeDirections,
    onYes: () -> Unit,
    onNotYet: () -> Unit,
    onCantFind: (() -> Unit)?,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp),
        color = Color.White,
        shadowElevation = 14.dp,
    ) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).navigationBarsPadding()
                .padding(start = 18.dp, end = 18.dp, top = 18.dp, bottom = 10.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            PlaceHeading(directions)
            if (directions.message.isNotBlank()) DescriptionBox(directions.message)
            Text(
                directions.question,
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                PrimaryButton("Yes, I'm here", onYes, Modifier.fillMaxWidth())
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                    TextButton(onClick = onNotYet) { Text("Not yet", fontWeight = FontWeight.SemiBold) }
                    onCantFind?.let { TextButton(onClick = it) { Text("I can't find it", fontWeight = FontWeight.SemiBold) } }
                }
            }
        }
    }
}

@Composable
private fun PlaceHeading(directions: OfficeDirections) {
    Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.size(44.dp).background(BlueSurface, RoundedCornerShape(12.dp)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Apartment, contentDescription = null, tint = IsatuBlue)
        }
        Column(Modifier.weight(1f)) {
            Text(directions.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            directions.subtitle.takeIf { it.isNotBlank() }?.let {
                Text(it, style = MaterialTheme.typography.bodyMedium, color = MutedInk)
            }
        }
    }
}

@Composable
private fun DescriptionBox(message: String) {
    Text(
        message,
        style = MaterialTheme.typography.bodyMedium,
        color = Color(0xFF4A3B0B),
        modifier = Modifier.fillMaxWidth().background(YellowSurface, RoundedCornerShape(14.dp))
            .padding(horizontal = 14.dp, vertical = 12.dp),
    )
}

/**
 * One step inside the building: a photo, one sentence, and Next. The visitor moves on when they
 * are ready, because a phone can't be followed accurately indoors.
 *
 * With [onHelp] and [onArrived] null, the steps are only being read ahead of time (before the
 * guard's check-in), so nothing here can report anything.
 */
@Composable
internal fun InsideStepScreen(
    directions: OfficeDirections,
    step: Int,
    voiceOn: Boolean,
    photo: ImageBitmap?,
    onToggleVoice: () -> Unit,
    onHear: () -> Unit,
    onStep: (Int) -> Unit,
    onClose: () -> Unit,
    onHelp: (() -> Unit)?,
    onArrived: (() -> Unit)?,
) {
    val current = directions.steps.getOrNull(step) ?: return
    val last = step == directions.steps.lastIndex
    Column(Modifier.fillMaxSize().background(Color.White).navigationBarsPadding()) {
        InsideTopBar("To ${directions.office}", voiceOn, onToggleVoice, onClose)
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(start = 18.dp, end = 18.dp, top = 4.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            StepProgress(step, directions.steps.size)
            if (current.photo.isNotBlank()) StepPhoto(photo)
            Text(current.text, fontSize = 22.sp, lineHeight = 30.sp, fontWeight = FontWeight.SemiBold, color = Ink)
            HearAgain(onHear)
        }
        Column(
            Modifier.fillMaxWidth().padding(start = 18.dp, end = 18.dp, top = 6.dp, bottom = 12.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            if (last && onArrived != null) {
                Text(
                    directions.arrivalQuestion,
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            if (onHelp != null || (onArrived != null && !last)) {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                    onHelp?.let { TextButton(onClick = it) { Text("I can't find it", fontWeight = FontWeight.SemiBold) } } ?: Spacer(Modifier.width(1.dp))
                    if (onArrived != null && !last) {
                        TextButton(onClick = onArrived) { Text("I'm already there", color = MutedInk) }
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedButton(
                    onClick = { if (step == 0) onClose() else onStep(step - 1) },
                    modifier = Modifier.width(96.dp).height(52.dp),
                    shape = RoundedCornerShape(12.dp),
                    colors = ButtonDefaults.outlinedButtonColors(contentColor = IsatuBlue),
                ) { Text("Back", fontWeight = FontWeight.SemiBold) }
                when {
                    !last -> PrimaryButton("Next", { onStep(step + 1) }, Modifier.weight(1f))
                    onArrived != null -> Button(
                        onClick = onArrived,
                        modifier = Modifier.weight(1f).height(52.dp),
                        shape = RoundedCornerShape(12.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = IsatuGreen),
                    ) {
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(8.dp))
                        Text("Yes, I'm here", fontWeight = FontWeight.SemiBold)
                    }
                    else -> PrimaryButton("Done", onClose, Modifier.weight(1f))
                }
            }
        }
    }
}

@Composable
private fun InsideTopBar(title: String, voiceOn: Boolean, onToggleVoice: () -> Unit, onBack: () -> Unit) {
    // Navy under the status bar too, like every other screen's title bar.
    Column(Modifier.fillMaxWidth().background(IsatuBlueDark)) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().height(60.dp).padding(start = 4.dp, end = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back", tint = Color.White) }
            Text(
                title,
                style = MaterialTheme.typography.titleLarge,
                color = Color.White,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f).padding(start = 4.dp),
            )
            IconButton(onClick = onToggleVoice) {
                Icon(
                    if (voiceOn) Icons.AutoMirrored.Rounded.VolumeUp else Icons.AutoMirrored.Rounded.VolumeOff,
                    contentDescription = if (voiceOn) "Turn voice directions off" else "Turn voice directions on",
                    tint = if (voiceOn) IsatuYellow else OnNavyMuted,
                )
            }
        }
        GoldEdge()
    }
}

@Composable
private fun StepProgress(step: Int, total: Int) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Step ${step + 1} of $total", style = MaterialTheme.typography.labelLarge, color = MutedInk)
        Row(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            repeat(total) { index ->
                Box(
                    Modifier.weight(1f).height(5.dp).clip(RoundedCornerShape(3.dp))
                        .background(if (index <= step) IsatuBlue else BorderSoft),
                )
            }
        }
    }
}

/** Photos are 4:3, as the admin's preview shows them. While one loads, the space stays reserved. */
@Composable
private fun StepPhoto(photo: ImageBitmap?) {
    Box(
        Modifier.fillMaxWidth().aspectRatio(4f / 3f).clip(RoundedCornerShape(18.dp)).background(Color(0xFFEDF2F8)),
        contentAlignment = Alignment.Center,
    ) {
        if (photo != null) {
            Image(photo, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        } else {
            Icon(Icons.Rounded.Image, contentDescription = null, tint = Color(0xFFB8C4D3), modifier = Modifier.size(40.dp))
        }
    }
}

@Composable
private fun HearAgain(onHear: () -> Unit) {
    TextButton(onClick = onHear, modifier = Modifier.height(36.dp)) {
        Icon(Icons.AutoMirrored.Rounded.VolumeUp, contentDescription = null, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(6.dp))
        Text("Hear again", fontWeight = FontWeight.SemiBold)
    }
}

/** Comes out of the building before the map and voice start again: GPS can mislead indoors. */
@Composable
internal fun HeadingOutScreen(
    directions: OfficeDirections,
    voiceOn: Boolean,
    photo: ImageBitmap?,
    onToggleVoice: () -> Unit,
    onHear: () -> Unit,
    onBack: () -> Unit,
    onOutside: () -> Unit,
) {
    val hasPhoto = directions.steps.firstOrNull()?.photo?.isNotBlank() == true
    Column(Modifier.fillMaxSize().background(Color.White).navigationBarsPadding()) {
        InsideTopBar("Heading out", voiceOn, onToggleVoice, onBack)
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(start = 18.dp, end = 18.dp, top = 4.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (hasPhoto) StepPhoto(photo)
            Text(directions.leavingText, fontSize = 22.sp, lineHeight = 30.sp, fontWeight = FontWeight.SemiBold, color = Ink)
            HearAgain(onHear)
        }
        Column(
            Modifier.fillMaxWidth().padding(start = 18.dp, end = 18.dp, top = 6.dp, bottom = 12.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text(
                "Are you outside now?",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
            PrimaryButton("Yes, I'm outside", onOutside, Modifier.fillMaxWidth())
        }
    }
}

/** Where "I can't find it" stands: asking, sending, or what the office heard. */
internal sealed interface HelpPrompt {
    data object Asking : HelpPrompt
    data object Sending : HelpPrompt
    data class Answered(val result: HelpResult) : HelpPrompt
}

/** A sheet over the screen, so the visitor doesn't lose their place among the steps. */
@Composable
internal fun BoxScope.HelpSheet(
    directions: OfficeDirections,
    step: Int?,
    prompt: HelpPrompt,
    onAsk: () -> Unit,
    onDismiss: () -> Unit,
) {
    BackHandler(onBack = onDismiss)
    Box(
        Modifier.matchParentSize().background(Color(0x800F172A))
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, role = Role.Button, onClickLabel = "Close", onClick = onDismiss),
    )
    Surface(
        modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth(),
        shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp),
        color = Color.White,
    ) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(start = 20.dp, end = 20.dp, top = 22.dp, bottom = 12.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            when (prompt) {
                HelpPrompt.Asking -> HelpAsk(directions, step, onAsk, onDismiss)
                HelpPrompt.Sending -> {
                    Text("Can't find ${directions.office}?", style = MaterialTheme.typography.titleLarge)
                    Row(Modifier.fillMaxWidth().padding(vertical = 16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        CircularProgressIndicator(Modifier.size(22.dp), color = IsatuBlue, strokeWidth = 2.dp)
                        Text("Telling ${directions.office}...", color = MutedInk)
                    }
                }
                is HelpPrompt.Answered -> when (prompt.result) {
                    HelpResult.TOLD -> {
                        Box(Modifier.size(44.dp).background(Color(0xFFDCFCE7), RoundedCornerShape(12.dp)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = IsatuGreen)
                        }
                        Text("${directions.office} has been told", style = MaterialTheme.typography.titleLarge)
                        Text("Please stay where you are. Someone will come to meet you.", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
                        PrimaryButton(if (step != null) "Back to the steps" else "Done", onDismiss, Modifier.fillMaxWidth())
                    }
                    HelpResult.NO_STAFF -> {
                        Text("${directions.office} can't be reached from here", style = MaterialTheme.typography.titleLarge)
                        Text("Please ask a guard or someone nearby for directions.", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
                        PrimaryButton(if (step != null) "Back to the steps" else "Done", onDismiss, Modifier.fillMaxWidth())
                    }
                    HelpResult.FAILED -> {
                        Text("Couldn't reach ${directions.office}", style = MaterialTheme.typography.titleLarge)
                        Text("Check your connection and try again, or ask someone nearby.", color = MutedInk, style = MaterialTheme.typography.bodyMedium)
                        PrimaryButton("Try again", onAsk, Modifier.fillMaxWidth())
                        TextButton(onClick = onDismiss, modifier = Modifier.align(Alignment.CenterHorizontally)) {
                            Text("Keep trying on my own", fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun ColumnScope.HelpAsk(directions: OfficeDirections, step: Int?, onAsk: () -> Unit, onDismiss: () -> Unit) {
    Text("Can't find ${directions.office}?", style = MaterialTheme.typography.titleLarge)
    Text(
        if (step != null) {
            "We'll tell the office you stopped at step ${step + 1}, so someone can come to you."
        } else {
            "We'll tell the office, so someone can come to you."
        },
        color = MutedInk,
        style = MaterialTheme.typography.bodyMedium,
    )
    PrimaryButton("Ask the office for help", onAsk, Modifier.fillMaxWidth())
    TextButton(onClick = onDismiss, modifier = Modifier.align(Alignment.CenterHorizontally)) {
        Text("Keep trying", fontWeight = FontWeight.SemiBold)
    }
}

/**
 * "How to find it" before the visit: the same steps, or the description when there are none, read
 * at the visitor's own pace. Nothing here can report anything, since the guard hasn't checked them in.
 */
@Composable
internal fun DirectionsPreview(directions: OfficeDirections, onClose: () -> Unit) {
    val context = LocalContext.current
    val voice = remember { VoiceGuide(context) }
    DisposableEffect(voice) { onDispose { voice.shutdown() } }
    var step by rememberSaveable { mutableIntStateOf(0) }
    var voiceOn by rememberSaveable { mutableStateOf(false) }
    BackHandler(onBack = onClose)
    val line = if (directions.hasSteps) directions.steps.getOrNull(step)?.text.orEmpty() else directions.nearLine()
    LaunchedEffect(voiceOn, step) {
        if (voiceOn) voice.speak(line, urgent = true) else voice.silence()
    }
    if (directions.hasSteps) {
        val photo = rememberDirectionPhoto(directions.steps.getOrNull(step)?.photo.orEmpty())
        InsideStepScreen(
            directions = directions,
            step = step,
            voiceOn = voiceOn,
            photo = photo,
            onToggleVoice = { voiceOn = !voiceOn },
            onHear = { voice.speak(line, urgent = true) },
            onStep = { step = it },
            onClose = onClose,
            onHelp = null,
            onArrived = null,
        )
    } else {
        Column(Modifier.fillMaxSize().background(Color.White).navigationBarsPadding()) {
            InsideTopBar("To ${directions.office}", voiceOn, { voiceOn = !voiceOn }, onClose)
            Column(
                Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                PlaceHeading(directions)
                if (directions.message.isNotBlank()) DescriptionBox(directions.message)
                HearAgain { voice.speak(line, urgent = true) }
            }
            PrimaryButton("Done", onClose, Modifier.fillMaxWidth().padding(start = 18.dp, end = 18.dp, bottom = 12.dp))
        }
    }
}

/** A step inside the map screen, with its photo fetched for it. */
@Composable
internal fun InsideStepWithPhoto(
    directions: OfficeDirections,
    step: Int,
    voiceOn: Boolean,
    onToggleVoice: () -> Unit,
    onHear: () -> Unit,
    onStep: (Int) -> Unit,
    onClose: () -> Unit,
    onHelp: () -> Unit,
    onArrived: () -> Unit,
) {
    InsideStepScreen(
        directions = directions,
        step = step,
        voiceOn = voiceOn,
        photo = rememberDirectionPhoto(directions.steps.getOrNull(step)?.photo.orEmpty()),
        onToggleVoice = onToggleVoice,
        onHear = onHear,
        onStep = onStep,
        onClose = onClose,
        onHelp = onHelp,
        onArrived = onArrived,
    )
}

/** The way out of the building, with the entrance's photo when the first step has one. */
@Composable
internal fun HeadingOutWithPhoto(
    directions: OfficeDirections,
    voiceOn: Boolean,
    onToggleVoice: () -> Unit,
    onHear: () -> Unit,
    onBack: () -> Unit,
    onOutside: () -> Unit,
) {
    HeadingOutScreen(
        directions = directions,
        voiceOn = voiceOn,
        photo = rememberDirectionPhoto(directions.steps.firstOrNull()?.photo.orEmpty()),
        onToggleVoice = onToggleVoice,
        onHear = onHear,
        onBack = onBack,
        onOutside = onOutside,
    )
}
