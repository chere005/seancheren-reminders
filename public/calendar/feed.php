<?php
// The suite moved under /calmind/ — 301 with the instance prefix and query intact.
header('Location: ' . preg_replace('#/calendar/#', '/calmind/calendar/', $_SERVER['REQUEST_URI'] ?? '/calendar/feed.php', 1), true, 301);
exit;
