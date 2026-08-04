package com.seancheren.suite.app

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import com.seancheren.suite.core.ItemKind

// The suite's page colours, matching the web and iOS. Every screen reads the vals below;
// since the four themes arrived they resolve through the current SuitePageTheme, so
// picking a theme in Settings repaints the whole app on the next recomposition.

/**
 * One of the suite's four page themes — a row of the web's `THEMES` table, frozen.
 * `light` is the web's `THEMES_LIGHT`, flipping the colour scheme so native surfaces
 * follow. The iOS twin is Theme.swift's `SuiteTheme`.
 */
data class SuitePageTheme(
    val name: String,      // the stored key — "midnight" …
    val label: String,
    val bg: Color,
    val surface: Color,
    val text: Color,
    val muted: Color,
    val hairline: Color,
    val accent: Color,
    val onAccent: Color,
    val gold: Color,
    val light: Boolean,
)

/** The four suite themes in the picker's order; midnight is the untouched default. */
val suitePageThemes = listOf(
    SuitePageTheme("midnight", "Midnight",
        Color(0xFF111111), Color(0xFF1B1B1B), Color(0xFFEEEEEE), Color(0xFF9AA0A6),
        Color(0xFF2A2A2A), Color(0xFF34D399), Color(0xFF06251B), Color(0xFFD9A441), light = false),
    SuitePageTheme("sage", "Sage & Cream",
        Color(0xFFFEFAE0), Color(0xFFFAEDCD), Color(0xFF3F3A2E), Color(0xFF776E56),
        Color(0xFFCCD5AE), Color(0xFF96632F), Color(0xFFFEFAE0), Color(0xFF8A5A12), light = true),
    SuitePageTheme("forest", "Forest",
        Color(0xFF040303), Color(0xFF16201D), Color(0xFFE4DDD6), Color(0xFF6A7B76),
        Color(0xFF3A4E48), Color(0xFF8B9D83), Color(0xFF0A0F0D), Color(0xFFC9A227), light = false),
    SuitePageTheme("olive", "Olive & Slate",
        Color(0xFF241E2D), Color(0xFF332A3E), Color(0xFFEAF0CE), Color(0xFF848B98),
        Color(0xFF564A62), Color(0xFFBBBE64), Color(0xFF241E2D), Color(0xFFD8C46A), light = false),
)

/** The theme a stored name means — an unknown name falls back to midnight, like the web. */
fun suitePageTheme(name: String): SuitePageTheme =
    suitePageThemes.firstOrNull { it.name == name } ?: suitePageThemes[0]

/** The theme currently worn, set by SuiteTheme() before anything reads the vals below. */
private var currentTheme: SuitePageTheme = suitePageThemes[0]

val Bg: Color get() = currentTheme.bg
val Surface: Color get() = currentTheme.surface
val TextColor: Color get() = currentTheme.text
val Muted: Color get() = currentTheme.muted
val Hairline: Color get() = currentTheme.hairline
val Accent: Color get() = currentTheme.accent
val OnAccent: Color get() = currentTheme.onAccent
val Gold: Color get() = currentTheme.gold   // section headings, echoing the web's gold titles

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

@Composable
fun SuiteTheme(themeName: String = "midnight", content: @Composable () -> Unit) {
    val t = suitePageTheme(themeName)
    currentTheme = t
    val scheme = (if (t.light) lightColorScheme(
        primary = t.accent, onPrimary = t.onAccent, secondary = KEvent,
        background = t.bg, onBackground = t.text, surface = t.surface, onSurface = t.text,
        surfaceVariant = t.surface, onSurfaceVariant = t.muted, outline = t.hairline,
    ) else darkColorScheme(
        primary = t.accent, onPrimary = t.onAccent, secondary = KEvent,
        background = t.bg, onBackground = t.text, surface = t.surface, onSurface = t.text,
        surfaceVariant = t.surface, onSurfaceVariant = t.muted, outline = t.hairline,
    ))
    MaterialTheme(
        colorScheme = scheme,
        typography = Typography(),
        content = content,
    )
}
