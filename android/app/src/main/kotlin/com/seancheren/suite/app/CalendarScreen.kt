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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
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
                        DayCell(
                            day = day,
                            isToday = day == today,
                            isSelected = day == selected,
                            hasEvent = day != null && store.events(day).isNotEmpty(),
                            reminderState = day?.let { reminderStateOn(store, it, today) } ?: ReminderState.None,
                            hasNote = day != null && store.notes(day).isNotEmpty(),
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
            Text(
                selected.format(java.time.format.DateTimeFormatter.ofPattern("EEEE, MMM d", Locale.US)),
                color = TextColor, fontSize = 16.sp, fontWeight = FontWeight.SemiBold,
            )
            Spacer(Modifier.size(8.dp))

            val events = store.events(selected)
            val reminders = store.reminders(selected, today)
            val notes = store.notes(selected)

            if (events.isEmpty() && reminders.isEmpty() && notes.isEmpty()) {
                Text("Nothing on.", color = Muted, fontSize = 14.sp)
            }
            for (e in events) {
                PanelRow(dot = KEvent, text = e.text, meta = e.minutes?.let { timeLabel(it) })
            }
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
            for (n in notes) {
                PanelRow(dot = KNote, text = n.title.ifBlank { "Untitled" }, meta = null)
            }
            Spacer(Modifier.size(24.dp))
        }
    }
}

private enum class ReminderState { None, Open, Overdue }

private fun reminderStateOn(store: com.seancheren.suite.core.Store, day: LocalDate, today: LocalDate): ReminderState {
    val rows = store.reminders(day, today)
    if (rows.isEmpty()) return ReminderState.None
    return if (rows.any { it.overdue(today) }) ReminderState.Overdue else ReminderState.Open
}

@Composable
private fun DayCell(
    day: LocalDate?,
    isToday: Boolean,
    isSelected: Boolean,
    hasEvent: Boolean,
    reminderState: ReminderState,
    hasNote: Boolean,
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
                Row(horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                    if (hasEvent) Dot(KEvent)
                    when (reminderState) {
                        ReminderState.Overdue -> Dot(KOverdue)
                        ReminderState.Open -> Dot(KReminder)
                        ReminderState.None -> {}
                    }
                    if (hasNote) Dot(KNote)
                }
            }
        }
    }
}

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
