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
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.Canvas
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import java.util.UUID
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.seancheren.suite.core.Event
import com.seancheren.suite.core.GroupRef
import com.seancheren.suite.core.ItemKind
import com.seancheren.suite.core.Note
import com.seancheren.suite.core.Reminder
import com.seancheren.suite.core.parseWhen
import com.seancheren.suite.core.timeLabel
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.TextStyle
import java.util.Locale

/** Calendar — the cousin of ios/App/CalendarView.swift and public/calendar/. */
@Composable
fun CalendarScreen(vm: SuiteViewModel) {
    val store = vm.store
    val today = LocalDate.now()
    var month by remember { mutableStateOf(today.withDayOfMonth(1)) }
    var selected by remember { mutableStateOf(today) }
    var adding by remember { mutableStateOf(false) }
    var addText by remember { mutableStateOf("") }
    var dayAddType by remember { mutableStateOf(DayAddKind.Reminder) }

    // Read rev so the grid and panel refresh after any change.
    val revKey = vm.rev

    Column(Modifier.fillMaxSize()) {
        TopBar("Calendar")

        // Month header with ‹ › navigation.
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("‹", color = Accent, fontSize = 22.sp,
                modifier = Modifier.clip(CircleShape).clickable { month = month.minusMonths(1) }.padding(horizontal = 10.dp))
            Text(
                "${month.month.getDisplayName(TextStyle.FULL, Locale.US)} ${month.year}",
                color = TextColor, fontSize = 17.sp, fontWeight = FontWeight.SemiBold,
                textAlign = TextAlign.Center, modifier = Modifier.weight(1f),
            )
            Text("›", color = Accent, fontSize = 22.sp,
                modifier = Modifier.clip(CircleShape).clickable { month = month.plusMonths(1) }.padding(horizontal = 10.dp))
        }

        // Weekday labels, Sunday-first like the suite.
        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp)) {
            for (w in listOf("S", "M", "T", "W", "T", "F", "S")) {
                Text(w, color = Muted, fontSize = 11.sp, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
            }
        }

        // The grid.
        val monthStart = month.withDayOfMonth(1)
        val daysInMonth = YearMonth.from(monthStart).lengthOfMonth()
        val firstCol = monthStart.dayOfWeek.value % 7          // Sunday → 0
        val cells = remember(revKey, month) {
            val list = ArrayList<LocalDate?>()
            repeat(firstCol) { list.add(null) }
            for (d in 1..daysInMonth) list.add(monthStart.withDayOfMonth(d))
            while (list.size % 7 != 0) list.add(null)
            list
        }
        Column(Modifier.fillMaxWidth().padding(horizontal = 6.dp)) {
            for (week in cells.chunked(7)) {
                Row(Modifier.fillMaxWidth()) {
                    for (day in week) {
                        // The legend's kind icons, at most one of each — the icon says which
                        // kinds the day holds, its colour whose calendar or folder; the panel
                        // has the detail. The reminder icon takes the worst reminder's folder
                        // colour (overdue beats open), the same pick the web makes.
                        DayCell(
                            day = day,
                            isToday = day == today,
                            isSelected = day == selected,
                            eventColor = day?.let { d -> store.events(d).firstOrNull()?.let { e ->
                                paletteColor(store.data.cal(e.cal)?.color ?: 0, Tier.Calendar) } },
                            reminderColor = day?.let { d ->
                                val rows = store.reminders(d, today)
                                val worst = rows.firstOrNull { it.overdue(today) } ?: rows.firstOrNull()
                                worst?.let { paletteColor(folderColorIndex(store, it.folder), Tier.Reminder) } },
                            noteColor = day?.let { d -> store.notes(d).firstOrNull()?.let { n ->
                                paletteColor(folderColorIndex(store, n.folder), Tier.Note) } },
                            modifier = Modifier.weight(1f),
                            onClick = { if (day != null) selected = day },
                        )
                    }
                }
            }
        }

        HorizontalDivider(color = Hairline, modifier = Modifier.padding(top = 8.dp))

        // Day panel for the selected day.
        Column(
            Modifier
                .weight(1f)
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
        ) {
            val events = store.events(selected)
            val reminders = store.reminders(selected, today)
            val notes = store.notes(selected)

            // Date, with the add button to its right.
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    selected.format(java.time.format.DateTimeFormatter.ofPattern("EEEE, MMM d", Locale.US)),
                    color = TextColor, fontSize = 16.sp, fontWeight = FontWeight.SemiBold,
                )
                Spacer(Modifier.weight(1f))
                Text(
                    if (adding) "Cancel" else "+ Add",
                    color = Accent, fontSize = 14.sp, fontWeight = FontWeight.SemiBold,
                    modifier = Modifier
                        .clip(RoundedCornerShape(999.dp))
                        .clickable { adding = !adding; addText = "" }
                        .padding(horizontal = 12.dp, vertical = 6.dp),
                )
            }
            if (adding) {
                Spacer(Modifier.size(6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    for (k in DayAddKind.values()) {
                        val sel = dayAddType == k
                        Text(
                            k.name,
                            color = if (sel) OnAccent else Muted,
                            fontSize = 12.sp,
                            modifier = Modifier
                                .clip(RoundedCornerShape(999.dp))
                                .then(if (sel) Modifier.background(Accent) else Modifier.border(1.dp, Hairline, RoundedCornerShape(999.dp)))
                                .clickable { dayAddType = k }
                                .padding(horizontal = 10.dp, vertical = 4.dp),
                        )
                    }
                }
                Spacer(Modifier.size(6.dp))
                AddField(addText, { addText = it }, {
                    val p = parseWhen(addText)
                    if (p.text.isNotBlank()) {
                        when (dayAddType) {
                            DayAddKind.Reminder -> store.add(Reminder(text = p.text, due = p.date ?: selected, minutes = p.minutes,
                                folder = store.target(ItemKind.reminder, null), group = GroupRef.Inbox))
                            DayAddKind.Event -> store.add(Event(text = p.text, date = p.date ?: selected, minutes = p.minutes,
                                cal = store.data.defaultCal))
                            DayAddKind.Note -> store.add(Note(title = addText.trim(), date = selected,
                                folder = store.target(ItemKind.note, null)))
                        }
                    }
                    addText = ""; adding = false
                }, "Add to this day…")
            }
            Spacer(Modifier.size(8.dp))

            if (events.isEmpty() && reminders.isEmpty() && notes.isEmpty()) {
                Text("Nothing on.", color = Muted, fontSize = 14.sp)
            }

            DayGroup("Events", events.size, vm.flag("cal.grp.Events"), { vm.setFlag("cal.grp.Events", !vm.flag("cal.grp.Events")) }) {
                for (e in events) PanelRow(dot = KEvent, text = e.text, meta = e.minutes?.let { timeLabel(it) })
            }
            DayGroup("Reminders", reminders.size, vm.flag("cal.grp.Reminders"), { vm.setFlag("cal.grp.Reminders", !vm.flag("cal.grp.Reminders")) }) {
                for (r in reminders) {
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            Modifier.size(20.dp).clip(CircleShape)
                                .border(2.dp, KReminder, CircleShape)
                                .clickable { store.toggle(r) },
                        )
                        Spacer(Modifier.size(10.dp))
                        Text(r.text, color = TextColor, fontSize = 15.sp)
                    }
                }
            }
            DayGroup("Notes", notes.size, vm.flag("cal.grp.Notes"), { vm.setFlag("cal.grp.Notes", !vm.flag("cal.grp.Notes")) }) {
                for (n in notes) PanelRow(dot = KNote, text = n.title.ifBlank { "Untitled" }, meta = null)
            }
            Spacer(Modifier.size(24.dp))
        }
    }
}

/** A folder's palette index by id — the first tier colour when the item has none. */
private fun folderColorIndex(store: com.seancheren.suite.core.Store, id: UUID?): Int =
    id?.let { fid -> store.data.folders.firstOrNull { it.id == fid }?.color } ?: 0

@Composable
private fun DayCell(
    day: LocalDate?,
    isToday: Boolean,
    isSelected: Boolean,
    eventColor: Color?,
    reminderColor: Color?,
    noteColor: Color?,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
) {
    Box(
        modifier
            .height(46.dp)
            .padding(2.dp)
            .clip(RoundedCornerShape(8.dp))
            .then(if (isSelected) Modifier.border(1.dp, Accent, RoundedCornerShape(8.dp)) else Modifier)
            .clickable(enabled = day != null) { onClick() },
        contentAlignment = Alignment.Center,
    ) {
        if (day != null) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                if (isToday) {
                    Box(Modifier.clip(CircleShape).background(Accent).padding(horizontal = 6.dp, vertical = 1.dp)) {
                        Text("${day.dayOfMonth}", color = OnAccent, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                    }
                } else {
                    Text("${day.dayOfMonth}", color = TextColor, fontSize = 13.sp)
                }
                Spacer(Modifier.size(2.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(3.dp)) {
                    if (eventColor != null) KindIcon(CellKind.Event, eventColor)
                    if (reminderColor != null) KindIcon(CellKind.Reminder, reminderColor)
                    if (noteColor != null) KindIcon(CellKind.Note, noteColor)
                }
            }
        }
    }
}

private enum class CellKind { Event, Reminder, Note }

/** The legend's kind glyphs at cell size — a calendar, a tick box, a page — stroke-drawn
 *  like the web's SVGs so no icon pack is pulled in for three shapes. */
@Composable
private fun KindIcon(kind: CellKind, color: Color) {
    Canvas(Modifier.size(9.dp)) {
        val w = size.width
        val h = size.height
        val stroke = Stroke(width = w * 0.12f)
        when (kind) {
            CellKind.Event -> {          // a calendar: the frame, its top bar
                drawRoundRect(color, topLeft = Offset(0f, h * 0.1f), size = Size(w, h * 0.9f),
                              cornerRadius = CornerRadius(w * 0.15f), style = stroke)
                drawLine(color, Offset(0f, h * 0.38f), Offset(w, h * 0.38f), strokeWidth = stroke.width)
            }
            CellKind.Reminder -> {       // a tick box
                drawRoundRect(color, size = Size(w, h),
                              cornerRadius = CornerRadius(w * 0.22f), style = stroke)
                val tick = Path().apply {
                    moveTo(w * 0.28f, h * 0.52f); lineTo(w * 0.45f, h * 0.7f); lineTo(w * 0.75f, h * 0.32f)
                }
                drawPath(tick, color, style = stroke)
            }
            CellKind.Note -> {           // a page with two ruled lines
                drawRoundRect(color, topLeft = Offset(w * 0.08f, 0f), size = Size(w * 0.84f, h),
                              cornerRadius = CornerRadius(w * 0.12f), style = stroke)
                drawLine(color, Offset(w * 0.3f, h * 0.42f), Offset(w * 0.7f, h * 0.42f), strokeWidth = stroke.width)
                drawLine(color, Offset(w * 0.3f, h * 0.65f), Offset(w * 0.7f, h * 0.65f), strokeWidth = stroke.width)
            }
        }
    }
}

/** A row's colour dot in the day panel — the panel keeps dots; only the cells wear icons. */
@Composable
private fun Dot(color: Color) {
    Spacer(Modifier.size(5.dp).clip(CircleShape).background(color))
}

@Composable
private fun PanelRow(dot: Color, text: String, meta: String?) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Dot(dot)
        Spacer(Modifier.size(10.dp))
        Text(text, color = TextColor, fontSize = 15.sp, modifier = Modifier.weight(1f))
        if (meta != null) Text(meta, color = Muted, fontSize = 13.sp)
    }
}

/** A collapsible kind group in the day panel (Events / Reminders / Notes), like the web's dp-group. */
@Composable
private fun DayGroup(name: String, count: Int, collapsed: Boolean, onToggle: () -> Unit, content: @Composable () -> Unit) {
    if (count == 0) return
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(6.dp))
            .clickable { onToggle() }
            .padding(vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(if (collapsed) "▸" else "▾", color = Muted, fontSize = 12.sp, modifier = Modifier.width(18.dp))
        Text(name, color = Gold, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.size(6.dp))
        Text(count.toString(), color = Muted, fontSize = 12.sp)
    }
    if (!collapsed) content()
}

private enum class DayAddKind { Reminder, Event, Note }
