package xyz.photosweep.vm

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import xyz.photosweep.Graph
import xyz.photosweep.api.NotSignedInException
import xyz.photosweep.api.ScanState
import xyz.photosweep.api.ServerConfig
import xyz.photosweep.api.Summary

data class AppState(
    val signedIn: Boolean = false,
    val loading: Boolean = true,
    val summary: Summary = Summary(),
    val scan: ScanState = ScanState(),
    val config: ServerConfig = ServerConfig(),
    val trashAvailable: Boolean = true,
    val queued: Int = 0,
    val error: String? = null,
) {
    val serverName: String
        get() = Graph.accounts.current()?.server?.removePrefix("https://")?.removePrefix("http://") ?: ""
}

/**
 * Session-wide state: who is signed in, and how the server's index is doing.
 */
class AppViewModel : ViewModel() {

    private val _state = MutableStateFlow(AppState(signedIn = Graph.accounts.isSignedIn()))
    val state: StateFlow<AppState> = _state.asStateFlow()

    private var scanning = false

    init {
        if (_state.value.signedIn) refresh()
    }

    fun onSignedIn() {
        _state.value = _state.value.copy(signedIn = true, loading = true, error = null)
        refresh()
    }

    fun signOut() {
        Graph.accounts.clear()
        _state.value = AppState(signedIn = false, loading = false)
    }

    fun refresh() {
        viewModelScope.launch {
            try {
                // Anything given while offline goes first, so the numbers below it
                // describe the same reality the user has been working in.
                val drained = runCatching { Graph.repository.flushOutbox() }.getOrDefault(false)
                val status = Graph.repository.status()
                _state.value = _state.value.copy(
                    loading = false,
                    summary = status.summary,
                    scan = status.scan,
                    config = status.config,
                    trashAvailable = status.trashAvailable,
                    queued = if (drained) 0 else Graph.repository.queuedCount(),
                    error = null,
                )

                // Nothing indexed and no scan finished means a first run. Start one
                // without being asked: an empty grid with a button on it is a worse
                // first impression than months appearing as they are found.
                if (!status.scan.complete && !status.scan.running) startScan(full = false)
            } catch (e: NotSignedInException) {
                signOut()
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = e.message)
            }
        }
    }

    /**
     * Runs the server's index to completion, one bounded chunk per request.
     *
     * The server caps how much it does per call so the request returns inside the web
     * server's timeout; going again while it reports incomplete is what turns a long
     * first scan into visible progress rather than a failure.
     */
    fun startScan(full: Boolean = false) {
        if (scanning) return
        scanning = true

        viewModelScope.launch {
            try {
                var guard = 0
                do {
                    val result = Graph.repository.scan(full && guard == 0)
                    _state.value = _state.value.copy(scan = result.scan, summary = result.summary)
                    if (result.scan.error != null) break
                    guard++
                } while (!_state.value.scan.complete && guard < 500)
            } catch (e: NotSignedInException) {
                signOut()
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message)
            } finally {
                scanning = false
            }
        }
    }

    fun setMode(mode: String) = updateConfig { Graph.repository.setMode(mode) }

    fun setTargetFolder(path: String) = updateConfig { Graph.repository.setTargetFolder(path) }

    fun setSkipDecided(value: Boolean) = updateConfig { Graph.repository.setSkipDecided(value) }

    private fun updateConfig(block: suspend () -> ServerConfig) {
        viewModelScope.launch {
            try {
                _state.value = _state.value.copy(config = block(), error = null)
            } catch (e: NotSignedInException) {
                signOut()
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message)
            }
        }
    }

    fun clearError() {
        _state.value = _state.value.copy(error = null)
    }
}
