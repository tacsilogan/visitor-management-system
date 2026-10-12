import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
    id("com.google.devtools.ksp")
}

if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

val localProperties = Properties().apply {
    val propertiesFile = rootProject.file("local.properties")
    if (propertiesFile.exists()) propertiesFile.inputStream().use(::load)
}
val configuredApiUrl = providers.gradleProperty("ISATU_API_BASE_URL").orNull
    ?: localProperties.getProperty("ISATU_API_BASE_URL")
    ?: "http://10.0.2.2/visitor-management-system/phone_tracker/api/v1/"
val normalizedApiUrl = configuredApiUrl.trimEnd('/') + "/"
// Map tiles for the visitor's campus map. OpenFreeMap needs no key; an institution can
// point this at its own MapLibre style (for example MapTiler) in local.properties.
val configuredMapStyleUrl = providers.gradleProperty("ISATU_MAP_STYLE_URL").orNull
    ?: localProperties.getProperty("ISATU_MAP_STYLE_URL")
    ?: "https://tiles.openfreemap.org/styles/liberty"
// Release builds (the APK handed to visitors) talk to the hosted server, which must use
// HTTPS; debug builds keep ISATU_API_BASE_URL for testing on the laptop. See DEPLOYMENT.md.
val configuredReleaseApiUrl = providers.gradleProperty("ISATU_RELEASE_API_BASE_URL").orNull
    ?: localProperties.getProperty("ISATU_RELEASE_API_BASE_URL")
    ?: configuredApiUrl
val normalizedReleaseApiUrl = configuredReleaseApiUrl.trimEnd('/') + "/"
// The release signing key, described in visitor_app/keystore.properties (never committed).
// Every update must be signed with the same key or phones refuse to install it.
val keystoreProperties = Properties().apply {
    val propertiesFile = rootProject.file("keystore.properties")
    if (propertiesFile.exists()) propertiesFile.inputStream().use(::load)
}
val releaseSigningReady = listOf("storeFile", "storePassword", "keyAlias", "keyPassword")
    .all { !keystoreProperties.getProperty(it).isNullOrBlank() }

android {
    namespace = "ph.edu.isatu.visitor"
    compileSdk = 37

    defaultConfig {
        applicationId = "ph.edu.isatu.visitor"
        minSdk = 26
        targetSdk = 37
        versionCode = 6
        versionName = "0.5.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        buildConfigField("String", "API_BASE_URL", "\"$normalizedApiUrl\"")
        buildConfigField("String", "CONSENT_VERSION", "\"location-v1\"")
        buildConfigField("String", "MAP_STYLE_URL", "\"$configuredMapStyleUrl\"")

        ndk {
            // The map engine is native code: ARM phones (64- and 32-bit) and 64-bit
            // emulators. Leaving out 32-bit x86 keeps the APK about 13 MB smaller.
            abiFilters += listOf("arm64-v8a", "armeabi-v7a", "x86_64")
        }
    }

    signingConfigs {
        if (releaseSigningReady) {
            create("release") {
                storeFile = rootProject.file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        debug {
            versionNameSuffix = "-debug"
        }
        release {
            buildConfigField("String", "API_BASE_URL", "\"$normalizedReleaseApiUrl\"")
            if (releaseSigningReady) {
                signingConfig = signingConfigs.getByName("release")
            }
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

// A release APK that cannot reach the server, or that phones would refuse to install as an
// update, is stopped before anything is built.
val checkReleaseSettings by tasks.registering {
    val apiUrl = normalizedReleaseApiUrl
    val signingReady = releaseSigningReady
    doLast {
        if (!apiUrl.startsWith("https://")) {
            throw GradleException(
                "Release builds need the hosted server's https:// address. Set ISATU_RELEASE_API_BASE_URL " +
                    "in visitor_app/local.properties (currently $apiUrl). See DEPLOYMENT.md.",
            )
        }
        if (!signingReady) {
            throw GradleException(
                "Release builds need visitor_app/keystore.properties with storeFile, storePassword, keyAlias, " +
                    "and keyPassword. See DEPLOYMENT.md.",
            )
        }
    }
}
tasks.matching { it.name == "preReleaseBuild" }.configureEach { dependsOn(checkReleaseSettings) }

ksp {
    arg("room.schemaLocation", "$projectDir/schemas")
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2026.09.00")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    implementation("androidx.core:core-ktx:1.19.0")
    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.11.0")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.11.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.11.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    debugImplementation("androidx.compose.ui:ui-tooling")
    debugImplementation("androidx.compose.ui:ui-test-manifest")

    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
    implementation("com.squareup.retrofit2:retrofit:3.0.0")
    implementation("com.squareup.retrofit2:converter-gson:3.0.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")

    implementation("androidx.room:room-runtime:2.8.5")
    implementation("androidx.room:room-ktx:2.8.5")
    ksp("androidx.room:room-compiler:2.8.5")
    implementation("androidx.work:work-runtime-ktx:2.11.2")

    implementation("com.google.android.gms:play-services-location:21.3.0")
    implementation("org.maplibre.gl:android-sdk:13.6.1")
    implementation(platform("com.google.firebase:firebase-bom:34.19.0"))
    implementation("com.google.firebase:firebase-messaging")
    implementation("com.google.zxing:core:3.5.4")

    testImplementation("junit:junit:4.13.2")
    androidTestImplementation("androidx.test.ext:junit:1.3.0")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
}
