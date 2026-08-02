<?php
// The suite moved under /calmind/ — send old bookmarks, home-screen icons and widget
// scripts on, keeping the instance prefix (/test/, /dev/) and any query string.
header('Location: ' . preg_replace('#/add#', '/calmind/add', $_SERVER['REQUEST_URI'] ?? '/add/', 1), true, 301);
exit;
