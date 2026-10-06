package com.romavb23.grandmahealth

import java.net.URI

internal object UploadPolicy {
    enum class Result { ACKNOWLEDGED, RETRY, BLOCKED, AUTH_ERROR }

    fun endpoint(value: String): String {
        val uri = try { URI(value.trim()) } catch (_: Exception) {
            throw IllegalArgumentException("Неверный адрес сервера")
        }
        require(uri.host != null && uri.userInfo == null && uri.query == null && uri.fragment == null &&
            (uri.path.isNullOrEmpty() || uri.path == "/") &&
            (uri.port == -1 || uri.port in 1..65535)) {
            "Введите адрес без пути, логина и параметров"
        }
        require(uri.scheme == "https" || (uri.scheme == "http" && privateIpv4(uri.host))) {
            "HTTP разрешён только для локального IP: например http://192.168.1.10:47863"
        }
        return uri.toString().trimEnd('/')
    }

    private fun privateIpv4(host: String): Boolean {
        val parts = host.split('.')
        if (parts.size != 4 || parts.any { it.isEmpty() || it.any { ch -> ch !in '0'..'9' } }) return false
        val octets = parts.map { it.toIntOrNull() ?: return false }
        if (octets.any { it !in 0..255 }) return false
        return octets[0] == 10 || (octets[0] == 192 && octets[1] == 168) ||
            (octets[0] == 172 && octets[1] in 16..31)
    }

    fun response(code: Int, expectedId: String, returnedId: String?, serverTime: Long): Result = when {
        code in listOf(200, 201) && returnedId == expectedId && serverTime > 0 -> Result.ACKNOWLEDGED
        code == 401 || code == 403 -> Result.AUTH_ERROR
        code == 409 || code == 422 -> Result.BLOCKED
        else -> Result.RETRY
    }
}
