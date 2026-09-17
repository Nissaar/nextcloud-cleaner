package xyz.photosweep.ui

import java.time.Instant
import java.time.YearMonth
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * Built per call rather than held in a field. `Locale.getDefault()` is read once when
 * a field is initialised, so a formatter cached here would keep rendering month names
 * in whatever language the app started in, even after the user changes the system
 * language and comes back.
 */
private fun monthFormatter() = DateTimeFormatter.ofPattern("LLLL yyyy", Locale.getDefault())

private fun dayFormatter() = DateTimeFormatter.ofPattern("d MMM yyyy", Locale.getDefault())

/** "2024-07" as a month a person would read. */
fun monthLabel(yearMonth: String): String = try {
    YearMonth.parse(yearMonth).format(monthFormatter())
} catch (e: Exception) {
    yearMonth
}

fun dateLabel(epochSeconds: Long, zone: ZoneId = ZoneId.systemDefault()): String =
    if (epochSeconds <= 0L) {
        ""
    } else {
        Instant.ofEpochSecond(epochSeconds).atZone(zone).format(dayFormatter())
    }

fun sizeLabel(bytes: Long): String {
    if (bytes <= 0) return ""
    val units = listOf("B", "KB", "MB", "GB", "TB")
    var value = bytes.toDouble()
    var unit = 0
    while (value >= 1024 && unit < units.lastIndex) {
        value /= 1024
        unit++
    }
    return if (value < 10 && unit > 0) {
        String.format(Locale.getDefault(), "%.1f %s", value, units[unit])
    } else {
        String.format(Locale.getDefault(), "%.0f %s", value, units[unit])
    }
}

/**
 * Explains where a photo's date came from.
 *
 * Shown because a photo landing in an unexpected month is confusing right up until you
 * can see that the app only had a modification time to go on.
 */
fun dateSourceLabel(source: String): String = when (source) {
    "exif" -> "Date taken, from the photo itself"
    "filename" -> "Date read from the file name"
    "upload" -> "Date this file reached the server"
    else -> "Date the file was last changed — no capture date was available"
}
