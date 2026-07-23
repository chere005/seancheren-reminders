<?php
/** Small shared helpers used by more than one app. */

/** Pull a time like "2pm" / "2:30 pm" out of text; returns [cleanedText, "HH:MM"|null]. */
function parse_time_from_text(string $text): array
{
    if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*([apAP])\.?[mM]\.?\b/', $text, $m)) {
        $h   = (int) $m[1];
        $min = ($m[2] ?? '') !== '' ? (int) $m[2] : 0;
        $ap  = strtolower($m[3]);
        if ($h >= 1 && $h <= 12 && $min < 60) {
            if ($ap === 'p' && $h < 12) { $h += 12; }
            if ($ap === 'a' && $h === 12) { $h = 0; }
            $clean = trim(preg_replace('/\s{2,}/', ' ', str_replace($m[0], '', $text)));
            return [$clean, sprintf('%02d:%02d', $h, $min)];
        }
    }
    return [$text, null];
}
