# Jetpack Compose in this app

The `app/` module is a thin Compose shell over `core`. It mirrors `ios/App/`
screen-for-screen (SwiftUI → Compose), so a reviewer can hold `RemindersView.swift`
and `RemindersScreen.kt` side by side. Keep the UI value-driven and push logic
down into `core` — a screen decides *layout*, `Store` decides *behavior*.

## The store-to-Compose bridge (`SuiteViewModel`)

`core`'s `Store` is framework-free and mutates an in-place `AppData` tree, so
Compose needs a nudge to recompose after a mutation. `SuiteViewModel` provides it:

```kotlin
class SuiteViewModel(app: Application) : AndroidViewModel(app) {
    val store = Store(file = suiteFile(app), onFirstRunSample = true)
    var rev by mutableStateOf(0L); private set          // bumped on every change
    private var saveJob: Job? = null

    init {
        store.onChange {
            rev++                                        // 1) trigger recomposition
            saveJob?.cancel()                            // 2) debounce the disk write
            saveJob = viewModelScope.launch { delay(400); store.save(); pushToWatch() }
        }
    }
}
```

A screen **reads `vm.rev` once at the top** to subscribe, then queries the store:

```kotlin
@Composable fun RemindersScreen(vm: SuiteViewModel) {
    val rev = vm.rev                                     // subscribe; recompose when data changes
    val folder = vm.store.data.lastFolder[...]           // read derived state from the store
    val rows = vm.store.reminders(folder = folder, group = GroupRef.Inbox)
    // …render rows; onClick { vm.store.toggle(row) } …
}
```

This is deliberate and simple. Don't copy model data into `remember { mutableStateOf(...) }`
that mirrors the store — it will go stale the moment the store changes underneath
it. `remember` is for **UI-local** state only: the text of a field being edited,
whether a section is collapsed, which sheet is open.

## Lists

- `LazyColumn` with a **stable key**: `items(rows, key = { it.id })`. Without it,
  a reorder or a tick can rebind the wrong row (the same class of bug SwiftUI's
  `Identifiable` prevents). Ids here are `UUID`.
- Derive the visible list inside the composable from `vm.store.…` each
  recomposition — it's cheap at this app's scale and always reflects truth.

## Gestures — where the real bugs live

The web harness runs no JS and can't see gestures; the same is true of Gradle and
Compose previews. **Every gesture is verified by hand on a device**, and that's
where nearly every shipped bug in this project has come from. Match the iOS
interactions precisely:

- **Long-press to enter edit mode**, tap empty space / back to leave it. Compose:
  `Modifier.combinedClickable(onClick = …, onLongClick = …)` or
  `pointerInput { detectTapGestures(onLongPress = …) }`.
- **Two-press delete**, no dialog: first tap arms (turns red), second deletes.
  Hold the "armed id" in UI-local state; the second press calls the store.
- **Swipe a row left to delete** one item without edit mode — `SwipeToDismiss` /
  `anchoredDraggable`, standing down while edit mode owns the row for dragging.
- **Drag to reorder** posts the new order via `store.moveReminders/Notes/Habits`,
  which only breaks ties (display still sorts undated-first) — same as the web.

Keep these near the code they act on, as iOS does, not in a global handler.

## Recomposition traps

- Reading `vm.rev` and then not using it: the compiler may warn "unused", but the
  read still subscribes. If you want it unmistakable, wrap the body in
  `key(vm.rev) { … }`.
- Don't mutate `store.data` from a background thread. `Store` mutation is
  main-thread (called from Compose callbacks / the ViewModel). Disk I/O in
  `store.save()` is fine off-main inside the debounce coroutine, but the mutation
  that preceded it happened on main.
- Hoisting a `TextField`'s value into the store on every keystroke re-saves and
  can fight the cursor. Keep the edited text in UI-local state; write it to the
  store on commit (blur / Enter / done), which is also what the debounce expects.

## Theme

`Theme.kt` holds the palette (`#111` background, `#eee` text, `#34d399` accent,
`#60a5fa` event blue — a blue, never a cyan, so it can't read as the green at dot
size) and the pill-shaped control shapes. Everything reads from it; no screen
hardcodes a colour. Dark theme throughout, matching the suite.
