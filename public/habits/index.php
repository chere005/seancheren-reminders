<?php
// The suite moved under /calmind/ — send old bookmarks, home-screen icons and widget
// scripts on, keeping the instance prefix (/test/, /dev/) and any query string.
header('Location: ' . preg_replace('#/habits#', '/calmind/habits', $_SERVER['REQUEST_URI'] ?? '/habits/', 1), true, 301);
exit;
