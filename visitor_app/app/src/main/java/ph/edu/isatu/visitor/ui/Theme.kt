package ph.edu.isatu.visitor.ui

import android.graphics.Color as AndroidColor
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.compose.material3.Shapes
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp
import ph.edu.isatu.visitor.R

val IsatuBlue = Color(0xFF0757B9)
val IsatuBlueDark = Color(0xFF003B82)
val IsatuYellow = Color(0xFFFFCB2F)
val IsatuGreen = Color(0xFF15803D)
val IsatuOrange = Color(0xFFF97316)
val AppBackground = Color(0xFFF4F7FC)
val Ink = Color(0xFF172033)
val MutedInk = Color(0xFF64748B)
val BorderSoft = Color(0xFFDCE3ED)
val BlueSurface = Color(0xFFEAF2FF)
val YellowSurface = Color(0xFFFFF7D6)
/** Small words on ISATU navy. */
val OnNavyMuted = Color(0xFFC9D8F2)

private val LightColors = lightColorScheme(
    primary = IsatuBlue,
    onPrimary = Color.White,
    primaryContainer = BlueSurface,
    onPrimaryContainer = IsatuBlueDark,
    secondary = IsatuYellow,
    onSecondary = Ink,
    tertiary = IsatuGreen,
    background = AppBackground,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Color(0xFFEDF2F8),
    onSurfaceVariant = MutedInk,
    error = Color(0xFFB42318),
)

private val Poppins = FontFamily(
    Font(R.font.poppins_regular, FontWeight.Normal),
    Font(R.font.poppins_semibold, FontWeight.SemiBold),
    Font(R.font.poppins_bold, FontWeight.Bold),
)

private val BaseTypography = Typography()
private val AppTypography = Typography(
    displayLarge = BaseTypography.displayLarge.copy(fontFamily = Poppins),
    displayMedium = BaseTypography.displayMedium.copy(fontFamily = Poppins),
    displaySmall = BaseTypography.displaySmall.copy(fontFamily = Poppins),
    headlineLarge = BaseTypography.headlineLarge.copy(fontFamily = Poppins, fontWeight = FontWeight.Bold, fontSize = 30.sp),
    headlineMedium = BaseTypography.headlineMedium.copy(fontFamily = Poppins, fontWeight = FontWeight.Bold, fontSize = 24.sp),
    headlineSmall = BaseTypography.headlineSmall.copy(fontFamily = Poppins),
    titleLarge = BaseTypography.titleLarge.copy(fontFamily = Poppins, fontWeight = FontWeight.SemiBold, fontSize = 20.sp),
    titleMedium = BaseTypography.titleMedium.copy(fontFamily = Poppins, fontWeight = FontWeight.SemiBold, fontSize = 16.sp),
    titleSmall = BaseTypography.titleSmall.copy(fontFamily = Poppins),
    bodyLarge = BaseTypography.bodyLarge.copy(fontFamily = Poppins, fontSize = 16.sp),
    bodyMedium = BaseTypography.bodyMedium.copy(fontFamily = Poppins, fontSize = 14.sp),
    bodySmall = BaseTypography.bodySmall.copy(fontFamily = Poppins),
    labelLarge = BaseTypography.labelLarge.copy(fontFamily = Poppins, fontWeight = FontWeight.SemiBold, fontSize = 14.sp),
    labelMedium = BaseTypography.labelMedium.copy(fontFamily = Poppins),
    labelSmall = BaseTypography.labelSmall.copy(fontFamily = Poppins),
)

private val AppShapes = Shapes(
    extraSmall = RoundedCornerShape(8.dp),
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

/** How many screens on show put ISATU navy behind the navigation bar ([NavyUnderNavigationBar]). */
private var navyNavigationBars by mutableIntStateOf(0)

/** Says this screen has navy at the very bottom, so the navigation bar's icons turn white while it shows. */
@Composable
fun NavyUnderNavigationBar() {
    DisposableEffect(Unit) {
        navyNavigationBars++
        onDispose { navyNavigationBars-- }
    }
}

@Composable
fun ISATUVisitorTheme(barsOverBlue: Boolean = false, content: @Composable () -> Unit) {
    // The visitor portal intentionally keeps the approved white/blue institutional
    // appearance even when the device uses a dark system theme.
    val colors = LightColors
    val view = LocalView.current
    val navyBottom = barsOverBlue || navyNavigationBars > 0
    if (!view.isInEditMode) {
        SideEffect {
            val activity = view.context as ComponentActivity
            // Every screen starts in ISATU navy (or the opening animation's blue), so the status bar
            // icons are white. The navigation bar's icons are dark over the white screens below.
            activity.enableEdgeToEdge(
                statusBarStyle = SystemBarStyle.dark(AndroidColor.TRANSPARENT),
                navigationBarStyle = if (navyBottom) {
                    SystemBarStyle.dark(AndroidColor.TRANSPARENT)
                } else {
                    SystemBarStyle.light(AndroidColor.TRANSPARENT, AndroidColor.TRANSPARENT)
                },
            )
        }
    }
    MaterialTheme(
        colorScheme = colors,
        typography = AppTypography,
        shapes = AppShapes,
        content = content,
    )
}
