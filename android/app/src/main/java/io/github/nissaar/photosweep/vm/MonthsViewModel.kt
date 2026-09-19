package io.github.nissaar.photosweep.vm

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import io.github.nissaar.photosweep.Graph
import io.github.nissaar.photosweep.api.MonthEntry
import io.github.nissaar.photosweep.api.NotSignedInException
import io.github.nissaar.photosweep.api.Summary

/**
 * Which months the grid shows.
 *
 * Defaults to [TO_REVIEW]: with a decade of months on screen, a badge on the finished
 * ones still leaves you scanning the whole grid to find what is left to do.
 */
enum class MonthFilter(val label: String) {
    TO_REVIEW("To review"),
    DONE("Done"),
    ALL("All"),
}

data class MonthsState(
    val months: List<MonthEntry> = emptyList(),
    val summary: Summary = Summary(),
    val filter: MonthFilter = MonthFilter.TO_REVIEW,
    val loading: Boolean = true,
    val error: String? = null,
) {
    val visible: List<MonthEntry>
        get() = when (filter) {
            MonthFilter.TO_REVIEW -> months.filterNot { it.done }
            MonthFilter.DONE -> months.filter { it.done }
            MonthFilter.ALL -> months
        }

    val toReviewCount: Int get() = months.count { !it.done }
    val doneCount: Int get() = months.count { it.done }
}

class MonthsViewModel : ViewModel() {

    private val _state = MutableStateFlow(MonthsState())
    val state: StateFlow<MonthsState> = _state.asStateFlow()

    init {
        load()
    }

    fun setFilter(filter: MonthFilter) {
        _state.value = _state.value.copy(filter = filter)
    }

    fun load() {
        viewModelScope.launch {
            try {
                val response = Graph.repository.months()
                _state.value = _state.value.copy(
                    months = response.months,
                    summary = response.summary,
                    loading = false,
                    error = null,
                )
            } catch (e: NotSignedInException) {
                throw e
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = e.message)
            }
        }
    }

    /**
     * Forgets a month's verdicts so it can be gone through again.
     * Local to the server's records — nothing in Files changes.
     */
    fun reopen(month: String) {
        viewModelScope.launch {
            runCatching { Graph.repository.resetMonth(month) }
            load()
        }
    }
}
