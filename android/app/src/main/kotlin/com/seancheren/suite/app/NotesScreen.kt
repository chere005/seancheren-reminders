package com.seancheren.suite.app

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.seancheren.suite.core.ItemKind
import com.seancheren.suite.core.Note
import java.util.UUID

/** Notes (plain text, like the iOS app) — the cousin of ios/App/NotesView.swift. */
@Composable
fun NotesScreen(vm: SuiteViewModel) {
    val store = vm.store
    var folderSel by remember { mutableStateOf(store.data.lastFolder[ItemKind.note.name]) }
    var folderMenu by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<UUID?>(null) }

    val editingNote = store.data.notes.firstOrNull { it.id == editing }
    if (editing != null && editingNote != null) {
        NoteEditor(editingNote, onBack = { editing = null }, onChange = { store.update(it) })
        return
    }

    val notes = remember(vm.rev, folderSel) {
        store.data.notes.filter { folderSel == null || it.folder == folderSel }.sortedBy { it.order }
    }

    Column(Modifier.fillMaxSize()) {
        TopBar(store.folderName(folderSel)) {
            Pill("+ Note", primary = true) {
                val folder = store.target(ItemKind.note, folderSel)
                store.add(Note(folder = folder))
                editing = store.data.notes.lastOrNull()?.id
            }
            Spacer(Modifier.size(8.dp))
            androidx.compose.foundation.layout.Box {
                Pill("Folders") { folderMenu = true }
                DropdownMenu(expanded = folderMenu, onDismissRequest = { folderMenu = false }) {
                    DropdownMenuItem(text = { Text("All") }, onClick = { folderSel = null; folderMenu = false })
                    for (f in store.data.folderList(ItemKind.note)) {
                        DropdownMenuItem(
                            text = { Text(f.name) },
                            leadingIcon = { Swatch(paletteColor(f.color)) },
                            onClick = { folderSel = f.id; folderMenu = false },
                        )
                    }
                }
            }
        }

        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState()),
        ) {
            if (notes.isEmpty()) {
                Text("No notes yet.", color = Muted, fontSize = 14.sp, modifier = Modifier.padding(16.dp))
            }
            for (n in notes) {
                SwipeToDelete(onDelete = { store.delete(n) }) {
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .background(Bg)
                            .clickable { editing = n.id }
                            .padding(start = 16.dp, end = 12.dp, top = 12.dp, bottom = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(n.title.ifBlank { "Untitled" }, color = TextColor, fontSize = 16.sp, fontWeight = FontWeight.Medium)
                            val snippet = n.body.replace("\n", " ").trim()
                            if (snippet.isNotEmpty()) {
                                Text(snippet.take(80), color = Muted, fontSize = 13.sp, maxLines = 1)
                            }
                        }
                    }
                }
                HorizontalDivider(color = Hairline)
            }
            Spacer(Modifier.size(48.dp))
        }
    }
}

@Composable
private fun NoteEditor(note: Note, onBack: () -> Unit, onChange: (Note) -> Unit) {
    var title by remember(note.id) { mutableStateOf(note.title) }
    var body by remember(note.id) { mutableStateOf(note.body) }

    Column(Modifier.fillMaxSize().background(Bg)) {
        TopBar("Note") {
            Pill("Done", primary = true) { onBack() }
        }
        OutlinedTextField(
            value = title,
            onValueChange = { title = it; onChange(note.copy(title = it, body = body)) },
            placeholder = { Text("Title", color = Muted) },
            singleLine = true,
            textStyle = LocalTextStyle.current.copy(color = TextColor, fontSize = 18.sp, fontWeight = FontWeight.SemiBold),
            colors = OutlinedTextFieldDefaults.colors(
                focusedBorderColor = Accent,
                unfocusedBorderColor = Hairline,
                cursorColor = Accent,
            ),
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 12.dp, vertical = 8.dp),
        )
        OutlinedTextField(
            value = body,
            onValueChange = { body = it; onChange(note.copy(title = title, body = it)) },
            placeholder = { Text("Write…", color = Muted) },
            textStyle = LocalTextStyle.current.copy(color = TextColor, fontSize = 16.sp),
            colors = OutlinedTextFieldDefaults.colors(
                focusedBorderColor = Accent,
                unfocusedBorderColor = Hairline,
                cursorColor = Accent,
            ),
            modifier = Modifier
                .fillMaxWidth()
                .weight(1f)
                .padding(horizontal = 12.dp, vertical = 4.dp),
        )
    }
}
