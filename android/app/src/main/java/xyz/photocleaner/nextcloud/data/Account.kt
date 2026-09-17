package xyz.photocleaner.nextcloud.data

import okhttp3.Credentials
import java.net.URLEncoder

/**
 * A signed-in Nextcloud account.
 *
 * [appPassword] is not the user's password: it is a per-device credential issued by
 * Login Flow v2, which the user can revoke from Nextcloud's own security settings
 * without changing anything else about their account.
 */
data class Account(
    val server: String,
    val loginName: String,
    val appPassword: String,
) {
    fun basicAuthHeader(): String = Credentials.basic(loginName, appPassword)

    /**
     * A thumbnail, from Nextcloud core rather than from this app.
     *
     * `a=1` keeps the aspect ratio. Without it every portrait photo arrives cropped to
     * a square, which is exactly the framing that makes a keep-or-delete judgement
     * harder than it needs to be.
     */
    fun previewUrl(fileId: Long, size: Int): String =
        "$server/index.php/core/preview?fileId=$fileId&x=$size&y=$size&a=1"

    /**
     * The file itself, for playing a video.
     *
     * @param path relative to the user's files root
     */
    fun fileUrl(path: String): String {
        val encoded = path.trim('/')
            .split('/')
            .joinToString("/") { URLEncoder.encode(it, "UTF-8").replace("+", "%20") }
        return "$server/remote.php/dav/files/${URLEncoder.encode(loginName, "UTF-8")}/$encoded"
    }

    /** Nextcloud's own trash, for when the app cannot restore something itself. */
    fun trashUrl(): String = "$server/index.php/apps/files/trashbin"
}
