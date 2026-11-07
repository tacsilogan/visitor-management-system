package ph.edu.isatu.visitor

import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.ViewModelProvider
import ph.edu.isatu.visitor.ui.AppViewModel
import ph.edu.isatu.visitor.ui.ISATUVisitorTheme
import ph.edu.isatu.visitor.ui.LocalSealHome
import ph.edu.isatu.visitor.ui.OpeningAnimation
import ph.edu.isatu.visitor.ui.SealHome
import ph.edu.isatu.visitor.ui.VisitorApp

class MainActivity : ComponentActivity() {
    private lateinit var viewModel: AppViewModel

    override fun onCreate(savedInstanceState: Bundle?) {
        // The manifest's blue theme is only for the moment before the app draws.
        setTheme(R.style.Theme_ISATUVisitor)
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            // Android's own splash screen is plain blue: the animation takes over without a fade.
            splashScreen.setOnExitAnimationListener { it.remove() }
        }
        viewModel = ViewModelProvider(this, AppViewModel.Factory(application))[AppViewModel::class.java]
        // Someone opening a notification wants what it points to, without the wait.
        val openedFromIcon = intent.idExtra("visit_id") <= 0 && intent.idExtra("appointment_id") <= 0
        setContent {
            var opening by rememberSaveable { mutableStateOf(openedFromIcon) }
            var barsOverBlue by remember { mutableStateOf(opening) }
            val sealHome = remember { SealHome(away = opening) }
            ISATUVisitorTheme(barsOverBlue) {
                CompositionLocalProvider(LocalSealHome provides sealHome) {
                    Box(Modifier.fillMaxSize()) {
                        VisitorApp(viewModel)
                        if (opening) {
                            OpeningAnimation(
                                greeting = viewModel::openingGreeting,
                                onReveal = { barsOverBlue = false },
                                onDone = { opening = false },
                            )
                        }
                    }
                }
            }
        }
        openAppointmentFrom(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        openAppointmentFrom(intent)
    }

    /**
     * Opens the visit or appointment a notification refers to. Notifications shown by the
     * app itself carry a Long extra; notifications Android shows from a background push
     * carry the FCM data values as String extras, so both forms are accepted.
     */
    private fun openAppointmentFrom(intent: Intent?) {
        val visitId = intent.idExtra("visit_id")
        val appointmentId = intent.idExtra("appointment_id")
        when {
            visitId > 0 -> viewModel.openVisit(visitId)
            appointmentId > 0 -> viewModel.openAppointment(appointmentId)
        }
    }

    private fun Intent?.idExtra(name: String): Long {
        val extras = this?.extras ?: return 0L
        return extras.getString(name)?.toLongOrNull() ?: extras.getLong(name, 0L)
    }
}

