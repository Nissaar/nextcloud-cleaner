package xyz.nextcloudcleaner

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.browser.customtabs.CustomTabsIntent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.DeleteSweep
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import xyz.nextcloudcleaner.ui.LoginScreen
import xyz.nextcloudcleaner.ui.MonthsScreen
import xyz.nextcloudcleaner.ui.ReviewScreen
import xyz.nextcloudcleaner.ui.SettingsScreen
import xyz.nextcloudcleaner.ui.SwipeScreen
import xyz.nextcloudcleaner.ui.monthLabel
import xyz.nextcloudcleaner.ui.theme.NextcloudCleanerTheme
import xyz.nextcloudcleaner.vm.AppViewModel
import xyz.nextcloudcleaner.vm.LoginViewModel
import xyz.nextcloudcleaner.vm.MonthsViewModel
import xyz.nextcloudcleaner.vm.ReviewViewModel
import xyz.nextcloudcleaner.vm.SwipeViewModel

private enum class Tab { MONTHS, REVIEW, SETTINGS }

class MainActivity : FragmentActivity() {

    private var unlocked = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        setContent {
            NextcloudCleanerTheme {
                Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
                    Root()
                }
            }
        }

        observeLifecycle()
    }

    /**
     * Applies the screenshot setting and the optional lock.
     *
     * FLAG_SECURE is on unless the user turns it off: this app puts an entire photo
     * library on screen, which has no business appearing in a screen recording or in
     * the recent-apps preview.
     */
    private fun observeLifecycle() {
        lifecycle.addObserver(
            LifecycleEventObserver { _, event ->
                if (event == Lifecycle.Event.ON_START) {
                    applySecureFlag()
                    maybePromptUnlock()
                }
            },
        )
    }

    private fun applySecureFlag() {
        lifecycleScope.launch {
            val allow = Graph.settings.allowScreenshots.first()
            if (allow) {
                window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
            } else {
                window.setFlags(
                    WindowManager.LayoutParams.FLAG_SECURE,
                    WindowManager.LayoutParams.FLAG_SECURE,
                )
            }
        }
    }

    private fun maybePromptUnlock() {
        if (unlocked) return

        lifecycleScope.launch {
            if (!Graph.settings.appLock.first()) {
                unlocked = true
                return@launch
            }

            val allowed = BiometricManager.Authenticators.BIOMETRIC_WEAK or
                BiometricManager.Authenticators.DEVICE_CREDENTIAL

            // Nothing to authenticate against — no biometrics enrolled and no device
            // lock. Refusing entry here would lock the user out of their own app with
            // no way back in.
            if (BiometricManager.from(this@MainActivity).canAuthenticate(allowed)
                != BiometricManager.BIOMETRIC_SUCCESS
            ) {
                unlocked = true
                return@launch
            }

            val prompt = BiometricPrompt(
                this@MainActivity,
                ContextCompat.getMainExecutor(this@MainActivity),
                object : BiometricPrompt.AuthenticationCallback() {
                    override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                        unlocked = true
                    }

                    override fun onAuthenticationError(code: Int, message: CharSequence) {
                        finish()
                    }
                },
            )

            prompt.authenticate(
                BiometricPrompt.PromptInfo.Builder()
                    .setTitle("Unlock Nextcloud Cleaner")
                    .setAllowedAuthenticators(allowed)
                    .build(),
            )
        }
    }

    fun openInBrowser(url: String) {
        try {
            CustomTabsIntent.Builder().build().launchUrl(this, Uri.parse(url))
        } catch (e: Exception) {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Root() {
    val appViewModel: AppViewModel = viewModel()
    val appState by appViewModel.state.collectAsState()

    if (!appState.signedIn) {
        SignIn(onSignedIn = appViewModel::onSignedIn)
        return
    }

    var tab by remember { mutableStateOf(Tab.MONTHS) }
    var openMonth by remember { mutableStateOf<String?>(null) }

    val monthsViewModel: MonthsViewModel = viewModel()
    val monthsState by monthsViewModel.state.collectAsState()

    val allowScreenshots by Graph.settings.allowScreenshots.collectAsState(initial = false)
    val appLock by Graph.settings.appLock.collectAsState(initial = false)
    val scope = androidx.compose.runtime.rememberCoroutineScope()

    // Coming back from a month is a back gesture, not a button hunt.
    BackHandler(enabled = openMonth != null) { openMonth = null }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        when {
                            openMonth != null -> monthLabel(openMonth!!)
                            tab == Tab.MONTHS -> "Nextcloud Cleaner"
                            tab == Tab.REVIEW -> "Marked for deletion"
                            else -> "Settings"
                        },
                    )
                },
            )
        },
        bottomBar = {
            if (openMonth == null) {
                NavigationBar {
                    NavigationBarItem(
                        selected = tab == Tab.MONTHS,
                        onClick = { tab = Tab.MONTHS },
                        icon = {
                            BadgedBox(badge = {
                                if (monthsState.toReviewCount > 0) Badge { Text("${monthsState.toReviewCount}") }
                            }) { Icon(Icons.Default.CalendarMonth, contentDescription = null) }
                        },
                        label = { Text("Months") },
                    )
                    NavigationBarItem(
                        selected = tab == Tab.REVIEW,
                        onClick = { tab = Tab.REVIEW },
                        icon = {
                            BadgedBox(badge = {
                                if (appState.summary.pendingDeletes > 0) {
                                    Badge { Text("${appState.summary.pendingDeletes}") }
                                }
                            }) { Icon(Icons.Default.DeleteSweep, contentDescription = null) }
                        },
                        label = { Text("Marked") },
                    )
                    NavigationBarItem(
                        selected = tab == Tab.SETTINGS,
                        onClick = { tab = Tab.SETTINGS },
                        icon = { Icon(Icons.Default.Settings, contentDescription = null) },
                        label = { Text("Settings") },
                    )
                }
            }
        },
    ) { padding ->
        Box(
            Modifier
                .fillMaxSize()
                .padding(padding),
        ) {
            val month = openMonth
            when {
                month != null -> {
                    val swipeViewModel: SwipeViewModel = viewModel(key = month)
                    val swipeState by swipeViewModel.state.collectAsState()

                    LaunchedEffect(month) { swipeViewModel.load(month) }
                    DisposableEffect(month) {
                        onDispose {
                            monthsViewModel.load()
                            appViewModel.refresh()
                        }
                    }

                    SwipeScreen(
                        state = swipeState,
                        onKeep = swipeViewModel::keep,
                        onDelete = swipeViewModel::delete,
                        onUndo = swipeViewModel::undo,
                        onReviewAgain = swipeViewModel::reviewAgain,
                        onBack = { openMonth = null },
                    )
                }

                tab == Tab.MONTHS -> MonthsScreen(
                    state = monthsState,
                    scanning = appState.scan.running,
                    indexedCount = appState.summary.indexed,
                    onFilter = monthsViewModel::setFilter,
                    onOpen = { openMonth = it },
                    onReopen = monthsViewModel::reopen,
                )

                tab == Tab.REVIEW -> {
                    val reviewViewModel: ReviewViewModel = viewModel()
                    val reviewState by reviewViewModel.state.collectAsState()

                    ReviewScreen(
                        state = reviewState,
                        mode = appState.config.mode,
                        trashAvailable = appState.trashAvailable,
                        onKeepAfterAll = reviewViewModel::keepAfterAll,
                        onApply = {
                            reviewViewModel.apply()
                            appViewModel.refresh()
                        },
                        onRestore = reviewViewModel::restore,
                    )
                }

                else -> SettingsScreen(
                    config = appState.config,
                    trashAvailable = appState.trashAvailable,
                    serverName = appState.serverName,
                    loginName = Graph.accounts.current()?.loginName ?: "",
                    allowScreenshots = allowScreenshots,
                    appLock = appLock,
                    onMode = appViewModel::setMode,
                    onTargetFolder = appViewModel::setTargetFolder,
                    onSkipDecided = appViewModel::setSkipDecided,
                    onAllowScreenshots = { scope.launch { Graph.settings.setAllowScreenshots(it) } },
                    onAppLock = { scope.launch { Graph.settings.setAppLock(it) } },
                    onRebuildIndex = { appViewModel.startScan(full = true) },
                    onSignOut = appViewModel::signOut,
                )
            }
        }
    }
}

@Composable
private fun SignIn(onSignedIn: () -> Unit) {
    val loginViewModel: LoginViewModel = viewModel()
    val state by loginViewModel.state.collectAsState()
    val activity = androidx.compose.ui.platform.LocalContext.current as MainActivity

    LaunchedEffect(state.openUrl) {
        state.openUrl?.let {
            activity.openInBrowser(it)
            loginViewModel.urlOpened()
        }
    }

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }

    LoginScreen(
        state = state,
        onServerUrlChange = loginViewModel::setServerUrl,
        onStart = loginViewModel::start,
        onCancel = loginViewModel::cancel,
    )
}
