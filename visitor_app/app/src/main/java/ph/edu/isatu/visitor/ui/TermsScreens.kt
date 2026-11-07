package ph.edu.isatu.visitor.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.LinkAnnotation
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLinkStyles
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.withLink
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

private val BlankBackground = Color(0xFFFFF0B3)
private val BlankInk = Color(0xFF6B4A00)

/** The notice to read, from sign-up or Profile. */
@Composable
fun TermsNoticeScreen(backLabel: String, onBack: () -> Unit) {
    BackHandler(onBack = onBack)
    DetailScaffold(TermsNotice.TITLE, onBack) { modifier ->
        NoticeList(modifier.fillMaxSize()) {
            item {
                OutlinedButton(
                    onClick = onBack,
                    modifier = Modifier.fillMaxWidth().padding(top = 18.dp).height(52.dp),
                ) { Text(backLabel) }
            }
        }
    }
}

/**
 * For an account that hasn't agreed to this version of the notice, once, before the app opens.
 * Someone who doesn't agree can sign out.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TermsAgreementScreen(busy: Boolean, onAgree: () -> Unit, onSignOut: () -> Unit) {
    var termsAgreed by rememberSaveable { mutableStateOf(false) }
    var locationAgreed by rememberSaveable { mutableStateOf(false) }
    Scaffold(
        containerColor = AppBackground,
        topBar = {
            TopAppBar(
                title = { Text(TermsNotice.TITLE, fontWeight = FontWeight.SemiBold, color = IsatuBlueDark, maxLines = 1) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White),
            )
        },
        bottomBar = {
            Surface(color = Color.White, shadowElevation = 10.dp) {
                Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 18.dp, vertical = 10.dp)) {
                    NoticeChecks(termsAgreed, { termsAgreed = it }, locationAgreed, { locationAgreed = it })
                    Spacer(Modifier.height(6.dp))
                    PrimaryButton(
                        "Agree and continue",
                        onAgree,
                        Modifier.fillMaxWidth(),
                        enabled = !busy && termsAgreed && locationAgreed,
                    )
                    TextButton(
                        onClick = onSignOut,
                        enabled = !busy,
                        modifier = Modifier.align(Alignment.CenterHorizontally),
                    ) { Text("Sign out") }
                }
            }
        },
    ) { padding ->
        NoticeList(
            Modifier.fillMaxSize().padding(padding),
            before = {
                item {
                    Text(
                        "ISATU Visitor now has written terms and a privacy notice. Please read them and agree to keep using the app.",
                        style = MaterialTheme.typography.bodyMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = Ink,
                        modifier = Modifier.padding(bottom = 12.dp),
                    )
                }
            },
        )
    }
}

/**
 * The two things a visitor agrees to. Location has a box of its own, so that agreement is clear
 * and specific. With [onOpenNotice], the notice's name opens it.
 */
@Composable
internal fun NoticeChecks(
    termsAgreed: Boolean,
    onTermsChange: (Boolean) -> Unit,
    locationAgreed: Boolean,
    onLocationChange: (Boolean) -> Unit,
    onOpenNotice: (() -> Unit)? = null,
) {
    val agreement = buildAnnotatedString {
        append("I have read and agree to the ")
        if (onOpenNotice != null) {
            withLink(
                LinkAnnotation.Clickable(
                    tag = "notice",
                    styles = TextLinkStyles(
                        SpanStyle(color = IsatuBlue, fontWeight = FontWeight.SemiBold, textDecoration = TextDecoration.Underline),
                    ),
                    linkInteractionListener = { onOpenNotice() },
                ),
            ) { append(TermsNotice.TITLE) }
        } else {
            append(TermsNotice.TITLE)
        }
        append(".")
    }
    Column {
        NoticeCheck(termsAgreed, onTermsChange, agreement)
        NoticeCheck(locationAgreed, onLocationChange, AnnotatedString(TermsNotice.LOCATION_AGREEMENT))
    }
}

/** Only the notice: a student is never tracked, so there is no location agreement to make. */
@Composable
internal fun TermsCheck(termsAgreed: Boolean, onTermsChange: (Boolean) -> Unit, onOpenNotice: () -> Unit) {
    val agreement = buildAnnotatedString {
        append("I have read and agree to the ")
        withLink(
            LinkAnnotation.Clickable(
                tag = "notice",
                styles = TextLinkStyles(
                    SpanStyle(color = IsatuBlue, fontWeight = FontWeight.SemiBold, textDecoration = TextDecoration.Underline),
                ),
                linkInteractionListener = { onOpenNotice() },
            ),
        ) { append(TermsNotice.TITLE) }
        append(".")
    }
    NoticeCheck(termsAgreed, onTermsChange, agreement)
}

@Composable
private fun NoticeCheck(checked: Boolean, onCheckedChange: (Boolean) -> Unit, text: AnnotatedString) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .toggleable(value = checked, role = Role.Checkbox, onValueChange = onCheckedChange)
            .padding(vertical = 7.dp),
        verticalAlignment = Alignment.Top,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        // The whole row is the control, so tapping the words works as well as the box.
        Checkbox(
            checked = checked,
            onCheckedChange = null,
            modifier = Modifier.size(24.dp),
            colors = CheckboxDefaults.colors(checkedColor = IsatuBlue),
        )
        Text(text, style = MaterialTheme.typography.bodySmall.copy(fontSize = 13.sp, lineHeight = 19.sp), color = Ink)
    }
}

@Composable
private fun NoticeList(
    modifier: Modifier = Modifier,
    before: LazyListScope.() -> Unit = {},
    after: LazyListScope.() -> Unit = {},
) {
    LazyColumn(
        modifier = modifier,
        contentPadding = PaddingValues(start = 18.dp, end = 18.dp, top = 14.dp, bottom = 22.dp),
    ) {
        before()
        items(TermsNotice.BLOCKS) { NoticeBlockView(it) }
        after()
    }
}

@Composable
internal fun NoticeBlockView(block: NoticeBlock) {
    val body = MaterialTheme.typography.bodyMedium.copy(lineHeight = 21.sp, color = Ink)
    when (block) {
        is NoticeBlock.Fine -> Text(
            noticeText(block.text),
            style = MaterialTheme.typography.bodySmall,
            color = MutedInk,
            modifier = Modifier.padding(bottom = 12.dp),
        )

        is NoticeBlock.Summary -> Column(
            Modifier
                .fillMaxWidth()
                .background(BlueSurface, RoundedCornerShape(16.dp))
                .padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text(block.title, style = MaterialTheme.typography.titleMedium, color = IsatuBlueDark)
            block.points.forEach { NoticePoint(it) }
        }

        is NoticeBlock.Part -> Column {
            HorizontalDivider(color = BorderSoft, modifier = Modifier.padding(top = 22.dp, bottom = 16.dp))
            Text(
                block.title,
                style = MaterialTheme.typography.titleLarge,
                color = IsatuBlueDark,
                modifier = Modifier.padding(bottom = 4.dp),
            )
        }

        is NoticeBlock.Heading -> Text(
            block.title,
            style = MaterialTheme.typography.titleMedium.copy(fontSize = 15.sp),
            color = Ink,
            modifier = Modifier.padding(top = 14.dp, bottom = 4.dp),
        )

        is NoticeBlock.Paragraph -> Text(noticeText(block.text), style = body, modifier = Modifier.padding(bottom = 6.dp))
        is NoticeBlock.Point -> NoticePoint(block.text, Modifier.padding(bottom = 6.dp))
    }
}

@Composable
private fun NoticePoint(text: String, modifier: Modifier = Modifier) {
    val body = MaterialTheme.typography.bodyMedium.copy(lineHeight = 21.sp, color = Ink)
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("•", style = body)
        Text(noticeText(text), style = body)
    }
}

private fun noticeText(line: String): AnnotatedString = buildAnnotatedString {
    noticeSpans(line).forEach { span ->
        when (span.style) {
            NoticeStyle.PLAIN -> append(span.text)
            NoticeStyle.BOLD -> withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) { append(span.text) }
            NoticeStyle.BLANK -> withStyle(
                SpanStyle(background = BlankBackground, color = BlankInk, fontWeight = FontWeight.SemiBold),
            ) { append(span.text) }
        }
    }
}
