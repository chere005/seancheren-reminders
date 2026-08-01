package com.seancheren.suite.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.lifecycle.viewmodel.compose.viewModel

/**
 * The one activity. No web view, no login, no network — a native Compose shell over the
 * local `Store`, the cousin of ios/App/SeancherenApp.swift.
 */
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContent {
            SuiteTheme {
                val vm: SuiteViewModel = viewModel()
                RootScreen(vm)
            }
        }
    }
}
