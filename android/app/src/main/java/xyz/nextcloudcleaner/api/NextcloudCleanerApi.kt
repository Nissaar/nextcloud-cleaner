package xyz.nextcloudcleaner.api

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import xyz.nextcloudcleaner.data.Account
import java.io.IOException
import java.net.URLEncoder

/**
 * The Nextcloud Cleaner server app's API.
 *
 * Every call carries the account's app password as Basic auth and the
 * `OCS-APIRequest` header, which is what tells Nextcloud this is an API call rather
 * than a browser and exempts it from the CSRF check.
 */
class NextcloudCleanerApi(
    private val client: OkHttpClient,
    private val accountProvider: () -> Account?,
) {

    private val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
    }

    private val jsonMedia = "application/json; charset=utf-8".toMediaType()

    // --- reads -------------------------------------------------------------

    suspend fun status(): StatusResponse = get("index")

    suspend fun months(): MonthsResponse = get("months")

    suspend fun month(yearMonth: String, skipDecided: Boolean? = null): MonthResponse {
        val query = skipDecided?.let { "?skipDecided=${if (it) 1 else 0}" } ?: ""
        return get("months/${encode(yearMonth)}$query")
    }

    suspend fun pending(): DecisionsResponse = get("decisions/pending")

    suspend fun applied(): DecisionsResponse = get("decisions/applied")

    suspend fun config(): ServerConfig = get("config")

    // --- writes ------------------------------------------------------------

    /** Advances the server's index by one bounded chunk. */
    suspend fun scan(full: Boolean = false): ScanResponse =
        post("index", buildJsonObject { put("full", full) })

    suspend fun record(fileId: Long, verdict: String): Decision =
        post(
            "decisions",
            buildJsonObject {
                put("fileId", fileId)
                put("verdict", verdict)
            },
        )

    /**
     * Sends a batch of verdicts in one request.
     *
     * This is what the outbox uses. A review session with no signal produces a hundred
     * verdicts, and they should reach the server in one round trip when it comes back,
     * not a hundred.
     */
    suspend fun recordMany(verdicts: List<PendingVerdict>): RecordBatchResult =
        post(
            "decisions",
            buildJsonObject {
                put(
                    "verdicts",
                    buildJsonArray {
                        verdicts.forEach { verdict ->
                            add(
                                JsonObject(
                                    mapOf(
                                        "fileId" to JsonPrimitive(verdict.fileId),
                                        "verdict" to JsonPrimitive(verdict.verdict),
                                    ),
                                ),
                            )
                        }
                    },
                )
            },
        )

    suspend fun undo(fileId: Long): JsonObject = delete("decisions/$fileId")

    suspend fun resetMonth(yearMonth: String): ClearedResult = delete("months/${encode(yearMonth)}")

    /** The only call that changes files. */
    suspend fun apply(): ApplyResult = post("apply", buildJsonObject {})

    suspend fun restore(fileIds: List<Long>): RestoreResult =
        post(
            "restore",
            buildJsonObject {
                put("fileIds", buildJsonArray { fileIds.forEach { add(JsonPrimitive(it)) } })
            },
        )

    suspend fun updateConfig(patch: JsonObject): ServerConfig = put("config", patch)

    // --- plumbing ----------------------------------------------------------

    private suspend inline fun <reified T> get(path: String): T =
        call(request(path).get().build())

    private suspend inline fun <reified T> post(path: String, body: JsonObject): T =
        call(request(path).post(body.toString().toRequestBody(jsonMedia)).build())

    private suspend inline fun <reified T> put(path: String, body: JsonObject): T =
        call(request(path).put(body.toString().toRequestBody(jsonMedia)).build())

    private suspend inline fun <reified T> delete(path: String): T =
        call(request(path).delete().build())

    fun request(path: String): Request.Builder {
        val account = accountProvider() ?: throw NotSignedInException()
        return Request.Builder()
            .url("${account.server}/ocs/v2.php/apps/nextcloud_cleaner/api/v1/$path")
            .header("Authorization", account.basicAuthHeader())
            .header("OCS-APIRequest", "true")
            .header("Accept", "application/json")
            .header("User-Agent", LoginFlow.USER_AGENT)
    }

    private suspend inline fun <reified T> call(request: Request): T = withContext(Dispatchers.IO) {
        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()

            // An expired or revoked app password is the one failure worth naming
            // exactly, because the fix is to sign in again rather than to retry.
            if (response.code == 401) throw NotSignedInException()

            if (body.isBlank()) {
                throw ApiException("The server returned nothing (HTTP ${response.code})")
            }

            val envelope = try {
                json.decodeFromString<OcsEnvelope<T>>(body)
            } catch (e: Exception) {
                throw ApiException(
                    if (response.isSuccessful) {
                        "Could not read the server's reply. Is the Nextcloud Cleaner app enabled?"
                    } else {
                        "The server returned HTTP ${response.code}"
                    },
                )
            }

            // OCS reports failures in the envelope, with HTTP 200 underneath, so the
            // HTTP status alone is not enough to tell whether the call worked.
            val status = envelope.ocs.meta.statuscode
            if (status != 100 && status != 200) {
                throw ApiException(envelope.ocs.meta.message ?: "The server refused that request")
            }

            envelope.ocs.data
        }
    }

    private fun encode(value: String): String = URLEncoder.encode(value, "UTF-8")
}

open class ApiException(message: String) : IOException(message)

class NotSignedInException : ApiException("You are signed out. Sign in again to carry on.")
