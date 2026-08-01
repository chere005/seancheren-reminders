package com.seancheren.suite.app

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.seancheren.suite.core.Store
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/**
 * Bridges the framework-free core `Store` to Compose (the `@Published` half of the iOS
 * Store, split off so the logic layer stays UI-free).
 *
 * A screen reads `rev` once to subscribe, then queries `store.…`; after any mutation the
 * store calls `onChange`, which bumps `rev` (recompose) and debounces the atomic save —
 * so typing a title doesn't rewrite the file per keystroke. This is where a Wear push
 * would also hang off, exactly as PhoneConnectivity does on iOS.
 */
class SuiteViewModel(app: Application) : AndroidViewModel(app) {

    val store: Store
    var rev by mutableStateOf(0L)
        private set

    private var saveJob: Job? = null

    init {
        val file = File(app.filesDir, "suite.json")
        store = Store(file, firstRunSample = true)
        store.onChange {
            rev++                                        // trigger recomposition
            saveJob?.cancel()                            // debounce the disk write
            saveJob = viewModelScope.launch {
                delay(400)
                withContext(Dispatchers.IO) { store.save() }
                // pushToWatch()  // future: hand the WatchList to Wear via the Data Layer
            }
        }
    }
}
