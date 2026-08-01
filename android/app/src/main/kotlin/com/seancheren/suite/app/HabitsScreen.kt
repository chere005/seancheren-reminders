package com.seancheren.suite.app

import androidx.compose.foundation.Canvas
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
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.seancheren.suite.core.Habit
import com.seancheren.suite.core.ItemKind
import com.seancheren.suite.core.ListGroup
import com.seancheren.suite.core.key
import java.time.LocalDate
import java.time.YearMonth
import java.util.UUID

/** Habits — the cousin of ios/App/HabitsView.swift. Week grid + month pies + a section filter. */
@Composable
fun HabitsScreen(vm: SuiteViewModel) {
    val store = vm.store
    val today = LocalDate.now()
    val revKey = vm.rev
    var anchor by remember { mutableStateOf(today) }         // week paging
    var month by remember { mutableStateOf(today.withDayOfMonth(1)) }
    var addingHabit by remember { mutableStateOf(false) }
    var habitName by remember { mutableStateOf("") }
    var addingSection by remember { mutableStateOf(false) }
    var sectionName by remember { mutableStateOf("") }

    val monthView = store.data.habitsMonth

    Column(Modifier.fillMaxSize()) {
        TopBar("Habits") {
            Pill(if (monthView) "Month" else "Week") {
                store.data.habitsMonth = !store.data.habitsMonth
                store.touch()
            }
        }

        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState()),
        ) {
            if (monthView) {
                MonthView(store, month, today, revKey,
                    onPrev = { month = month.minusMonths(1) },
                    onNext = { month = month.plusMonths(1) })
            } else {
                WeekView(store, anchor, today, revKey,
                    onPrev = { anchor = anchor.minusWeeks(1) },
                    onNext = { anchor = anchor.plusWeeks(1) })
            }

            Spacer(Modifier.size(12.dp))
            if (addingHabit) {
                AddField(habitName, { habitName = it }, {
                    store.addHabit(habitName, null); habitName = ""; addingHabit = false
                }, "New habit…")
            } else {
                Text("+ Habit", color = Muted, fontSize = 14.sp,
                    modifier = Modifier.padding(start = 16.dp, top = 4.dp, bottom = 4.dp).clickable { addingHabit = true })
            }
            if (addingSection) {
                AddField(sectionName, { sectionName = it }, {
                    store.addGroup(sectionName, ItemKind.habit); sectionName = ""; addingSection = false
                }, "Section name…")
            } else {
                Text("+ Section", color = Muted, fontSize = 14.sp,
                    modifier = Modifier.padding(start = 16.dp, top = 4.dp, bottom = 4.dp).clickable { addingSection = true })
            }
            Spacer(Modifier.size(48.dp))
        }
    }
}

// MARK: - Week

@Composable
private fun WeekView(
    store: com.seancheren.suite.core.Store,
    anchor: LocalDate,
    today: LocalDate,
    revKey: Long,
    onPrev: () -> Unit,
    onNext: () -> Unit,
) {
    val weekStart = anchor.minusDays((anchor.dayOfWeek.value % 7).toLong())    // Sunday
    val days = (0..6).map { weekStart.plusDays(it.toLong()) }

    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("‹", color = Accent, fontSize = 22.sp, modifier = Modifier.clip(CircleShape).clickable { onPrev() }.padding(horizontal = 8.dp))
        Spacer(Modifier.weight(1f))
        Text("›", color = Accent, fontSize = 22.sp, modifier = Modifier.clip(CircleShape).clickable { onNext() }.padding(horizontal = 8.dp))
    }

    // Column head: weekday + date, today marked.
    Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp)) {
        Spacer(Modifier.width(104.dp))
        for (d in days) {
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Text("SMTWTFS"[d.dayOfWeek.value % 7].toString(), color = Muted, fontSize = 10.sp)
                if (d == today) {
                    Box(Modifier.clip(CircleShape).background(Accent).padding(horizontal = 5.dp)) {
                        Text("${d.dayOfMonth}", color = OnAccent, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                    }
                } else {
                    Text("${d.dayOfMonth}", color = TextColor, fontSize = 11.sp)
                }
            }
        }
    }

    remember(revKey) { 0 } // subscribe to changes
    for (section in habitSections(store)) {
        SectionHeaderMini(section.name, section.color)
        for (h in section.habits) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(h.name, color = TextColor, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.width(104.dp))
                for (d in days) {
                    val on = h.on(d)
                    Box(
                        Modifier
                            .weight(1f)
                            .padding(2.dp)
                            .height(30.dp)
                            .clip(RoundedCornerShape(6.dp))
                            .background(if (on) Accent else Surface)
                            .then(if (d == today) Modifier.border(2.dp, Accent, RoundedCornerShape(6.dp)) else Modifier)
                            .clickable { store.toggleHabit(h, d) },
                    )
                }
            }
        }
    }
}

// MARK: - Month

@Composable
private fun MonthView(
    store: com.seancheren.suite.core.Store,
    month: LocalDate,
    today: LocalDate,
    revKey: Long,
    onPrev: () -> Unit,
    onNext: () -> Unit,
) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("‹", color = Accent, fontSize = 22.sp, modifier = Modifier.clip(CircleShape).clickable { onPrev() }.padding(horizontal = 8.dp))
        Text(
            "${month.month.getDisplayName(java.time.format.TextStyle.FULL, java.util.Locale.US)} ${month.year}",
            color = TextColor, fontSize = 15.sp, fontWeight = FontWeight.SemiBold,
            textAlign = TextAlign.Center, modifier = Modifier.weight(1f),
        )
        Text("›", color = Accent, fontSize = 22.sp, modifier = Modifier.clip(CircleShape).clickable { onNext() }.padding(horizontal = 8.dp))
    }

    // Section filter chips.
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        for (section in habitSections(store)) {
            val gid = section.groupId
            val shown = store.habitSectionShown(gid)
            Text(
                section.name,
                color = if (shown) OnAccent else Muted,
                fontSize = 12.sp,
                modifier = Modifier
                    .clip(RoundedCornerShape(999.dp))
                    .then(if (shown) Modifier.background(section.color) else Modifier.border(1.dp, Hairline, RoundedCornerShape(999.dp)))
                    .clickable { store.toggleHabitSection(gid) }
                    .padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }
    }

    val fill = remember(revKey, month) { store.habitMonthFill(month) }
    val total = remember(revKey) { store.habitsCounted().size }
    val monthStart = month.withDayOfMonth(1)
    val daysInMonth = YearMonth.from(monthStart).lengthOfMonth()
    val firstCol = monthStart.dayOfWeek.value % 7
    val cells = ArrayList<LocalDate?>()
    repeat(firstCol) { cells.add(null) }
    for (d in 1..daysInMonth) cells.add(monthStart.withDayOfMonth(d))
    while (cells.size % 7 != 0) cells.add(null)

    Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp)) {
        for (week in cells.chunked(7)) {
            Row(Modifier.fillMaxWidth()) {
                for (day in week) {
                    Box(Modifier.weight(1f).height(44.dp).padding(3.dp), contentAlignment = Alignment.Center) {
                        if (day != null) {
                            val ticks = fill[day.key]?.values?.sum() ?: 0
                            val fraction = if (total > 0) ticks.toFloat() / total else 0f
                            Pie(fraction = fraction, ahead = day > today)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun Pie(fraction: Float, ahead: Boolean) {
    Canvas(Modifier.size(26.dp)) {
        if (ahead) {
            drawCircle(color = Hairline, style = androidx.compose.ui.graphics.drawscope.Stroke(width = 2f))
        } else {
            drawCircle(color = Surface)
            if (fraction > 0f) {
                drawArc(color = Accent, startAngle = -90f, sweepAngle = 360f * fraction.coerceIn(0f, 1f), useCenter = true)
            }
        }
    }
}

// MARK: - Sections helper

private data class HabitSection(val groupId: UUID?, val name: String, val color: Color, val habits: List<Habit>)

private fun habitSections(store: com.seancheren.suite.core.Store): List<HabitSection> {
    val out = ArrayList<HabitSection>()
    for (g: ListGroup in store.data.groupList(ItemKind.habit)) {
        out.add(HabitSection(g.id, g.name, paletteColor(g.color), store.habits(g.id)))
    }
    val loose = store.habits(null)
    if (loose.isNotEmpty()) out.add(HabitSection(null, "Habits", Muted, loose))
    return out
}

@Composable
private fun SectionHeaderMini(name: String, color: Color) {
    Row(
        Modifier.fillMaxWidth().padding(start = 12.dp, top = 10.dp, bottom = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Swatch(color)
        Spacer(Modifier.size(6.dp))
        Text(name, color = Gold, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}
