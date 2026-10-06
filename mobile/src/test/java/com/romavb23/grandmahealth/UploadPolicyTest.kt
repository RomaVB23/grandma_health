package com.romavb23.grandmahealth

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class UploadPolicyTest {
    @Test fun localEndpointIsNormalized() {
        assertEquals("http://192.168.1.12:47863", UploadPolicy.endpoint(" http://192.168.1.12:47863/ "))
        assertEquals("http://10.0.0.8:47863", UploadPolicy.endpoint("http://10.0.0.8:47863"))
        assertEquals("http://172.31.2.8", UploadPolicy.endpoint("http://172.31.2.8"))
        assertEquals("https://example.org", UploadPolicy.endpoint("https://example.org"))
    }

    @Test fun credentialsAreNotSentToPublicHttpOrAmbiguousAddresses() {
        listOf("http://example.org", "http://172.32.0.1", "http://192.168.1.999", "http://127.0.0.1",
            "http://user:secret@192.168.1.8", "http://192.168.1.8/api", "http://192.168.1.8?token=x",
            "http://192.168.1.8#fragment", "ftp://192.168.1.8", "http://192.168.1.8:70000").forEach {
            assertThrows(it, IllegalArgumentException::class.java) { UploadPolicy.endpoint(it) }
        }
    }

    @Test fun acknowledgementMustIdentifyTheOriginalPacket() {
        assertEquals(UploadPolicy.Result.ACKNOWLEDGED, UploadPolicy.response(201, "packet-a", "packet-a", 100))
        assertEquals(UploadPolicy.Result.ACKNOWLEDGED, UploadPolicy.response(200, "packet-a", "packet-a", 100))
        assertEquals(UploadPolicy.Result.RETRY, UploadPolicy.response(200, "packet-a", "packet-b", 100))
        assertEquals(UploadPolicy.Result.RETRY, UploadPolicy.response(201, "packet-a", "packet-a", 0))
        assertEquals(UploadPolicy.Result.RETRY, UploadPolicy.response(204, "packet-a", null, 0))
    }

    @Test fun serverFailuresKeepPacketsAndDistinguishInvalidPayloads() {
        listOf(301, 404, 429, 500, 503).forEach {
            assertEquals(UploadPolicy.Result.RETRY, UploadPolicy.response(it, "a", null, 0))
        }
        listOf(401, 403).forEach {
            assertEquals(UploadPolicy.Result.AUTH_ERROR, UploadPolicy.response(it, "a", null, 0))
        }
        listOf(409, 422).forEach {
            assertEquals(UploadPolicy.Result.BLOCKED, UploadPolicy.response(it, "a", null, 0))
        }
    }
}
