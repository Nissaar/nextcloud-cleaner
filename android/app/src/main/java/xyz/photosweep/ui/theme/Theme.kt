package xyz.photosweep.ui.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext

/** Nextcloud blue, so the app looks like it belongs to the server it talks to. */
private val NextcloudBlue = Color(0xFF0082C9)
private val NextcloudBlueLight = Color(0xFF6FB3E0)

private val LightColours = lightColorScheme(
    primary = NextcloudBlue,
    onPrimary = Color.White,
    secondary = Color(0xFF00679E),
    error = Color(0xFFBA1A1A),
)

private val DarkColours = darkColorScheme(
    primary = NextcloudBlueLight,
    onPrimary = Color(0xFF00304D),
    secondary = Color(0xFF9CC9E8),
    error = Color(0xFFFFB4AB),
)

/** Green has no slot in a Material scheme, but "keep" needs to read as clearly good. */
val KeepGreen = Color(0xFF2E7D32)
val KeepGreenDark = Color(0xFF81C784)

@Composable
fun keepColour(): Color = if (isSystemInDarkTheme()) KeepGreenDark else KeepGreen

@Composable
fun PhotoSweepTheme(content: @Composable () -> Unit) {
    val dark = isSystemInDarkTheme()
    val context = LocalContext.current

    // Material You where the device offers it: a photo app sitting inside the user's
    // own wallpaper palette looks less like a visitor.
    val colours = when {
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ->
            if (dark) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context)
        dark -> DarkColours
        else -> LightColours
    }

    MaterialTheme(colorScheme = colours, content = content)
}
