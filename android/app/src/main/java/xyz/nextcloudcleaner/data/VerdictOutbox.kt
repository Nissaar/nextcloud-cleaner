package xyz.nextcloudcleaner.data

import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import xyz.nextcloudcleaner.api.PendingVerdict
import java.io.File

/**
 * Verdicts that have been given but have not reached the server yet.
 *
 * Swiping has to stay instant. Waiting for a round trip between every photo is what
 * makes going through a thousand of them unbearable, and a train tunnel should not end
 * a review session. So a verdict is written here first and sent afterwards, and what
 * is here survives the process being killed.
 *
 * Ordering is preserved, and a later verdict for the same file replaces an earlier one
 * — changing your mind twice should not send two contradictory instructions.
 */
class VerdictOutbox(private val file: File) {

    private val json = Json { ignoreUnknownKeys = true }
    private val mutex = Mutex()

    private companion object {
        const val TAG = "VerdictOutbox"

        /**
         * A cap, so a pathological session cannot grow the file without limit. Ten
         * thousand verdicts is far more than anyone gives in one offline stretch.
         */
        const val MAX_ENTRIES = 10_000
    }

    suspend fun add(verdict: PendingVerdict) = mutex.withLock {
        val current = readUnlocked().filterNot { it.fileId == verdict.fileId }
        writeUnlocked((current + verdict).takeLast(MAX_ENTRIES))
    }

    /** Drops a verdict before it is sent, which is what undo does while offline. */
    suspend fun remove(fileId: Long) = mutex.withLock {
        writeUnlocked(readUnlocked().filterNot { it.fileId == fileId })
    }

    suspend fun all(): List<PendingVerdict> = mutex.withLock { readUnlocked() }

    suspend fun size(): Int = mutex.withLock { readUnlocked().size }

    /**
     * Hands the queued verdicts to [send] and clears only what it accepted.
     *
     * Anything added while the send was in flight is kept: clearing the file wholesale
     * would throw away verdicts that were never transmitted.
     */
    suspend fun flush(send: suspend (List<PendingVerdict>) -> Unit) {
        val batch = mutex.withLock { readUnlocked() }
        if (batch.isEmpty()) return

        send(batch)

        mutex.withLock {
            val sent = batch.map { it.fileId }.toHashSet()
            writeUnlocked(readUnlocked().filterNot { it.fileId in sent })
        }
    }

    private fun readUnlocked(): List<PendingVerdict> {
        if (!file.exists()) return emptyList()
        return try {
            json.decodeFromString<List<PendingVerdict>>(file.readText())
        } catch (e: Exception) {
            // A truncated file from a hard kill is not worth crashing over, and its
            // contents are recoverable by reviewing the month again.
            Log.w(TAG, "Discarding an unreadable outbox", e)
            emptyList()
        }
    }

    private fun writeUnlocked(verdicts: List<PendingVerdict>) {
        try {
            if (verdicts.isEmpty()) {
                file.delete()
                return
            }
            // Written to a temporary file and moved into place, so a kill halfway
            // through leaves the previous queue rather than half of the new one.
            val temp = File(file.parentFile, "${file.name}.tmp")
            temp.writeText(json.encodeToString(verdicts))
            if (!temp.renameTo(file)) {
                file.writeText(temp.readText())
                temp.delete()
            }
        } catch (e: Exception) {
            Log.w(TAG, "Could not write the outbox", e)
        }
    }
}

/** Runs [block] off the main thread; the outbox does file IO. */
suspend fun <T> onIo(block: suspend () -> T): T = withContext(Dispatchers.IO) { block() }
