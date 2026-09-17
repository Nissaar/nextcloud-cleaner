package xyz.photosweep.vm

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import xyz.photosweep.Graph
import xyz.photosweep.api.ApplyResult
import xyz.photosweep.api.Decision

data class ReviewState(
    val pending: List<Decision> = emptyList(),
    val applied: List<Decision> = emptyList(),
    val loading: Boolean = true,
    val applying: Boolean = false,
    val result: ApplyResult? = null,
    val error: String? = null,
    /** Verdicts still sitting in the outbox; the list below is incomplete while > 0. */
    val queued: Int = 0,
) {
    val totalBytes: Long get() = pending.sumOf { it.size }
}

/**
 * The review-and-confirm screen.
 *
 * This is the only view model that can change files, and it does so exactly once, on
 * an explicit call from a confirmation dialog.
 */
class ReviewViewModel : ViewModel() {

    private val _state = MutableStateFlow(ReviewState())
    val state: StateFlow<ReviewState> = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        viewModelScope.launch {
            try {
                // Drained first: the list would otherwise be missing exactly the
                // photos the user has just finished marking.
                runCatching { Graph.repository.flushOutbox() }
                _state.value = _state.value.copy(
                    pending = Graph.repository.pending().decisions,
                    applied = Graph.repository.applied().decisions,
                    queued = runCatching { Graph.repository.queuedCount() }.getOrDefault(0),
                    loading = false,
                    error = null,
                )
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = e.message)
            }
        }
    }

    /** Takes one photo back out of the list, before anything happens to it. */
    fun keepAfterAll(fileId: Long) {
        viewModelScope.launch {
            runCatching { Graph.repository.undo(fileId) }
            _state.value = _state.value.copy(pending = _state.value.pending.filterNot { it.fileId == fileId })
        }
    }

    /** Carries out every pending delete. Requires confirmation upstream. */
    fun apply() {
        if (_state.value.applying) return

        viewModelScope.launch {
            _state.value = _state.value.copy(applying = true, result = null, error = null)
            try {
                val result = Graph.repository.apply()
                _state.value = _state.value.copy(applying = false, result = result)
                load()
            } catch (e: Exception) {
                _state.value = _state.value.copy(applying = false, error = e.message)
            }
        }
    }

    fun restore(fileId: Long) {
        viewModelScope.launch {
            try {
                val result = Graph.repository.restore(listOf(fileId))
                if (result.restored == 0) {
                    _state.value = _state.value.copy(
                        error = result.failures.firstOrNull()?.reason ?: "Could not bring that back",
                    )
                }
                load()
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message)
            }
        }
    }

    fun clearResult() {
        _state.value = _state.value.copy(result = null, error = null)
    }
}
