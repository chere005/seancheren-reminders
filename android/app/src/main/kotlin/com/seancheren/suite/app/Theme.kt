package com.seancheren.suite.app

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import com.seancheren.suite.core.ItemKind

// The suite's dark theme, matching the web and iOS: #111 background, #eee text,
// #34d399 accent, a real blue (never a cyan) for events. Everything reads from here.

val Bg = Color(0xFF111111)
val Surface = Color(0xFF1B1B1B)
val TextColor = Color(0xFFEEEEEE)
val Muted = Color(0xFF9AA0A6)
val Hairline = Color(0xFF2A2A2A)
val Accent = Color(0xFF34D399)
val OnAccent = Color(0xFF06251B)
val Gold = Color(0xFFD9A441)   // section headings, echoing the web's gold titles

// One palette suite-wide for reminders/events/notes.
val KReminder = Color(0xFF34D399)
val KEvent = Color(0xFF60A5FA)
val KNote = Color(0xFFFBBF24)
val KOverdue = Color(0xFFFB923C)

/**
 * The suite's item palettes — the web's leaned tiers, one per kind: the same six hues
 * (blue, red, green, orange, purple, grey), each kind wearing them at its own
 * unmistakable shade. The values are `app_palette()`'s live output (lib/palette.php),
 * frozen here; folders, calendars and sections store an index into their kind's tier,
 * so an old index just re-hues. The iOS twin is Theme.swift's `Tier`.
 */
enum class Tier { Reminder, Calendar, Note, Habit }

private val ReminderPalette = listOf(   // the vivid anchor
    Color(0xFF4C8BF0), Color(0xFFEA5853), Color(0xFF66D695),
    Color(0xFFF39849), Color(0xFF9E5CE0), Color(0xFF929AAA))
private val CalendarPalette = listOf(   // electric deep
    Color(0xFF0379F6), Color(0xFFED0D10), Color(0xFF2AD05F),
    Color(0xFFFA6800), Color(0xFF803BE7), Color(0xFF677289))
private val NotePalette = listOf(       // sky, leaned back
    Color(0xFF7DC2ED), Color(0xFFE9818A), Color(0xFF8FDB9D),
    Color(0xFFEFA37B), Color(0xFFA088E2), Color(0xFFADB2BD))
private val HabitPalette = listOf(      // full-strength jewel
    Color(0xFF4357EF), Color(0xFFE44525), Color(0xFF3ECB9F),
    Color(0xFFF09A19), Color(0xFFB131D8), Color(0xFF7D8699))

/** The tier a stored kind's colours come from (calendars aren't an ItemKind; they ask
 *  for Tier.Calendar directly). */
fun tier(kind: ItemKind): Tier = when (kind) {
    ItemKind.reminder -> Tier.Reminder
    ItemKind.note -> Tier.Note
    ItemKind.habit -> Tier.Habit
}

fun palette(tier: Tier): List<Color> = when (tier) {
    Tier.Reminder -> ReminderPalette
    Tier.Calendar -> CalendarPalette
    Tier.Note -> NotePalette
    Tier.Habit -> HabitPalette
}

fun paletteColor(index: Int, tier: Tier): Color {
    val p = palette(tier)
    return p[((index % p.size) + p.size) % p.size]
}

private val DarkColors = darkColorScheme(
    primary = Accent,
    onPrimary = OnAccent,
    secondary = KEvent,
    background = Bg,
    onBackground = TextColor,
    surface = Surface,
    onSurface = TextColor,
    surfaceVariant = Surface,
    onSurfaceVariant = Muted,
    outline = Hairline,
)

@Composable
fun SuiteTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = DarkColors,
        typography = Typography(),
        content = content,
    )
}
