<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';

ob_start();
?>
<h1>About</h1>
<p>I'm Sean Cheren, I work on software projects and play music and games in my spare time..</p>
<p>Some current favorites that come to mind:</p>

<h3>Music</h3>
<div class="lists-col">
<ul>
  <li>King Gizzard and the Lizard Wizard</li>
  <li>Mars Volta</li>
  <li>Dream Theater</li>
  <li>Led Zeppelin</li>
  <li>Amon Amarth</li>
</ul>
</div>

<h3>Games</h3>
<div class="lists-col">
<ul>
  <li>Outer Wilds</li>
  <li>Dark Souls (I)</li>
  <li>Sekiro</li>
  <li>Demon's Souls</li>
  <li>Morrowind</li>
  <li>Oblivion</li>
  <li>The Legend of Zelda</li>
  <li>Breath of the Wild</li>
  <li>Metal Gear Solid: Tactical Espionage</li>
  <li>Metal Gear Solid: Sons of Liberty</li>
  <li>Returnal</li>
  <li>Inscryption</li>
  <li>Slay the Spire</li>
  <li>Star Realms</li>
</ul>
</div>
<?php
site_page('about', 'About', ob_get_clean());
