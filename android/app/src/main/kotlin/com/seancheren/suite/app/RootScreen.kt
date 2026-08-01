package com.seancheren.suite.app

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** The five tabs — the cousin of ios/App/RootView.swift's TabView. */
private enum class Tab(val label: String, val glyph: String) {
    Reminders("Reminders", "✓"),   // ✓
    Calendar("Calendar", "▦"),      // ▦
    Notes("Notes", "✎"),            // ✎
    Habits("Habits", "◴"),          // ◴
    Settings("Settings", "⚙"),      // ⚙
}

@Composable
fun RootScreen(vm: SuiteViewModel) {
    var tab by remember { mutableStateOf(Tab.Reminders) }
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
                Tab.Notes -> NotesScreen(vm)
                Tab.Habits -> HabitsScreen(vm)
                Tab.Settings -> SettingsScreen(vm)
            }
        }
    }
}
