package io.github.nissaar.photosweep.ui

import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import io.github.nissaar.photosweep.api.MonthEntry
import io.github.nissaar.photosweep.vm.MonthFilter
import io.github.nissaar.photosweep.vm.MonthsState

@OptIn(ExperimentalFoundationApi::class)
@Composable
fun MonthsScreen(
    state: MonthsState,
    scanning: Boolean,
    indexedCount: Int,
    onFilter: (MonthFilter) -> Unit,
    onOpen: (String) -> Unit,
    onReopen: (String) -> Unit,
) {
    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(horizontal = 16.dp)) {
            Text(
                subtitle(state, scanning, indexedCount),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(12.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                MonthFilter.entries.forEach { filter ->
                    FilterChip(
                        selected = state.filter == filter,
                        onClick = { onFilter(filter) },
                        label = {
                            Text(
                                when (filter) {
                                    MonthFilter.TO_REVIEW -> "${filter.label} (${state.toReviewCount})"
                                    MonthFilter.DONE -> "${filter.label} (${state.doneCount})"
                                    MonthFilter.ALL -> filter.label
                                },
                            )
                        },
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
        }

        when {
            state.loading -> Centre { CircularProgressIndicator() }

            state.visible.isEmpty() -> Centre {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(emptyTitle(state, scanning), style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(4.dp))
                    Text(
                        emptyBody(state, scanning),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            else -> LazyVerticalGrid(
                columns = GridCells.Adaptive(minSize = 160.dp),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(state.visible, key = { it.month }) { month ->
                    MonthTile(
                        month = month,
                        onClick = { onOpen(month.month) },
                        // A long press is how a finished month is put back into play.
                        // Deliberately not a visible button: reopening a month is a
                        // rare, deliberate act, and a tappable target on every tile
                        // would be hit by accident far more often than on purpose.
                        onLongClick = { onReopen(month.month) },
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MonthTile(month: MonthEntry, onClick: () -> Unit, onLongClick: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .combinedClickable(onClick = onClick, onLongClick = onLongClick),
    ) {
        Column(Modifier.padding(16.dp)) {
            Text(
                monthLabel(month.month),
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
            )
            Spacer(Modifier.height(4.dp))
            Text(
                if (month.done) {
                    "All ${month.total} reviewed"
                } else {
                    "${month.remaining} of ${month.total} left"
                },
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(10.dp))
            LinearProgressIndicator(
                progress = {
                    if (month.total == 0) 0f else month.reviewed.toFloat() / month.total
                },
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun Centre(content: @Composable () -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .padding(32.dp),
        contentAlignment = Alignment.Center,
    ) { content() }
}

private fun subtitle(state: MonthsState, scanning: Boolean, indexed: Int): String = when {
    indexed == 0 && scanning -> "Reading your library…"
    indexed == 0 -> "No photos indexed yet"
    else -> "${state.summary.photosLeft} photos still to go through, across ${state.toReviewCount} months"
}

private fun emptyTitle(state: MonthsState, scanning: Boolean): String = when {
    scanning -> "Still reading your library"
    state.filter == MonthFilter.TO_REVIEW && state.months.isNotEmpty() -> "Every month is done"
    else -> "Nothing here yet"
}

private fun emptyBody(state: MonthsState, scanning: Boolean): String = when {
    scanning -> "Months appear as they are found."
    state.filter == MonthFilter.TO_REVIEW && state.months.isNotEmpty() ->
        "Switch to All, then long-press a month to go back over it."
    else -> "Once your photos have been indexed, the months they were taken in show up here."
}
