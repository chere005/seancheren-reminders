package com.seancheren.suite.app

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

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

/** Folder/section swatch palette, indexed by the stored `color` (mod length). */
private val Palette = listOf(
    Color(0xFF60A5FA), // blue
    Color(0xFFF87171), // red
    Color(0xFF34D399), // green
    Color(0xFFFB923C), // orange
    Color(0xFFA78BFA), // purple
    Color(0xFF9CA3AF), // grey
    Color(0xFF22D3EE), // cyan
    Color(0xFFF472B6), // pink
    Color(0xFF4ADE80), // light green
    Color(0xFFFBBF24), // amber
)

fun paletteColor(index: Int): Color = Palette[((index % Palette.size) + Palette.size) % Palette.size]

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
