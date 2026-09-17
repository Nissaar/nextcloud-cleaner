package xyz.photocleaner.nextcloud.api

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

/**
 * The address field is the first thing anyone touches, and people type every one of
 * these forms.
 */
class ServerUrlTest {

    @Test
    fun `assumes https when no scheme is given`() {
        assertEquals("https://cloud.example.com", normaliseServerUrl("cloud.example.com"))
    }

    @Test
    fun `keeps an explicit http scheme`() {
        // Guessing https here would be friendlier but would break the rare self-hosted
        // server behind a trusted tunnel. Typing it out is the opt-in.
        assertEquals("http://192.168.1.10:8080", normaliseServerUrl("http://192.168.1.10:8080"))
    }

    @Test
    fun `strips trailing slashes`() {
        assertEquals("https://cloud.example.com", normaliseServerUrl("https://cloud.example.com/"))
        assertEquals("https://cloud.example.com", normaliseServerUrl("https://cloud.example.com///"))
    }

    @Test
    fun `strips a pasted app path`() {
        assertEquals(
            "https://cloud.example.com",
            normaliseServerUrl("https://cloud.example.com/index.php/apps/files"),
        )
        assertEquals(
            "https://cloud.example.com",
            normaliseServerUrl("https://cloud.example.com/apps/photos/"),
        )
        assertEquals(
            "https://cloud.example.com",
            normaliseServerUrl("https://cloud.example.com/settings/user"),
        )
    }

    @Test
    fun `keeps a subdirectory install intact`() {
        // Nextcloud is often served from a subdirectory, and cutting that off would
        // point every request at the wrong host root.
        assertEquals(
            "https://example.com/nextcloud",
            normaliseServerUrl("https://example.com/nextcloud/"),
        )
    }

    @Test
    fun `trims surrounding whitespace`() {
        assertEquals("https://cloud.example.com", normaliseServerUrl("  cloud.example.com  "))
    }

    @Test
    fun `refuses an empty address`() {
        assertThrows(LoginException::class.java) { normaliseServerUrl("   ") }
    }
}
