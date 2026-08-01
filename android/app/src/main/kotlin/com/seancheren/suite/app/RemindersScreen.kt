package com.seancheren.suite.app

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
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
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.seancheren.suite.core.GroupRef
import com.seancheren.suite.core.ItemKind
import com.seancheren.suite.core.Reminder
import com.seancheren.suite.core.dayLabel
import com.seancheren.suite.core.parseWhen
import com.seancheren.suite.core.timeLabel
import java.time.LocalDate
import java.util.UUID

/** Reminders — the cousin of ios/App/RemindersView.swift and public/reminders/. */
@Composable
fun RemindersScreen(vm: SuiteViewModel) {
    val store = vm.store
    var folderSel by remember { mutableStateOf(store.data.lastFolder[ItemKind.reminder.name]) }
    var folderMenu by remember { mutableStateOf(false) }
    var addingKey by remember { mutableStateOf<String?>(null) }
    var addingText by remember { mutableStateOf("") }
    var editingId by remember { mutableStateOf<UUID?>(null) }
    var editText by remember { mutableStateOf("") }
    var addingSection by remember { mutableStateOf(false) }
    var sectionName by remember { mutableStateOf("") }

    // Recomputed on any store change (keyed on rev) or a folder switch.
    val sections = remember(vm.rev, folderSel) {
        val list = ArrayList<ReminderSection>()
        list.add(ReminderSection(GroupRef.Calendar, "Calendar", KEvent, store.reminders(folderSel, GroupRef.Calendar)))
        list.add(ReminderSection(GroupRef.Inbox, "Reminders", Accent, store.reminders(folderSel, GroupRef.Inbox)))
        for (g in store.data.groupList(ItemKind.reminder)) {
            list.add(ReminderSection(GroupRef.Group(g.id), g.name, paletteColor(g.color), store.reminders(folderSel, GroupRef.Group(g.id))))
        }
        list
    }

    fun chooseFolder(f: UUID?) {
        folderSel = f
        if (f != null) store.data.lastFolder[ItemKind.reminder.name] = f
        else store.data.lastFolder.remove(ItemKind.reminder.name)
        store.touch()
    }

    fun submitAdd(ref: GroupRef) {
        val p = parseWhen(addingText)
        if (p.text.isNotBlank()) {
            val folder = store.target(ItemKind.reminder, folderSel)
            store.add(Reminder(text = p.text, due = p.date, minutes = p.minutes, folder = folder, group = ref))
        }
        addingText = ""
        addingKey = null
    }

    fun commitEdit() {
        val id = editingId ?: return
        val row = store.data.reminders.firstOrNull { it.id == id }
        if (row != null) {
            val p = parseWhen(editText)
            // Retyping re-parses date/time (like the web); a bare edit keeps the old ones.
            if (p.text.isNotBlank()) {
                store.update(row.copy(text = p.text, due = p.date ?: row.due, minutes = p.minutes ?: row.minutes))
            }
        }
        editingId = null
    }

    Column(Modifier.fillMaxSize()) {
        TopBar(store.folderName(folderSel)) {
            Box {
                Pill("Folders") { folderMenu = true }
                DropdownMenu(expanded = folderMenu, onDismissRequest = { folderMenu = false }) {
                    DropdownMenuItem(text = { Text("All") }, onClick = { chooseFolder(null); folderMenu = false })
                    for (f in store.data.folderList(ItemKind.reminder)) {
                        DropdownMenuItem(
                            text = { Text(f.name) },
                            leadingIcon = { Swatch(paletteColor(f.color)) },
                            onClick = { chooseFolder(f.id); folderMenu = false },
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
            for (sec in sections) {
                val key = keyOf(sec.ref)
                val permanent = sec.ref == GroupRef.Calendar || sec.ref == GroupRef.Inbox
                if (sec.rows.isEmpty() && !permanent && addingKey != key) continue
                SectionTitle(sec.title, sec.color) {
                    Spacer(Modifier.weight(1f))
                    Text(
                        "+",
                        color = Accent,
                        fontSize = 20.sp,
                        modifier = Modifier
                            .clip(CircleShape)
                            .clickable { addingKey = key; addingText = "" }
                            .padding(horizontal = 8.dp),
                    )
                }
                if (addingKey == key) {
                    AddField(addingText, { addingText = it }, { submitAdd(sec.ref) }, "New reminder…")
                }
                for (row in sec.rows) {
                    ReminderRow(
                        row = row,
                        editing = editingId == row.id,
                        editText = editText,
                        onToggle = { store.toggle(row) },
                        onLongPress = { editingId = row.id; editText = row.text },
                        onEditChange = { editText = it },
                        onCommit = { commitEdit() },
                        onDelete = { store.delete(row) },
                    )
                }
            }

            Spacer(Modifier.size(8.dp))
            if (addingSection) {
                AddField(sectionName, { sectionName = it }, {
                    store.addGroup(sectionName, ItemKind.reminder)
                    sectionName = ""; addingSection = false
                }, "Section name…")
            } else {
                Text(
                    "+ Section",
                    color = Muted,
                    fontSize = 14.sp,
                    modifier = Modifier
                        .padding(16.dp)
                        .clickable { addingSection = true },
                )
            }
            Spacer(Modifier.size(48.dp))
        }
    }
}

private data class ReminderSection(val ref: GroupRef, val title: String, val color: Color, val rows: List<Reminder>)

private fun keyOf(ref: GroupRef): String = when (ref) {
    GroupRef.Inbox -> "inbox"
    GroupRef.Calendar -> "calendar"
    is GroupRef.Group -> ref.id.toString()
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun ReminderRow(
    row: Reminder,
    editing: Boolean,
    editText: String,
    onToggle: () -> Unit,
    onLongPress: () -> Unit,
    onEditChange: (String) -> Unit,
    onCommit: () -> Unit,
    onDelete: () -> Unit,
) {
    val today = LocalDate.now()
    // Swipe left to delete; long-press the text to edit it inline (Enter commits).
    SwipeToDelete(onDelete = onDelete) {
        Row(
            Modifier
                .fillMaxWidth()
                .background(Bg)
                .padding(start = (16 + row.indent * 20).dp, end = 12.dp, top = 8.dp, bottom = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier
                    .size(22.dp)
                    .clip(CircleShape)
                    .border(2.dp, if (row.done) Accent else Muted, CircleShape)
                    .background(if (row.done) Accent else Color.Transparent)
                    .clickable { onToggle() },
                contentAlignment = Alignment.Center,
            ) {
                if (row.done) Text("✓", color = OnAccent, fontSize = 13.sp)
            }
            Spacer(Modifier.size(10.dp))
            if (editing) {
                OutlinedTextField(
                    value = editText,
                    onValueChange = onEditChange,
                    singleLine = true,
                    textStyle = LocalTextStyle.current.copy(color = TextColor, fontSize = 16.sp),
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
                    keyboardActions = KeyboardActions(onDone = { onCommit() }),
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedBorderColor = Accent,
                        unfocusedBorderColor = Hairline,
                        cursorColor = Accent,
                    ),
                    modifier = Modifier.weight(1f),
                )
            } else {
                Column(
                    Modifier
                        .weight(1f)
                        .combinedClickable(onClick = {}, onLongClick = onLongPress),
                ) {
                    Text(
                        row.text.ifBlank { "—" },
                        color = if (row.done) Muted else TextColor,
                        fontSize = 16.sp,
                    )
                    val meta = ArrayList<String>()
                    row.due?.let { meta.add(dayLabel(it, today)) }
                    row.minutes?.let { meta.add(timeLabel(it)) }
                    row.recurrence?.let { meta.add(it.label) }
                    if (meta.isNotEmpty()) {
                        Text(
                            meta.joinToString(" · "),
                            color = if (row.overdue(today)) KOverdue else Muted,
                            fontSize = 12.sp,
                        )
                    }
                }
            }
        }
    }
}

@Composable
fun AddField(value: String, onValue: (String) -> Unit, onSubmit: () -> Unit, placeholder: String) {
    OutlinedTextField(
        value = value,
        onValueChange = onValue,
        placeholder = { Text(placeholder, color = Muted) },
        singleLine = true,
        textStyle = LocalTextStyle.current.copy(color = TextColor, fontSize = 16.sp),
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
        keyboardActions = KeyboardActions(onDone = { onSubmit() }),
        colors = OutlinedTextFieldDefaults.colors(
            focusedBorderColor = Accent,
            unfocusedBorderColor = Hairline,
            cursorColor = Accent,
        ),
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 4.dp),
    )
}
