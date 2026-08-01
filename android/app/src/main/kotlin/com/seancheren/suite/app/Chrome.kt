package com.seancheren.suite.app

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// Shared chrome, so every screen's top bar sits in the same place with a rule under it —
// the cousin of lib/chrome.php's top bar. The app's name on the left, its own controls
// gathered on the right.

@Composable
fun TopBar(title: String, trailing: @Composable RowScope.() -> Unit = {}) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(start = 16.dp, end = 10.dp, top = 20.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(title, color = TextColor, fontSize = 20.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.weight(1f))
        trailing()
    }
    HorizontalDivider(color = Hairline, thickness = 1.dp)
}

/** A pill button — outlined, or filled in the accent for a primary action. */
@Composable
fun Pill(text: String, primary: Boolean = false, onClick: () -> Unit) {
    val base = Modifier
        .clip(RoundedCornerShape(999.dp))
        .clickable { onClick() }
    Text(
        text = text,
        color = if (primary) OnAccent else TextColor,
        fontSize = 14.sp,
        fontWeight = if (primary) FontWeight.Bold else FontWeight.Normal,
        modifier = (if (primary) base.background(Accent) else base.border(1.dp, Hairline, RoundedCornerShape(999.dp)))
            .padding(horizontal = 14.dp, vertical = 6.dp),
    )
}

/** The section/folder colour dot. */
@Composable
fun Swatch(color: Color, sizeDp: Int = 11) {
    Spacer(
        Modifier
            .size(sizeDp.dp)
            .clip(CircleShape)
            .background(color),
    )
}

/** A gold section heading, echoing the web's gold titles. */
@Composable
fun SectionTitle(name: String, color: Color, trailing: @Composable RowScope.() -> Unit = {}) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(start = 16.dp, end = 12.dp, top = 14.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Swatch(color)
        Spacer(Modifier.size(8.dp))
        Text(name, color = Gold, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        trailing()
    }
}
