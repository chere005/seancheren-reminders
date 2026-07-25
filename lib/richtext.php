<?php
/**
 * The formatting toolbar shared by the Notes editor and Aki's Bookshelf note editor.
 *
 * Note bodies used to be plain text in a <textarea>. They are now a small subset of
 * HTML edited in a contenteditable, mirrored into a hidden <input name="body"> so the
 * apps' existing autosave POST doesn't change at all. Anything already stored as plain
 * text still opens fine — rt_body_html() spots a tagless body and escapes it.
 *
 * The body is rendered as HTML rather than escaped, so rt_sanitize() on the way in is
 * the whole security story: allowlist the tags, drop every attribute except our own
 * rt-* classes. Never store a body that hasn't been through it.
 */

/** Tags a note body may contain. Everything else is unwrapped, keeping its text. */
const RT_TAGS = ['b', 'strong', 'i', 'em', 'u', 'blockquote', 'ul', 'ol', 'li', 'br', 'div', 'p', 'span'];

/** Tags whose *contents* go too, rather than being unwrapped into the text. */
const RT_DROP = ['script', 'style', 'head', 'iframe', 'object', 'embed'];

/** Strip a note body down to the allowed tags and our own classes. */
function rt_sanitize(string $html): string
{
    $html = trim($html);
    if ($html === '' || !class_exists('DOMDocument')) {
        return $html === '' ? '' : strip_tags($html, '<' . implode('><', RT_TAGS) . '>');
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    // The wrapper gives the fragment a single root; NOIMPLIED keeps libxml from adding
    // <html><body> around it, so the wrapper itself ends up as the document element.
    $doc->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>',
                   LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->documentElement;
    if (!$root) { return ''; }
    rt_clean_node($root);

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) { $out .= $doc->saveHTML($child); }
    return trim($out);
}

/** Recursively drop what isn't allowed. Disallowed tags are unwrapped, not deleted. */
function rt_clean_node(DOMNode $node): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child instanceof DOMText) { continue; }
        if (!($child instanceof DOMElement)) { $node->removeChild($child); continue; }   // comments, PIs

        $tag = strtolower($child->tagName);
        if (in_array($tag, RT_DROP, true)) { $node->removeChild($child); continue; }

        rt_clean_node($child);

        if (!in_array($tag, RT_TAGS, true)) {
            // Unwrap: the text stays, the tag doesn't.
            while ($child->firstChild) { $node->insertBefore($child->firstChild, $child); }
            $node->removeChild($child);
            continue;
        }
        // class is the only attribute worth keeping, and only ours (rt-quote, rt-meta…).
        foreach (iterator_to_array($child->attributes) as $attr) {
            $keep = $attr->name === 'class'
                 && preg_match('/^rt-[a-z-]+$/', trim($attr->value));
            if (!$keep) { $child->removeAttribute($attr->name); }
        }
    }
}

/**
 * Stored body -> editor HTML. A body with no tags at all is one of the old plain-text
 * notes, so it gets escaped and its line breaks turned into <br>.
 */
function rt_body_html(?string $stored): string
{
    $stored = (string) $stored;
    if ($stored === '') { return ''; }
    if (strip_tags($stored) === $stored) { return nl2br(htmlspecialchars($stored, ENT_QUOTES), false); }
    return rt_sanitize($stored);
}

/**
 * The button row. $withEntry adds the bookshelf-only "+✏️" that opens the quote window.
 * Sizing follows the Calendar day-panel buttons, like every other control in the suite.
 */
function rt_toolbar_html(bool $withEntry = false): string
{
    $b = function (string $cmd, string $label, string $title, string $cls = '') {
        return '<button type="button" class="rt-btn ' . $cls . '" data-cmd="' . $cmd . '" title="' . $title
             . '" aria-label="' . $title . '">' . $label . '</button>';
    };
    // Quote first, then the bookshelf's quote window right beside it — they're the
    // same idea, one by hand and one filled in.
    $html = '<div class="rt-toolbar" role="toolbar" aria-label="Formatting">'
          . $b('quote', '&rdquo;', 'Quote (press again to unquote this line)');
    if ($withEntry) {
        $html .= '<button type="button" class="rt-btn rt-entry" id="rtEntryBtn" title="Add a quote with a note"'
               . ' aria-label="Add a quote with a note">+&#9998;&#65038;</button>';
    }
    return $html
         . $b('bold', '<b>B</b>', 'Bold')
         . $b('italic', '<i>I</i>', 'Italic')
         . $b('underline', '<u>U</u>', 'Underline')
         . $b('insertUnorderedList', '&bull;&nbsp;List', 'Bullet points')
         . '</div>';
}

/** The quote window: quote, my note about it, page, and an optional date stamp. */
function rt_entry_modal_html(): string
{
    return <<<'HTML'
    <div class="modal-backdrop" id="rtEntryModal">
      <div class="rt-modal">
        <h2>Add a quote</h2>
        <label for="rtQuote">Quote</label>
        <textarea id="rtQuote" rows="3" placeholder="What the book says…"></textarea>
        <label for="rtNote">Note</label>
        <textarea id="rtNote" rows="3" placeholder="What you think about it…"></textarea>
        <label for="rtPage">Page</label>
        <input type="text" id="rtPage" inputmode="numeric" maxlength="12" placeholder="e.g. 73">
        <button type="button" class="rt-stamp" id="rtStamp" aria-pressed="false">Stamp date &amp; time</button>
        <div class="rt-modal-actions">
          <button type="button" class="rt-cancel" id="rtEntryCancel">Cancel</button>
          <button type="button" class="rt-insert" id="rtEntryInsert">Insert</button>
        </div>
      </div>
    </div>
    HTML;
}

function rt_styles(): string
{
    return <<<'CSS'
    .rt-toolbar { display: flex; flex-wrap: wrap; gap: 0.35rem; margin: 0 0 0.5rem; }
    .rt-btn {
      padding: 0.35rem 0.9rem; font-size: 0.9rem; border-radius: 999px; cursor: pointer;
      background: none; border: 1px solid #333; color: #ccc; font-family: inherit; line-height: 1.2;
    }
    .rt-btn:hover { border-color: #888; color: #fff; }
    .rt-btn.on { background: #06251b; border-color: #34d399; color: #34d399; font-weight: 700; }
    /* The body itself: a contenteditable wearing the textarea's old clothes. */
    .rt-body {
      width: 100%; min-height: 40vh; background: #1a1a1a; border: 1px solid #333; border-radius: 8px;
      padding: 0.75rem; color: #eee; font-family: inherit; font-size: 16px; line-height: 1.5;
      overflow-wrap: anywhere;
    }
    .rt-body:focus { outline: none; border-color: #34d399; }
    .rt-body:empty::before { content: attr(data-placeholder); color: #666; }
    .rt-body blockquote {
      margin: 0.5rem 0; padding: 0.15rem 0 0.15rem 0.9rem;
      border-left: 3px solid #34d399; color: #dfe; font-style: italic;
    }
    .rt-body ul, .rt-body ol { margin: 0.4rem 0 0.4rem 1.3rem; }
    .rt-body .rt-meta { color: #888; font-size: 0.8rem; margin: 0.15rem 0 0.6rem; font-style: normal; }
    /* Quote window — the same shape as the calendars/folders managers. */
    .rt-modal {
      background: #1c1c1c; border: 1px solid #333; border-radius: 12px; padding: 1rem;
      width: min(420px, 92vw); max-height: 88vh; overflow: auto;
    }
    .rt-modal h2 { font-size: 1rem; margin: 0 0 0.75rem; }
    .rt-modal label { display: block; font-size: 0.8rem; color: #888; margin: 0.6rem 0 0.2rem; }
    .rt-modal textarea, .rt-modal input[type=text] {
      width: 100%; background: #111; border: 1px solid #333; border-radius: 8px; color: #eee;
      padding: 0.5rem 0.7rem; font-family: inherit; font-size: 16px; resize: vertical;
    }
    .rt-modal textarea:focus, .rt-modal input:focus { outline: none; border-color: #34d399; }
    .rt-stamp {
      margin-top: 0.8rem; padding: 0.35rem 0.9rem; font-size: 0.9rem; border-radius: 999px;
      background: none; border: 1px solid #333; color: #777; cursor: pointer; font-family: inherit;
    }
    .rt-stamp[aria-pressed="true"] { background: #06251b; border-color: #34d399; color: #34d399; font-weight: 700; }
    .rt-modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; }
    .rt-modal-actions button {
      padding: 0.35rem 0.9rem; font-size: 0.9rem; border-radius: 999px; cursor: pointer; font-family: inherit;
    }
    .rt-cancel { background: none; border: 1px solid #444; color: #ccc; }
    .rt-insert { background: #34d399; border: none; color: #06251b; font-weight: 700; }
    CSS;
}

/**
 * Wire the toolbar to the contenteditable. The hidden input is what actually posts, so
 * every change mirrors into it and fires an `input` event — that's what the apps'
 * existing autosave is already listening for.
 */
function rt_script(): string
{
    return <<<'JS'
<script>(function () {
  const body = document.querySelector('.rt-body');
  const hidden = document.querySelector('input.rt-value');
  if (!body || !hidden) { return; }

  const sync = () => {
    hidden.value = body.innerHTML;
    hidden.dispatchEvent(new Event('input', { bubbles: true }));   // hands off to autosave
  };
  body.addEventListener('input', sync);

  // Paste as text: keeps Word/Safari markup out of the body, and means a paste lands in
  // whatever formatting the caret is already in (which is the point of the quote button).
  body.addEventListener('paste', (e) => {
    e.preventDefault();
    const t = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, t);
  });

  // The blockquote the caret is in, if any.
  const quoteAt = () => {
    let n = document.getSelection().anchorNode;
    while (n && n !== body) { if (n.nodeName === 'BLOCKQUOTE') { return n; } n = n.parentNode; }
    return null;
  };
  const inQuote = () => quoteAt() !== null;

  /**
   * Take the caret's line back out of the quote. execCommand('formatBlock','div') is
   * supposed to do this and doesn't reliably strip a blockquote, so the line is lifted
   * out by hand: anything above and below it stays quoted, the line itself doesn't.
   */
  const unquote = () => {
    const bq = quoteAt();
    if (!bq) { return false; }
    const sel = document.getSelection();
    // Which of the quote's own children holds the caret? (No element child = the
    // whole quote is one line.)
    let line = sel.anchorNode;
    while (line && line.parentNode !== bq) { line = line.parentNode; }
    const kids = [...bq.childNodes];

    const out = document.createElement('div');
    if (!line || kids.length <= 1) {          // one line: the whole quote comes out
      while (bq.firstChild) { out.appendChild(bq.firstChild); }
      bq.replaceWith(out);
    } else {
      const i = kids.indexOf(line);
      const rest = kids.slice(i + 1);
      out.appendChild(line);
      bq.after(out);
      if (rest.length) {                      // keep what was below it quoted
        const tail = document.createElement('blockquote');
        rest.forEach(n => tail.appendChild(n));
        out.after(tail);
      }
      if (!bq.childNodes.length) { bq.remove(); }
    }
    const r = document.createRange();
    r.selectNodeContents(out);
    r.collapse(false);
    sel.removeAllRanges(); sel.addRange(r);
    return true;
  };

  const toolbar = document.querySelector('.rt-toolbar');
  toolbar.addEventListener('mousedown', (e) => { if (e.target.closest('.rt-btn')) { e.preventDefault(); } });
  toolbar.addEventListener('click', (e) => {
    const btn = e.target.closest('.rt-btn[data-cmd]');
    if (!btn) { return; }
    body.focus();
    if (btn.dataset.cmd === 'quote') { if (!unquote()) { document.execCommand('formatBlock', false, 'blockquote'); } }
    else { document.execCommand(btn.dataset.cmd, false, null); }
    sync(); refresh();
  });

  // Light the buttons that describe where the caret is.
  const refresh = () => {
    toolbar.querySelectorAll('.rt-btn[data-cmd]').forEach(b => {
      const c = b.dataset.cmd;
      let on = false;
      try { on = c === 'quote' ? inQuote() : document.queryCommandState(c); } catch (_) {}
      b.classList.toggle('on', on);
    });
  };
  document.addEventListener('selectionchange', () => { if (document.activeElement === body) { refresh(); } });
  body.addEventListener('focus', refresh);

  // ---- Quote window (bookshelf only; absent elsewhere) ----
  const openBtn = document.getElementById('rtEntryBtn');
  const modal = document.getElementById('rtEntryModal');
  if (!openBtn || !modal) { return; }
  const q = document.getElementById('rtQuote'), n = document.getElementById('rtNote'),
        p = document.getElementById('rtPage'), stamp = document.getElementById('rtStamp');
  const close = () => { modal.classList.remove('open'); };

  // Where the caret was before the window stole focus, so the entry lands where you
  // were typing rather than at the top of the note.
  let savedRange = null;
  const saveRange = () => {
    const s = document.getSelection();
    if (s.rangeCount && body.contains(s.anchorNode)) { savedRange = s.getRangeAt(0).cloneRange(); }
  };
  body.addEventListener('keyup', saveRange);
  body.addEventListener('mouseup', saveRange);
  body.addEventListener('blur', saveRange);

  openBtn.addEventListener('click', () => {
    saveRange();
    q.value = n.value = p.value = '';
    stamp.setAttribute('aria-pressed', 'false');
    modal.classList.add('open');
    q.focus();
  });
  stamp.addEventListener('click', () => {
    stamp.setAttribute('aria-pressed', stamp.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
  });
  document.getElementById('rtEntryCancel').addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) { close(); } });

  const esc = (s) => s.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  const lines = (s) => esc(s.trim()).replace(/\n/g, '<br>');

  document.getElementById('rtEntryInsert').addEventListener('click', () => {
    const quote = q.value.trim(), note = n.value.trim(), page = p.value.trim();
    const stamped = stamp.getAttribute('aria-pressed') === 'true';
    if (!quote && !note) { close(); return; }
    const meta = [];
    if (page) { meta.push('p. ' + esc(page)); }
    if (stamped) {
      const d = new Date();
      meta.push(d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
              + ' ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
    }
    let html = '';
    if (quote) { html += '<blockquote class="rt-quote">' + lines(quote) + '</blockquote>'; }
    if (note)  { html += '<div>' + lines(note) + '</div>'; }
    if (meta.length) { html += '<div class="rt-meta">' + meta.join(' · ') + '</div>'; }
    html += '<div><br></div>';   // somewhere to carry on typing, outside the quote

    body.focus();
    if (savedRange) {
      const s = document.getSelection();
      s.removeAllRanges(); s.addRange(savedRange);
    }
    document.execCommand('insertHTML', false, html);
    sync();
    close();
  });
})();</script>
JS;
}
