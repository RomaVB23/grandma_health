package com.romavb23.grandmahealth

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

internal class UploadSettings(context: Context) {
    val preferences = context.getSharedPreferences("server_upload", Context.MODE_PRIVATE)

    val endpoint: String get() = preferences.getString("endpoint", "") ?: ""
    val enabled: Boolean get() = preferences.getBoolean("enabled", false)

    fun token(): String {
        val encrypted = preferences.getString("token", null) ?: return ""
        val iv = preferences.getString("token_iv", null) ?: return ""
        return Cipher.getInstance("AES/GCM/NoPadding").run {
            init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)))
            String(doFinal(Base64.decode(encrypted, Base64.NO_WRAP)), Charsets.UTF_8)
        }
    }

    fun save(address: String, enteredToken: String) {
        val normalized = UploadPolicy.endpoint(address)
        val token = enteredToken.trim().ifEmpty { token() }
        require(token.length in 32..512 && token.none { it.isWhitespace() || it.isISOControl() }) {
            "Нужен TELEMETRY_TOKEN из server/.env (не менее 32 символов)"
        }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val encrypted = cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        check(preferences.edit().putString("endpoint", normalized)
            .putString("token", Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .putString("token_iv", Base64.encodeToString(cipher.iv, Base64.NO_WRAP)).commit()) {
            "Не удалось сохранить настройки"
        }
    }

    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (store.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").run {
            init(KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
            generateKey()
        }
    }

    companion object { private const val KEY_ALIAS = "grandma_health_server_token" }
}
