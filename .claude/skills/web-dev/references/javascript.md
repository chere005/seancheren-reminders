# Vanilla JavaScript reference

Framework-free browser JS, inline in the page. No bundler, no npm, no React.
Assumes the house style in `SKILL.md`.

## DOM basics done right

- `document.querySelector` / `querySelectorAll` (returns a static NodeList —
  `forEach` works, but it won't update as the DOM changes).
- Build nodes with `document.createElement` + `textContent`, or set
  `el.textContent = userValue`. **Never** `el.innerHTML = userValue` with
  untrusted data — that's client-side XSS. If you must build HTML, escape first
  or use `insertAdjacentText`.
- Toggle behavior with classes (`el.classList.add/remove/toggle`) and CSS, not
  inline style, so state stays inspectable and themeable.
- Reads then writes: batch DOM reads before writes to avoid layout thrash in a
  loop.

## Event delegation

For a list that changes, bind one listener on the container and match the
target, instead of binding per row (which leaks listeners as rows come and go):

```js
list.addEventListener('click', e => {
  const btn = e.target.closest('[data-action]');
  if (!btn || !list.contains(btn)) return;
  handle(btn.dataset.action, btn.closest('li'));
});
```

`closest()` + `dataset` is the workhorse pattern. It survives re-rendering the
list's innerHTML because the listener lives on the stable parent.

## Talking to the server (progressive enhancement)

Start from a working `<form>` that POSTs and reloads. Then intercept:

```js
form.addEventListener('submit', async e => {
  e.preventDefault();
  const res = await fetch(form.action, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(form),          // sends the CSRF token field too
  });
  if (!res.ok) { form.submit(); return; }   // fall back to a real submit
  const state = await res.json();            // authoritative state from server
  render(state);                             // re-draw from truth, don't guess
});
```

- `FormData` posts the same fields the plain form would, CSRF token included —
  no need to hand-serialize.
- Send `X-Requested-With` so the PHP side returns JSON instead of redirecting.
- On failure, fall back to a normal submit rather than leaving the user stuck.
- Re-render from the returned state, not from what you *think* changed — the
  server is the source of truth (matches CLAUDE.md's AJAX convention).

## Forms & inputs

- `input.value` is always a string; coerce. `<input type=number>` still yields a
  string and can be empty.
- Prevent double-submit: disable the button on submit, re-enable on response.
- `requestSubmit()` fires the submit event (so your handler runs);
  `form.submit()` does **not** — this repo patches around that for programmatic
  submits (see `keep_scroll_script`).

## Persistence & state

- `localStorage` / `sessionStorage` hold strings only — `JSON.stringify` in,
  `JSON.parse` out, and wrap the parse in try/catch (a user can corrupt it).
- Namespace keys (`calFold_<id>`) so features don't collide — this repo does.
- Never store secrets or trust localStorage for authorization; it's client-side
  and editable.

## Traps in frameworkless code

- **Timing:** script in `<head>` runs before the body exists. Put scripts at end
  of `<body>`, or guard with `DOMContentLoaded`.
- **`this`** in a plain function isn't the element; use arrow functions for
  callbacks or `event.currentTarget`.
- **Listener leaks:** re-rendering innerHTML orphans element listeners (they're
  GC'd with the nodes) but *delegated* listeners on a surviving parent persist —
  prefer delegation.
- **Async ordering:** two in-flight `fetch`es can resolve out of order; if the
  later request's response matters, track a request id and ignore stale ones.
- **`==` vs `===`:** always `===` in JS too.
- Debounce rapid events (scroll, input, resize) so you're not posting on every
  keystroke; autosave should debounce ~500ms.

## PWA / iOS notes (this repo)

- These pages are iOS home-screen PWAs. Respect `env(safe-area-inset-*)` in CSS.
- In `navigator.standalone` mode, same-origin link clicks are intercepted so the
  app doesn't kick out to Safari — see `tabbar.php`. Don't fight that.
