package com.seancheren.suite.app

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.seancheren.suite.core.Event
import com.seancheren.suite.core.GroupRef
import com.seancheren.suite.core.ItemKind
import com.seancheren.suite.core.Note
import com.seancheren.suite.core.Reminder
import com.seancheren.suite.core.Store
import com.seancheren.suite.core.parseWhen
import java.time.LocalDate

private enum class AddType(val label: String) { Reminder("Reminder"), Event("Event"), Note("Note") }

/** The Add app — the tab bar's middle +, the cousin of public/add/index.php. */
@Composable
fun AddScreen(vm: SuiteViewModel) {
    val store = vm.store
    var text by remember { mutableStateOf("") }
    var type by remember { mutableStateOf(AddType.Reminder) }
    var folder by remember { mutableStateOf(store.data.defaultFolder[ItemKind.reminder.name]) }
    var noteFolder by remember { mutableStateOf(store.data.defaultFolder[ItemKind.note.name]) }
    var section by remember { mutableStateOf<GroupRef>(GroupRef.Inbox) }
    var cal by remember { mutableStateOf(store.data.defaultCal) }
    var justAdded by remember { mutableStateOf(false) }
    var folderMenu by remember { mutableStateOf(false) }
    var sectionMenu by remember { mutableStateOf(false) }
    var calMenu by remember { mutableStateOf(false) }

    fun submit() {
        val raw = text.trim()
        if (raw.isEmpty()) return
        when (type) {
            AddType.Reminder -> {
                val p = parseWhen(raw)
                store.add(Reminder(text = p.text, due = p.date, minutes = p.minutes,
                    folder = store.target(ItemKind.reminder, folder), group = section))
            }
            AddType.Note -> store.add(Note(title = raw, folder = store.target(ItemKind.note, noteFolder)))
            AddType.Event -> {
                val p = parseWhen(raw)
                store.add(Event(text = p.text, date = p.date ?: LocalDate.now(), minutes = p.minutes,
                    cal = cal ?: store.data.defaultCal))
            }
        }
        text = ""
        justAdded = true
    }

    Column(Modifier.fillMaxSize()) {
        TopBar("Add")
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
        ) {
            OutlinedTextField(
                value = text,
                onValueChange = { text = it; justAdded = false },
                placeholder = { Text(placeholderFor(type), color = Muted) },
                textStyle = LocalTextStyle.current.copy(color = TextColor, fontSize = 16.sp),
                colors = OutlinedTextFieldDefaults.colors(
                    focusedBorderColor = Accent,
                    unfocusedBorderColor = Hairline,
                    cursorColor = Accent,
                ),
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.size(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                for (t in AddType.values()) {
                    val sel = type == t
                    Text(
                        t.label,
                        color = if (sel) OnAccent else TextColor,
                        fontSize = 14.sp,
                        modifier = Modifier
                            .clip(RoundedCornerShape(999.dp))
                            .then(if (sel) Modifier.background(Accent) else Modifier.border(1.dp, Hairline, RoundedCornerShape(999.dp)))
                            .clickable { type = t; justAdded = false }
                            .padding(horizontal = 14.dp, vertical = 7.dp),
                    )
                }
            }

            Spacer(Modifier.size(18.dp))
            Text("Where", color = Gold, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.size(8.dp))
            when (type) {
                AddType.Reminder -> {
                    Selector("Folder", store.folderName(folder), folderMenu, { folderMenu = true }, { folderMenu = false }) {
                        for (f in store.data.folderList(ItemKind.reminder)) {
                            DropdownMenuItem(text = { Text(f.name) }, leadingIcon = { Swatch(paletteColor(f.color, Tier.Reminder)) },
                                onClick = { folder = f.id; folderMenu = false })
                        }
                    }
                    Spacer(Modifier.size(8.dp))
                    Selector("Section", sectionLabel(store, section), sectionMenu, { sectionMenu = true }, { sectionMenu = false }) {
                        DropdownMenuItem(text = { Text("Calendar") }, onClick = { section = GroupRef.Calendar; sectionMenu = false })
                        DropdownMenuItem(text = { Text("Reminders") }, onClick = { section = GroupRef.Inbox; sectionMenu = false })
                        for (g in store.data.groupList(ItemKind.reminder)) {
                            DropdownMenuItem(text = { Text(g.name) }, leadingIcon = { Swatch(paletteColor(g.color, Tier.Reminder)) },
                                onClick = { section = GroupRef.Group(g.id); sectionMenu = false })
                        }
                    }
                }
                AddType.Note -> {
                    Selector("Folder", store.folderName(noteFolder), folderMenu, { folderMenu = true }, { folderMenu = false }) {
                        for (f in store.data.folderList(ItemKind.note)) {
                            DropdownMenuItem(text = { Text(f.name) }, leadingIcon = { Swatch(paletteColor(f.color, Tier.Note)) },
                                onClick = { noteFolder = f.id; folderMenu = false })
                        }
                    }
                }
                AddType.Event -> {
                    Selector("Calendar", store.data.cal(cal ?: store.data.defaultCal)?.name ?: "—", calMenu, { calMenu = true }, { calMenu = false }) {
                        for (c in store.calendarsOnly) {
                            DropdownMenuItem(text = { Text(c.name) }, leadingIcon = { Swatch(paletteColor(c.color, Tier.Calendar)) },
                                onClick = { cal = c.id; calMenu = false })
                        }
                    }
                }
            }

            Spacer(Modifier.size(20.dp))
            Pill("Add ${type.label}", primary = true) { submit() }
            if (justAdded) {
                Spacer(Modifier.size(10.dp))
                Text("Added ✓", color = Accent, fontSize = 13.sp)
            }
        }
    }
}

@Composable
private fun Selector(
    label: String,
    value: String,
    open: Boolean,
    onOpen: () -> Unit,
    onDismiss: () -> Unit,
    menu: @Composable () -> Unit,
) {
    Box {
        Row(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(10.dp))
                .border(1.dp, Hairline, RoundedCornerShape(10.dp))
                .clickable { onOpen() }
                .padding(horizontal = 12.dp, vertical = 11.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(label, color = Muted, fontSize = 13.sp, modifier = Modifier.width(80.dp))
            Text(value, color = TextColor, fontSize = 15.sp, modifier = Modifier.weight(1f))
            Text("▾", color = Muted, fontSize = 12.sp)
        }
        DropdownMenu(expanded = open, onDismissRequest = onDismiss) { menu() }
    }
}

private fun placeholderFor(type: AddType): String = when (type) {
    AddType.Reminder -> "New reminder — e.g. Vet 8/3 2pm"
    AddType.Event -> "New event"
    AddType.Note -> "New note"
}

private fun sectionLabel(store: Store, ref: GroupRef): String = when (ref) {
    GroupRef.Calendar -> "Calendar"
    GroupRef.Inbox -> "Reminders"
    is GroupRef.Group -> store.data.groups.firstOrNull { it.id == ref.id }?.name ?: "Reminders"
}
