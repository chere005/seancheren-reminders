# The phone ↔ watch bridge

The watch app is **read-only**: it draws a list the phone builds. There is no
shared database — the phone is the sole writer, the watch is a display.

## The data that crosses: `WatchList`

`Shared/WatchPayload.swift` is the *only* file compiled into **both** targets, so
the two ends can't disagree on the shape. It is deliberately small — not the
whole `AppData`, just what the watch draws, with dates already turned into short
strings:

```swift
struct WatchList  { var folder: String; var sections: [WatchSection] }
struct WatchSection { var name: String; var items: [WatchItem] }
struct WatchItem  { var id: String; var text: String; var due: String; var overdue: Bool }
// due is pre-formatted: "today", "2pm", "Aug 3", or ""
```

Because dates are formatted **on the phone**, the watch does no date math and
can't drift from the phone's idea of "today."

## How it flows

1. Any store change calls `Store.touch()`, which (besides saving) invokes the
   `onChange` block the phone wired up.
2. `App/PhoneConnectivity.swift` builds the current list with `Store.watchList()`
   — the same groups, same order as the Reminders screen, **open items only** —
   and ships it as the WatchConnectivity **application context**, under the
   single key `WatchLink.listKey` (`"list"`).
3. `Watch/WatchConnectivityReceiver.swift` (`WatchLinkReceiver`) decodes it,
   keeps a copy in `UserDefaults`, and publishes it to the watch UI.

## Why application context (not a message)

`updateApplicationContext` keeps **only the latest** state and redelivers it when
the watch next wakes — exactly right for "here's the current list." It works with
the phone out of range: the watch keeps showing the last context until a new one
arrives. Don't switch to `sendMessage` for this — that's for live, both-reachable
request/response and would lose updates when the watch is asleep.

## If you extend it

- **Keep `WatchList` minimal and pre-computed.** Every field the watch needs
  should arrive ready to display. Don't send raw model types or the watch starts
  needing the model and the two codebases fuse.
- **Changing `WatchPayload.swift` changes both targets at once** — that's the
  point, but it means an incompatible change can break the watch's decode of an
  old cached context. Keep it `Codable`-tolerant (defaults on new fields) so an
  updated phone talking to a not-yet-updated watch (or a stale cached context)
  decodes gracefully.
- **Ticking from the wrist** would reverse the flow: the watch sends a message
  *to* the phone, the phone mutates `data` and `touch()`es, and the new context
  flows back. Only the phone may write — never let the watch mutate its cached
  copy as if it were the source of truth.
- After any change, verify the round trip in the simulator (or on device): mutate
  on the phone, confirm the watch list updates; background the phone, confirm the
  watch still shows the last list.
