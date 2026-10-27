<p class="eyebrow">A LIVE PARAMETER ROUTE</p><h1>Hello,<br><span><?= $e($name) ?>.</span></h1><p class="lede">This page came from a named route, a controller, and a PHP view. The URL parameter became the controller's <code>$name</code> argument.</p><div class="code-panel"><div><h2>Follow the request</h2><p>Try changing the final part of the URL to your name.</p></div><pre>/hello/<?= $e($name) ?>
    → routes/web.php
    → HomeController::hello()
    → resources/views/hello.php</pre></div><a class="button primary" href="<?= $e($route('guide')) ?>">Explore routing ↗</a>
