<?php
// Locate the shared lib/ — local dev (../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';

ob_start();
?>
<h1>Hello!</h1>
<p>Thanks to my good friend claudio (I really just like the nickname, I'm still rather agnostic to models and more concerned with agent harnesses, but I digress), apparently spinning up web applications and apps is incredibly trivial to vibe code slop that somehow seems to work in testing!</p>
<p>Check out the <a href="/projects/">projects page</a> to see what I felt like posting that I'm clawing at time to work on..</p>
<p>And.. if you poke around, you might find some demo projects sitting at places like <a href="/chat/">my url/chat</a> or <a href="/reminders/">url/reminders</a>.</p>
<p>Try not to blast through my (extremely low) server budget :)</p>
<div class="sig">
  <div>&mdash;S</div>
  <div class="date">July 26, 2026</div>
</div>
<?php
site_page('', 'Home', ob_get_clean());
