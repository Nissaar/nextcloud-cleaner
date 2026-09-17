package xyz.nextcloudcleaner.data

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Where the app password lives.
 *
 * Held in `EncryptedSharedPreferences`, so the bytes on disk are encrypted under a key
 * the Android Keystore holds and the app itself never sees. That is the difference
 * between a stolen device backup containing a working credential and containing
 * nothing useful.
 *
 * Falls back to plain preferences only if the Keystore is unusable — which happens on
 * a small number of broken devices — and says so in the log rather than failing to
 * sign in at all.
 */
class AccountStore(context: Context) {

    private companion object {
        const val FILE = "nextcloud_cleaner-account"
        const val KEY_SERVER = "server"
        const val KEY_LOGIN = "login_name"
        const val KEY_PASSWORD = "app_password"
        const val TAG = "AccountStore"
    }

    private val prefs: SharedPreferences = try {
        EncryptedSharedPreferences.create(
            context,
            FILE,
            MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    } catch (e: Exception) {
        Log.w(TAG, "Keystore unavailable; storing the account unencrypted", e)
        context.getSharedPreferences(FILE, Context.MODE_PRIVATE)
    }

    @Volatile
    private var cached: Account? = load()

    fun current(): Account? = cached

    fun isSignedIn(): Boolean = cached != null

    fun save(account: Account) {
        prefs.edit()
            .putString(KEY_SERVER, account.server)
            .putString(KEY_LOGIN, account.loginName)
            .putString(KEY_PASSWORD, account.appPassword)
            .apply()
        cached = account
    }

    fun clear() {
        prefs.edit().clear().apply()
        cached = null
    }

    private fun load(): Account? {
        val server = prefs.getString(KEY_SERVER, null) ?: return null
        val login = prefs.getString(KEY_LOGIN, null) ?: return null
        val password = prefs.getString(KEY_PASSWORD, null) ?: return null
        return Account(server, login, password)
    }
}
