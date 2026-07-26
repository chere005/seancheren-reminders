<?php
// Locate the shared lib/ — local dev (../../lib) or NFSN (/home/protected/lib).
$__libDir = null;
foreach ([__DIR__ . '/../../lib', '/home/protected/lib'] as $__c) {
    if (is_file($__c . '/site.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/site.php';

ob_start();
?>
<h1>Projects</h1>

<h2>Public</h2>
<h3>Vibe Coding Apps</h3>
<p>Well, sort of. There's links to the apps I'm vibe coding (it's probably pure slop), but this code will end up on my public github once I wait for a human (me) to review that I'm not posting API keys or passwords.</p>

<h2>Private</h2>
<h3>Work</h3>
<p>I spend most of my time working remotely at Wolfram Research where I get to work on really cool projects with really cool people.</p>
<h3>Music</h3>
<p>I probably wish this were my focused project, and something I did more publicly, but for now I'm just trying to practice some drums, studio work, composition, etc. Maybe some day I'll finish a real project.</p>
<h3>Games</h3>
<p>I hope to play Tears of the Kingdom soon. Haven't played MTG much since Phyrexia, but I hope to get back into that and venture into commander some day.. I'm also hoping to spend a bit more time doing part of the making of games; all of the art and storytelling is authored by my best friend and partner. We've also been working through catching the first 150 pokemon in Red and Blue.</p>
<?php
site_page('projects', 'Projects', ob_get_clean());
