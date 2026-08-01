package com.seancheren.suite.app

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/** Settings — the cousin of ios/App/SettingsView.swift. */
@Composable
fun SettingsScreen(vm: SuiteViewModel) {
    val store = vm.store
    var eraseArmed by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxSize()) {
        TopBar("Settings")
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
        ) {
            Heading("Data")
            Spacer(Modifier.size(8.dp))
            Pill("Load sample data") { store.loadSample() }
            Spacer(Modifier.size(10.dp))
            Pill(if (eraseArmed) "Tap again to erase everything" else "Erase all data") {
                if (eraseArmed) { store.erase(); eraseArmed = false } else eraseArmed = true
            }

            Spacer(Modifier.size(24.dp))
            Heading("About")
            Spacer(Modifier.size(8.dp))
            Text(
                "Seancheren — a local, offline suite. No account, no network, nothing to sign into. " +
                    "A native Android cousin of the web app and the iOS app: the same Reminders, " +
                    "Calendar, Notes and Habits, built to behave identically.",
                color = Muted,
                fontSize = 14.sp,
            )
        }
    }
}

@Composable
private fun Heading(text: String) {
    Text(text, color = Gold, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
}
