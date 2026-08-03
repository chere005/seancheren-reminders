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
<p><a class="gitlink" href="https://github.com/chere005/CalMind"><svg class="giticon" viewBox="0 0 16 16" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>CalMind on GitHub</a></p>
<style>
  .gitlink { display: inline-flex; align-items: center; gap: 0.45rem; }
  .giticon { flex: none; }
</style>

<h2>Private</h2>
<h3>Work</h3>
<p>I spend most of my time working remotely at Wolfram Research where I get to work on really cool projects with really cool people.</p>
<h3>Music</h3>
<p>I probably wish this were my focused project, and something I did more publicly, but for now I'm just trying to practice some drums, studio work, composition, etc. Maybe some day I'll finish a real project.</p>
<h3>Games</h3>
<p>I hope to play Tears of the Kingdom soon. Haven't played MTG much since Phyrexia, but I hope to get back into that and venture into commander some day.. I'm also hoping to spend a bit more time doing part of the making of games; all of the art and storytelling is authored by my best friend and partner. We've also been working through catching the first 150 pokemon in Red and Blue.</p>
<?php
site_page('projects', 'Projects', ob_get_clean());
