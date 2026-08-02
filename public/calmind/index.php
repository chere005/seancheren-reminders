<?php
// /calmind/ itself is no app — land on the Calendar (LOGIN_LANDING's target), keeping
// the instance prefix (/test/, /dev/) and any query string.
header('Location: ' . preg_replace('#/calmind/?(\?|$)#', '/calmind/calendar/$1', $_SERVER['REQUEST_URI'] ?? '/calmind/', 1), true, 301);
exit;
