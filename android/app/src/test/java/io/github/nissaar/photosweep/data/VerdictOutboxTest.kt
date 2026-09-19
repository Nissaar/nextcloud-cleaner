package io.github.nissaar.photosweep.data

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import io.github.nissaar.photosweep.api.PendingVerdict
import io.github.nissaar.photosweep.api.Verdict
import java.io.File

/**
 * The outbox is what lets swiping stay instant and survive going offline, so its
 * edges — replacing a verdict, losing the process, a failed send — are worth pinning
 * down.
 */
class VerdictOutboxTest {

    @get:Rule
    val folder = TemporaryFolder()

    private fun outbox(name: String = "outbox.json"): Pair<VerdictOutbox, File> {
        val file = File(folder.root, name)
        return VerdictOutbox(file) to file
    }

    @Test
    fun `keeps verdicts in the order they were given`() = runTest {
        val (box, _) = outbox()
        box.add(PendingVerdict(1, Verdict.KEEP))
        box.add(PendingVerdict(2, Verdict.DELETE))
        box.add(PendingVerdict(3, Verdict.KEEP))

        assertEquals(listOf(1L, 2L, 3L), box.all().map { it.fileId })
    }

    @Test
    fun `changing your mind replaces the earlier verdict`() = runTest {
        val (box, _) = outbox()
        box.add(PendingVerdict(1, Verdict.DELETE))
        box.add(PendingVerdict(1, Verdict.KEEP))

        // Sending both would tell the server two contradictory things about one file.
        assertEquals(1, box.size())
        assertEquals(Verdict.KEEP, box.all().single().verdict)
    }

    @Test
    fun `undo removes a queued verdict`() = runTest {
        val (box, _) = outbox()
        box.add(PendingVerdict(1, Verdict.DELETE))
        box.add(PendingVerdict(2, Verdict.DELETE))

        box.remove(1)

        assertEquals(listOf(2L), box.all().map { it.fileId })
    }

    @Test
    fun `survives being reopened`() = runTest {
        val file = File(folder.root, "persist.json")
        VerdictOutbox(file).add(PendingVerdict(7, Verdict.DELETE))

        // A process killed between swiping and syncing must not lose the verdicts.
        assertEquals(listOf(7L), VerdictOutbox(file).all().map { it.fileId })
    }

    @Test
    fun `clears only what was sent`() = runTest {
        val (box, _) = outbox()
        box.add(PendingVerdict(1, Verdict.KEEP))
        box.add(PendingVerdict(2, Verdict.KEEP))

        var sent: List<PendingVerdict>? = null
        box.flush { batch ->
            sent = batch
            // Arrives while the send is in flight. It was never transmitted, so it
            // must still be queued afterwards.
            box.add(PendingVerdict(3, Verdict.DELETE))
        }

        assertEquals(listOf(1L, 2L), sent?.map { it.fileId })
        assertEquals(listOf(3L), box.all().map { it.fileId })
    }

    @Test
    fun `a failed send keeps everything queued`() = runTest {
        val (box, _) = outbox()
        box.add(PendingVerdict(1, Verdict.KEEP))

        val failed = runCatching {
            box.flush { throw IllegalStateException("offline") }
        }

        assertTrue(failed.isFailure)
        assertEquals(1, box.size())
    }

    @Test
    fun `an empty queue does not call the sender`() = runTest {
        val (box, _) = outbox()
        var called = false
        box.flush { called = true }
        assertFalse(called)
    }

    @Test
    fun `a corrupted file is discarded rather than crashing`() = runTest {
        val file = File(folder.root, "broken.json")
        file.writeText("[{\"fileId\": 1, \"verdi")

        val box = VerdictOutbox(file)

        // Half a file from a hard kill is not worth taking the app down over; the
        // verdicts can be given again by reviewing the month.
        assertEquals(emptyList<PendingVerdict>(), box.all())
        box.add(PendingVerdict(9, Verdict.KEEP))
        assertEquals(listOf(9L), box.all().map { it.fileId })
    }

    @Test
    fun `emptying the queue removes the file`() = runTest {
        val (box, file) = outbox()
        box.add(PendingVerdict(1, Verdict.KEEP))
        assertTrue(file.exists())

        box.remove(1)

        assertFalse(file.exists())
    }
}
