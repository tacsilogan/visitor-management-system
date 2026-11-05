buildscript {
    dependencies {
        // AGP 9 ships Kotlin 2.2.10. This explicit classpath keeps the Compose
        // compiler and Kotlin compilation on that same supported version.
        classpath("org.jetbrains.kotlin:kotlin-gradle-plugin:2.2.10")
    }
}

plugins {
    id("com.android.application") version "9.4.0" apply false
    id("org.jetbrains.kotlin.plugin.compose") version "2.2.10" apply false
    id("com.google.devtools.ksp") version "2.3.10" apply false
    id("com.google.gms.google-services") version "4.5.0" apply false
    // Draws the app's screens as pictures on the laptop (app/src/screenshotTest).
    id("com.android.compose.screenshot") version "0.0.1-alpha16" apply false
}
