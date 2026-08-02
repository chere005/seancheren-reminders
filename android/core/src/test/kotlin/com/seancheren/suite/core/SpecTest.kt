package com.seancheren.suite.core

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Test
import java.io.File
import java.time.LocalDate

/**
 * The shared behavior contract: the JSON vectors in spec/ at the repo root — the same
 * cases the web (tools/test.php, the `spec` area) and iOS (SpecTests.swift) replay. A
 * behavior change starts in spec/ and is done only when every platform's suite is green.
 */
class SpecTest {

    // Gradle runs tests from the module dir (android/core); walk up until spec/ shows,
    // so the resolution survives being run from the repo root or an IDE too.
    private fun spec(name: String): File {
        var dir = File(".").absoluteFile
        repeat(6) {
            val f = File(dir, "spec/$name")
            if (f.isFile) return f
            dir = dir.parentFile ?: return@repeat
        }
        throw AssertionError("spec/$name not found above ${File(".").absolutePath}")
    }

    private fun loadArray(name: String): JsonArray =
        Json.parseToJsonElement(spec(name).readText()).jsonArray

    private fun loadObject(name: String): JsonObject =
        Json.parseToJsonElement(spec(name).readText()).jsonObject

    private fun JsonObject.str(key: String): String? =
        this[key]?.takeIf { it !is JsonNull }?.jsonPrimitive?.content

    private fun day(ymd: String): LocalDate = LocalDate.parse(ymd)

    private fun hhmm(minutes: Int): String = String.format("%02d:%02d", minutes / 60, minutes % 60)

    // MARK: - parse.json

    @Test fun testEveryParseVectorHolds() {
        for (el in loadArray("parse.json")) {
            val c = el.jsonObject
            val name = c.str("name")
            val got = parseWhen(c.str("input")!!, day(c.str("today")!!))
            assertEquals("$name: text", c.str("text"), got.text)
            assertEquals("$name: date", c.str("date"), got.date?.toString())
            assertEquals("$name: time", c.str("time"), got.minutes?.let { hhmm(it) })
        }
    }

    // MARK: - repeats.json

    private fun recurrence(n: Int, unit: String) = Recurrence(n, RepeatUnit.valueOf(unit))

    @Test fun testEveryRepeatVectorHolds() {
        val spec = loadObject("repeats.json")
        for (el in spec["step"]!!.jsonArray) {
            val c = el.jsonObject
            // occurrence(start, i) is the start-anchored seam all three cores define —
            // the web's repeat_step — so a month repeat springs back to the 31st instead
            // of drifting to wherever a shorter month clamped it.
            val got = recurrence(c["n"]!!.jsonPrimitive.int, c.str("unit")!!)
                .occurrence(day(c.str("start")!!), c["i"]!!.jsonPrimitive.int)
            assertEquals(c.str("name"), c.str("expect"), got.toString())
        }
        for (el in spec["window"]!!.jsonArray) {
            val c = el.jsonObject
            val start = day(c.str("start")!!)
            val from = day(c.str("from")!!)
            val to = day(c.str("to")!!)
            val rule = c["repeat"]?.takeIf { it !is JsonNull }?.jsonObject
            val got: List<LocalDate> = if (rule != null) {
                recurrence(rule["n"]!!.jsonPrimitive.int, rule.str("unit")!!).dates(start, from, to)
            } else {
                // No rule: a one-off is itself when it falls inside the window. (The
                // Store's calendar read does this branch; the spec keeps it honest.)
                if (from <= to && start >= from && start <= to) listOf(start) else emptyList()
            }
            val want = c["expect"]!!.jsonArray.map { it.jsonPrimitive.content }
            assertEquals(c.str("name"), want, got.map { it.toString() })
        }
        for (el in spec["next"]!!.jsonArray) {
            val c = el.jsonObject
            val got = recurrence(c["n"]!!.jsonPrimitive.int, c.str("unit")!!)
                .next(day(c.str("start")!!), day(c.str("after")!!))
            assertEquals(c.str("name"), c.str("expect"), got.toString())
        }
    }

    // MARK: - sort.json

    @Test fun testEverySortVectorHolds() {
        for (el in loadArray("sort.json")) {
            val c = el.jsonObject
            val f = File.createTempFile("suitespec-", ".json")
            f.delete()
            val store = Store(f)
            c["rows"]!!.jsonArray.forEachIndexed { i, rowEl ->
                val row = rowEl.jsonObject
                store.data.reminders.add(Reminder(
                    text = row.str("id")!!,          // the vector id rides in the text
                    due = row.str("due")?.let { day(it) },
                    indent = row["indent"]?.jsonPrimitive?.int ?: 0,
                    order = i,
                ))
            }
            val got = store.reminders(folder = null, group = GroupRef.Inbox).map { it.text }
            val want = c["expect"]!!.jsonArray.map { it.jsonPrimitive.content }
            assertEquals(c.str("name"), want, got)
        }
    }
}
