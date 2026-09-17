package xyz.photosweep.data

import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import xyz.photosweep.api.ApplyResult
import xyz.photosweep.api.ClearedResult
import xyz.photosweep.api.DecisionsResponse
import xyz.photosweep.api.MediaItem
import xyz.photosweep.api.MonthResponse
import xyz.photosweep.api.MonthsResponse
import xyz.photosweep.api.NotSignedInException
import xyz.photosweep.api.PendingVerdict
import xyz.photosweep.api.PhotoSweepApi
import xyz.photosweep.api.RestoreResult
import xyz.photosweep.api.ScanResponse
import xyz.photosweep.api.ServerConfig
import xyz.photosweep.api.StatusResponse

/**
 * Everything the screens need, with the offline queue folded in.
 *
 * The rule here is that giving a verdict never fails and never blocks. It is written
 * to the outbox, and reaching the server is a separate concern that retries. The one
 * thing that does talk to the server synchronously is applying verdicts, because that
 * is the step that changes files and its outcome has to be reported honestly.
 */
class Repository(
    private val api: PhotoSweepApi,
    private val outbox: VerdictOutbox,
) {

    suspend fun status(): StatusResponse = api.status()

    suspend fun scan(full: Boolean = false): ScanResponse = api.scan(full)

    suspend fun months(): MonthsResponse = api.months()

    /**
     * One month's photos.
     *
     * Anything already sitting in the outbox is filtered out here. Without that, a
     * month reopened before the queue has drained deals the same photos again — the
     * server has not heard about them yet, so it still thinks they need a verdict.
     */
    suspend fun month(yearMonth: String, skipDecided: Boolean? = null): MonthResponse {
        val response = api.month(yearMonth, skipDecided)
        val queued = outbox.all().map { it.fileId }.toHashSet()
        if (queued.isEmpty()) return response
        return response.copy(items = response.items.filterNot { it.fileId in queued })
    }

    /**
     * Records a verdict. Returns immediately; delivery is the outbox's problem.
     *
     * @return whether it reached the server straight away
     */
    suspend fun record(item: MediaItem, verdict: String): Boolean {
        outbox.add(PendingVerdict(item.fileId, verdict))
        return flushQuietly()
    }

    /**
     * Takes a verdict back.
     *
     * If it never left the outbox this is purely local. If it did, the server is asked
     * to forget it — which it will refuse if it has already been carried out, and that
     * refusal is correct: the way back from there is a restore, not an undo.
     */
    suspend fun undo(fileId: Long): Boolean {
        val queued = outbox.all().any { it.fileId == fileId }
        outbox.remove(fileId)
        if (queued) return true

        return try {
            api.undo(fileId)
            true
        } catch (e: NotSignedInException) {
            throw e
        } catch (e: Exception) {
            false
        }
    }

    /** How many verdicts have not reached the server yet. */
    suspend fun queuedCount(): Int = outbox.size()

    /**
     * Pushes the queue to the server.
     *
     * @return true if the queue is now empty
     */
    suspend fun flushOutbox(): Boolean {
        outbox.flush { batch -> api.recordMany(batch) }
        return outbox.size() == 0
    }

    private suspend fun flushQuietly(): Boolean = try {
        flushOutbox()
    } catch (e: NotSignedInException) {
        throw e
    } catch (e: Exception) {
        // Offline, or the server is having a moment. The queue keeps it.
        false
    }

    suspend fun pending(): DecisionsResponse = api.pending()

    suspend fun applied(): DecisionsResponse = api.applied()

    /**
     * Carries out every pending delete verdict.
     *
     * The queue is drained first, on purpose and without swallowing the failure: if
     * some verdicts have not arrived, applying now would quietly skip exactly the
     * photos the user has just finished marking.
     */
    suspend fun apply(): ApplyResult {
        flushOutbox()
        return api.apply()
    }

    suspend fun restore(fileIds: List<Long>): RestoreResult = api.restore(fileIds)

    suspend fun resetMonth(yearMonth: String): ClearedResult = api.resetMonth(yearMonth)

    suspend fun config(): ServerConfig = api.config()

    suspend fun setMode(mode: String): ServerConfig =
        api.updateConfig(buildJsonObject { put("mode", mode) })

    suspend fun setTargetFolder(path: String): ServerConfig =
        api.updateConfig(buildJsonObject { put("targetFolder", path) })

    suspend fun setSkipDecided(value: Boolean): ServerConfig =
        api.updateConfig(buildJsonObject { put("skipDecided", value) })
}
