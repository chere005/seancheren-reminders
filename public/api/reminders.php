<?php
// The suite moved under /calmind/ — 301 with the instance prefix and query intact.
header('Location: ' . preg_replace('#/api/#', '/calmind/api/', $_SERVER['REQUEST_URI'] ?? '/api/reminders.php', 1), true, 301);
exit;
