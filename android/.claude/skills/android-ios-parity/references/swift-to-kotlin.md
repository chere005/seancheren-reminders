# Swift → Kotlin translation reference

How the iOS core (`ios/Shared/`) becomes the Android core (`android/core/`) so the
two read as translations. The aim is a mechanical, reviewable mapping — not a
rewrite that "improves" one side and lets them drift.

## Value types and models

Swift `struct`s are value types with member-wise init and `Codable`. Kotlin:

```kotlin
@Serializable
data class Reminder(
    @Contextual var id: UUID = UUID.randomUUID(),
    var text: String = "",
    @Contextual var due: LocalDate? = null,   // nil = undated
    var minutes: Int? = null,                 // time of day, if any
    var done: Boolean = false,
    @Contextual var folder: UUID? = null,
    var group: GroupRef = GroupRef.Inbox,
    var recurrence: Recurrence? = null,
    var order: Int = 0,
    var indent: Int = 0,                       // 0 top-level, 1 subtask (one level only)
) {
    fun overdue(today: LocalDate) = due?.let { !done && it < today } ?: false
    val ridesAlong: Boolean get() = due == null && group == GroupRef.Calendar
}
```

- **`var` + defaults, not `val`.** `var` lets `Store` mutate in place
  (`data.reminders[i].done = !…`) exactly as Swift does, keeping the two `Store`s
  line-for-line. Defaults make decoding tolerant (below).
- **`MutableList`** for the arrays in `AppData` for the same reason (`add`,
  `removeAll`, indexed assignment mirror Swift).
- Swift computed `var x: T { … }` → Kotlin `val x: T get() = …`. Swift `func f()` →
  Kotlin `fun f()`. Keep the names identical.

## Enums

- Plain raw-value enum → `enum class`:
  ```kotlin
  @Serializable enum class ItemKind { reminder, note, habit }   // rawValue == name
  ```
  Where Swift uses `.rawValue` as a dictionary key, use `name` in Kotlin.
- **Associated-value enum** (`GroupRef`) → a **sealed interface** + a custom
  serializer, because kotlinx.serialization can't synthesize one that matches a
  compact form:
  ```kotlin
  @Serializable(with = GroupRefSerializer::class)
  sealed interface GroupRef {
      @Serializable data object Inbox : GroupRef
      @Serializable data object Calendar : GroupRef
      @Serializable data class Group(@Contextual val id: UUID) : GroupRef
      val groupId: UUID? get() = (this as? Group)?.id
  }
  object GroupRefSerializer : KSerializer<GroupRef> {
      override val descriptor = PrimitiveSerialDescriptor("GroupRef", PrimitiveKind.STRING)
      override fun serialize(e: Encoder, v: GroupRef) = e.encodeString(when (v) {
          GroupRef.Inbox -> "inbox"; GroupRef.Calendar -> "calendar"; is GroupRef.Group -> v.id.toString()
      })
      override fun deserialize(d: Decoder) = when (val s = d.decodeString()) {
          "inbox" -> GroupRef.Inbox; "calendar" -> GroupRef.Calendar; else -> GroupRef.Group(UUID.fromString(s))
      }
  }
  ```
  A UUID string can never equal `"inbox"`/`"calendar"`, so decoding is unambiguous.

## Codable → kotlinx.serialization

- `Json` config (one shared instance in `Store`):
  ```kotlin
  val json = Json {
      prettyPrint = true; encodeDefaults = true; ignoreUnknownKeys = true
      serializersModule = SerializersModule {
          contextual(UUID::class, UuidSerializer)
          contextual(LocalDate::class, LocalDateSerializer)
          contextual(Instant::class, InstantSerializer)
      }
  }
  ```
- **Tolerant decode is automatic** given (a) a default on every field and (b)
  `ignoreUnknownKeys = true`: a missing key uses its default, an unknown key is
  dropped. This replaces Swift's hand-written `init(from:)` /
  `decodeIfPresent ?? default`. The rule that keeps it working: **never add a
  field without a default.** Keep the twin of `testOldDocumentWithoutNewKeysStillLoads`.
- Small serializers:
  ```kotlin
  object UuidSerializer : KSerializer<UUID> { /* string ↔ UUID.fromString */ }
  object LocalDateSerializer : KSerializer<LocalDate> { /* ISO "2026-08-03" */ }
  object InstantSerializer : KSerializer<Instant> { /* epoch millis Long */ }
  ```
- Annotate each `UUID`/`LocalDate`/`Instant` occurrence `@Contextual` (including
  inside `List`/`Set`/`Map`: `Set<@Contextual UUID>`, `Map<String, @Contextual UUID>`).

## Dates

Swift stores day-granular values as `Date` pinned to `startOfDay` (`.day`) plus a
separate `minutes: Int?`. Kotlin uses **`java.time.LocalDate`** for those — it *is*
day-granular, so the whole `startOfDay` dance disappears and a class of timezone
bugs with it. Behavior is identical; only the type is more honest.

- `date.key` ("yyyy-MM-dd", habit marks) → `date.toString()` (ISO) or an explicit
  formatter — same string.
- `Note.updated` (a real timestamp) → `Instant`.
- `Calendar.current.date(byAdding:)`, `range(of:.day,in:.month)` →
  `LocalDate.plusDays/plusMonths`, `YearMonth.lengthOfMonth()`, `withDayOfMonth`.
  Month/year clamping becomes `min(wanted, ym.lengthOfMonth())`.

## The store bridge

Swift `Store` is `@MainActor ObservableObject` with `@Published var data` and a
debounced save fired off `objectWillChange`. Split on Android:

- **`core/Store.kt`** stays framework-free: `var data`, `load`/`save` (atomic:
  temp file then `renameTo`), and `touch()` that fires an injected
  `onChange` listener. **No coroutines, no timers, no Compose** — so `:core:test`
  stays a pure-JVM twin of `swift test`.
- **`app/SuiteViewModel.kt`** owns the rest: `store.onChange { rev++; debounceSave() }`,
  where `rev` is a Compose `mutableStateOf` that screens read to recompose, and the
  save is a `viewModelScope` coroutine with `delay(400)`. This is the `@Published`
  + debounce, split so the logic layer needs no UI framework.

## The reorder helper

SwiftUI hands drag callbacks `(IndexSet, Int)` and provides `move(fromOffsets:toOffset:)`.
The Swift core reimplements it on `Array` so it's testable; port it verbatim onto
`MutableList`:

```kotlin
fun <T> MutableList<T>.reorder(from: Set<Int>, to: Int) {
    val moving = from.sorted().map { this[it] }
    val target = to - from.count { it < to }
    from.sortedDescending().forEach { removeAt(it) }
    addAll(target.coerceIn(0, size), moving)
}
```

Keep `testReorderMatchesDragSemantics` as the twin so the semantics can't drift.

## JSON-shape caveats (they don't share a file, but keep them close)

The two apps each own a local `suite.json`; they never exchange it, so exact byte
compatibility isn't required. But keep the *shape* the same (same keys, same
nesting) so the model stays one spec and a future import/sync is trivial. Known
cosmetic differences that are fine: Kotlin `UUID.toString()` is lowercase where
Swift's `uuidString` is uppercase; Kotlin dates serialize as ISO strings where
Swift `Date` serializes as a number. Don't "fix" these by making one side ugly —
they're a consequence of each platform's idiomatic types.
