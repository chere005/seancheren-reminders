I haven't been quite happy with subtle things like not being able to have reminders from previous days on the calendar not continue to show until they are checked off.. I also wanted to tie together reminders, notes, and my calendar.. I also like enforcing date and time patterns.

Feel free to deploy this on your own website, build and deploy the iOS version, etc. This is a personal project to have some fun with claude code, which generated essentially all of the code, and the rest of this readme:



A small multi-user app suite in plain PHP on [NearlyFreeSpeech.NET](https://nearlyfreespeech.net) —
no framework, no build step, no database. Each app is one `index.php` that renders its own
HTML/CSS/JS and posts back to itself; data is encrypted JSON on disk. A matching **native
iOS + Apple Watch** app lives in `ios/` (SwiftUI, local-only, shares no code with the web).

## Features

- **Reminders** — folders + sections, subtasks, dates/times parsed from what you type, repeats, drag-reorder, Copy-as-Markdown.
- **Calendar** — month/week views, several calendars, a per-day panel of events + reminders + notes.
- **Notes** — folders + sections, rich-text bodies.
- **Habits** — a week tick-grid and a month of per-day pie charts, behind a section filter.
- **Chat** (public, no login) and **Aki's Bookshelf** (aki only).
- One login covers the suite; each user has their own encrypted data; sharing is opt-in between paired accounts.

## Web — run & test

```sh
php -S 127.0.0.1:8787 -t public     # apps at /reminders/, /calendar/, /notes/, /habits/, /chat/
php tools/test.php                  # the test suite (~15s, no framework)
find public lib tools -name '*.php' -exec php -l {} \;   # lint
```

Local logins come from `lib/config.php` (copy `lib/config.sample.php`); local data lands in `./data/`, separate from the live site.

## Web — deploy

Two live instances share one source tree: **production** (`/`) and a **`/test/` sandbox** with its own data.
`deploy.sh` is one-way (Mac → server), lints first, and never sends `config.php`, never touches the data dirs, never uses `--delete`.

```sh
./deploy.sh            # → TEST only (the safe default)
./deploy.sh promote    # copy the verified TEST tree onto PROD (server-side)
./deploy.sh both       # → TEST and PROD at once
./deploy.sh --dry-run  # preview, change nothing
```

The SSH target lives in a gitignored `deploy.conf` (copy `deploy.conf.sample`). Secrets live in
`lib/config.php` (gitignored, never deployed): the user map, the `data_key` for at-rest encryption,
and NFSN credentials. A blank `data_key` is generated into `data/.datakey` on first use — keep it.

## iOS + Apple Watch — build & run

A fully native SwiftUI app (no web view, no login, no network) with all data in one local `suite.json`.

```sh
open ios/Seancheren.xcodeproj   # pick a scheme + device, then ⌘R:
                                #   Seancheren      → iPhone (installs the embedded watch app)
                                #   SeancherenWatch → Apple Watch (needs a paired simulator/device)
```

From the command line (no simulator needed):

```sh
cd ios
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer swift test        # logic tests
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer \
  xcodebuild -scheme Seancheren -destination 'generic/platform=iOS' CODE_SIGNING_ALLOWED=NO build
```

Nothing in `ios/` is deployed. See `ios/README.md` for detail.

## License

BSD 3-Clause — see [LICENSE](LICENSE).
