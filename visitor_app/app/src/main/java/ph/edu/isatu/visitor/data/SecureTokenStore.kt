package ph.edu.isatu.visitor.data

import android.content.Context
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.nio.charset.StandardCharsets
import java.security.KeyStore
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class SecureTokenStore(context: Context) {
    private val preferences = context.getSharedPreferences("secure_session", Context.MODE_PRIVATE)
    private val keyAlias = "isatu_visitor_api_token"
    private val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    private val _signedIn = MutableStateFlow(readToken() != null)
    val signedIn = _signedIn.asStateFlow()

    /** The visitor's name, to greet them before the network answers. Goes with the token. */
    var savedName: String?
        get() = preferences.getString("name", null)
        set(value) = preferences.edit().putString("name", value).apply()

    @Synchronized
    fun saveToken(token: String) {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
        val encrypted = cipher.doFinal(token.toByteArray(StandardCharsets.UTF_8))
        preferences.edit()
            .putString("token", Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .putString("iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .apply()
        _signedIn.value = true
    }

    @Synchronized
    fun readToken(): String? {
        val encoded = preferences.getString("token", null) ?: return null
        val encodedIv = preferences.getString("iv", null) ?: return null
        return runCatching {
            val key = keyStore.getKey(keyAlias, null) as? SecretKey ?: return null
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(
                Cipher.DECRYPT_MODE,
                key,
                GCMParameterSpec(128, Base64.decode(encodedIv, Base64.NO_WRAP)),
            )
            String(cipher.doFinal(Base64.decode(encoded, Base64.NO_WRAP)), StandardCharsets.UTF_8)
        }.getOrElse {
            preferences.edit().clear().apply()
            null
        }
    }

    @Synchronized
    fun clear() {
        preferences.edit().clear().apply()
        _signedIn.value = false
    }

    private fun getOrCreateKey(): SecretKey {
        (keyStore.getKey(keyAlias, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").run {
            init(
                KeyGenParameterSpec.Builder(
                    keyAlias,
                    KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
                )
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .build(),
            )
            generateKey()
        }
    }
}

class InstallationStore(context: Context) {
    private val preferences = context.getSharedPreferences("visitor_installation", Context.MODE_PRIVATE)

    val installationId: String
        get() = preferences.getString("installation_id", null) ?: UUID.randomUUID().toString().also {
            preferences.edit().putString("installation_id", it).apply()
        }

    val deviceName: String
        get() = listOf(Build.MANUFACTURER, Build.MODEL)
            .filter { it.isNotBlank() }
            .joinToString(" ")
            .ifBlank { "Android phone" }
            .take(100)

    /** The notice version each account agreed to on this phone. It outlives signing out. */
    fun agreedNotice(userId: Long): String? = preferences.getString("agreed_notice_$userId", null)

    fun saveAgreedNotice(userId: Long, version: String) {
        preferences.edit().putString("agreed_notice_$userId", version).apply()
    }

    fun saveActiveTracking(appointmentId: Long, sessionId: Long) {
        preferences.edit()
            .putLong("tracking_appointment_id", appointmentId)
            .putLong("tracking_session_id", sessionId)
            .apply()
    }

    fun clearActiveTracking() {
        preferences.edit()
            .remove("tracking_appointment_id")
            .remove("tracking_session_id")
            .apply()
    }

    fun activeAppointmentId(): Long? = preferences.getLong("tracking_appointment_id", 0L).takeIf { it > 0 }
    fun activeSessionId(): Long? = preferences.getLong("tracking_session_id", 0L).takeIf { it > 0 }
}
