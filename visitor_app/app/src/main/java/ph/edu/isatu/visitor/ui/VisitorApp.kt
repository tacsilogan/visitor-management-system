package ph.edu.isatu.visitor.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.lifecycle.compose.collectAsStateWithLifecycle

@Composable
fun VisitorApp(viewModel: AppViewModel) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    when {
        state.initializing -> Box(
            modifier = Modifier.fillMaxSize(),
            contentAlignment = Alignment.Center,
        ) { LoadingBlock("Preparing your visitor account…") }

        !state.signedIn -> AuthFlow(state, viewModel)
        state.noticeAgreementNeeded -> TermsAgreementScreen(
            busy = state.busy,
            onAgree = viewModel::agreeToNotice,
            onSignOut = viewModel::logout,
        )
        else -> MainShell(state, viewModel)
    }
}

