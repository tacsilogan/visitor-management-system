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
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp
import androidx.core.view.WindowCompat
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

@Composable
fun ISATUVisitorTheme(barsOverBlue: Boolean = false, content: @Composable () -> Unit) {
    // The visitor portal intentionally keeps the approved white/blue institutional
    // appearance even when the device uses a dark system theme.
    val colors = LightColors
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val activity = view.context as ComponentActivity
            if (barsOverBlue) {
                // White icons, with nothing behind them, over the opening animation's blue.
                activity.enableEdgeToEdge(
                    statusBarStyle = SystemBarStyle.dark(AndroidColor.TRANSPARENT),
                    navigationBarStyle = SystemBarStyle.dark(AndroidColor.TRANSPARENT),
                )
            } else {
                activity.enableEdgeToEdge()
                activity.window.statusBarColor = colors.surface.toArgb()
                WindowCompat.getInsetsController(activity.window, view).isAppearanceLightStatusBars = true
            }
        }
    }
    MaterialTheme(
        colorScheme = colors,
        typography = AppTypography,
        shapes = AppShapes,
        content = content,
    )
}
