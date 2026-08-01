package com.seancheren.suite.app

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.systemBars
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.sp

/**
 * The tab bar — the cousin of ios/App/RootView.swift and the web tab bar: Reminders,
 * Calendar, a middle Add (+), Notes, Habits. Settings is not a tab; it lives behind the
 * top-right gear (see LocalOpenSettings) and opens as an overlay.
 */
private enum class Tab(val label: String, val glyph: String) {
    Reminders("Reminders", "✓"),
    Calendar("Calendar", "▦"),
    Add("Add", "＋"),
    Notes("Notes", "✎"),
    Habits("Habits", "◴"),
}

@Composable
fun RootScreen(vm: SuiteViewModel) {
    var tab by remember { mutableStateOf(Tab.Reminders) }
    var showSettings by remember { mutableStateOf(false) }

    CompositionLocalProvider(LocalOpenSettings provides { showSettings = true }) {
        Box(Modifier.fillMaxSize()) {
            Scaffold(
                containerColor = Bg,
                bottomBar = {
                    NavigationBar(containerColor = Surface) {
                        Tab.values().forEach { t ->
                            NavigationBarItem(
                                selected = tab == t,
                                onClick = { tab = t },
                                icon = { Text(t.glyph, fontSize = 18.sp) },
                                label = { Text(t.label, fontSize = 10.sp) },
                                colors = NavigationBarItemDefaults.colors(
                                    selectedIconColor = Accent,
                                    selectedTextColor = Accent,
                                    unselectedIconColor = Muted,
                                    unselectedTextColor = Muted,
                                    indicatorColor = Surface,
                                ),
                            )
                        }
                    }
                },
            ) { padding ->
                Box(
                    Modifier
                        .padding(padding)
                        .fillMaxSize()
                        .background(Bg),
                ) {
                    when (tab) {
                        Tab.Reminders -> RemindersScreen(vm)
                        Tab.Calendar -> CalendarScreen(vm)
                        Tab.Add -> AddScreen(vm)
                        Tab.Notes -> NotesScreen(vm)
                        Tab.Habits -> HabitsScreen(vm)
                    }
                }
            }

            // Settings opens over everything (incl. the tab bar), like the web's settings modal.
            // It's outside the Scaffold, so apply the system-bar insets itself.
            if (showSettings) {
                Box(
                    Modifier
                        .fillMaxSize()
                        .background(Bg)
                        .windowInsetsPadding(WindowInsets.systemBars),
                ) {
                    SettingsScreen(vm) { showSettings = false }
                }
            }
        }
    }
}
