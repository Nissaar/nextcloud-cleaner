package xyz.photosweep

import android.app.Application
import coil.ImageLoader
import coil.ImageLoaderFactory
import coil.disk.DiskCache
import coil.memory.MemoryCache
import okhttp3.OkHttpClient
import xyz.photosweep.api.LoginFlow
import xyz.photosweep.api.PhotoSweepApi
import xyz.photosweep.data.AccountStore
import xyz.photosweep.data.Repository
import xyz.photosweep.data.Settings
import xyz.photosweep.data.VerdictOutbox
import java.io.File
import java.util.concurrent.TimeUnit

/**
 * The object graph.
 *
 * Small enough that a dependency-injection framework would cost more than it saves,
 * and explicit enough that what depends on what is readable in one screen.
 */
object Graph {
    lateinit var accounts: AccountStore
        private set
    lateinit var settings: Settings
        private set
    lateinit var api: PhotoSweepApi
        private set
    lateinit var repository: Repository
        private set
    lateinit var loginFlow: LoginFlow
        private set
    lateinit var http: OkHttpClient
        private set

    fun open(application: Application) {
        accounts = AccountStore(application)
        settings = Settings(application)

        http = OkHttpClient.Builder()
            .connectTimeout(20, TimeUnit.SECONDS)
            // Generous, because a first index scan on a large library legitimately
            // keeps a request open for a while before it answers.
            .readTimeout(120, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .build()

        api = PhotoSweepApi(http) { accounts.current() }
        repository = Repository(api, VerdictOutbox(File(application.filesDir, "outbox.json")))
        loginFlow = LoginFlow(http)
    }
}

class App : Application(), ImageLoaderFactory {

    override fun onCreate() {
        super.onCreate()
        Graph.open(this)
    }

    /**
     * Previews come from the user's own Nextcloud and need the account's credentials,
     * which a plain image request would not carry.
     *
     * The header is attached per request from the store rather than baked in, so
     * signing out takes effect immediately instead of leaving a client that can still
     * fetch the previous account's photos.
     */
    override fun newImageLoader(): ImageLoader {
        val client = Graph.http.newBuilder()
            .addInterceptor { chain ->
                val account = Graph.accounts.current()
                val request = chain.request()
                val authorised = if (account != null && request.url.toString().startsWith(account.server)) {
                    request.newBuilder()
                        .header("Authorization", account.basicAuthHeader())
                        .build()
                } else {
                    request
                }
                chain.proceed(authorised)
            }
            .build()

        return ImageLoader.Builder(this)
            .okHttpClient(client)
            .memoryCache { MemoryCache.Builder(this).maxSizePercent(0.25).build() }
            .diskCache {
                DiskCache.Builder()
                    .directory(cacheDir.resolve("previews"))
                    .maxSizeBytes(256L * 1024 * 1024)
                    .build()
            }
            .respectCacheHeaders(false)
            .build()
    }
}
