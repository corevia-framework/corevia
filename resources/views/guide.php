<p class="eyebrow">ROUTES & VIEWS</p><h1>Your URL.<br><span>Your application.</span></h1><p class="lede">Routes describe the request. Controllers prepare the response. Views present it.</p>
<div class="guide-grid"><section class="card"><span class="number">01 · ROUTE</span><h2>Choose an address</h2><pre>$route-&gt;get('/hello/{name}',
    [HomeController::class, 'hello']
)-&gt;name('hello');</pre><p>Edit <code>routes/web.php</code>. Use get, post, put, patch, and delete for explicit HTTP methods.</p></section><section class="card"><span class="number">02 · CONTROLLER</span><h2>Prepare your response</h2><pre>public function hello(Request $request,
    string $name): Response
{
    return Response::html(
        $this-&gt;view-&gt;render('hello',
            ['name' =&gt; $name])
    );
}</pre><p>Controllers receive services through constructor injection. Route parameters map to method arguments by name.</p></section><section class="card"><span class="number">03 · VIEW</span><h2>Give the data a home</h2><pre>&lt;h1&gt;Hello, &lt;?= $e($name) ?&gt;&lt;/h1&gt;</pre><p>Edit <code>resources/views/hello.php</code>. Use <code>$e()</code> for values that appear in HTML.</p></section><section class="card"><span class="number">04 · GROUP</span><h2>Keep routes together</h2><pre>$router-&gt;group('/admin', function ($r) {
    $r-&gt;get('/reports', $handler)
      -&gt;name('reports');
}, [$auth], 'admin.');</pre><p>Group middleware protects every route in the group. Resource routes can map seven conventional controller actions.</p></section></div>
<section class="section-heading"><h2>Routes in this starter</h2><code>php corevia route:list</code></section><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>PATH</th><th>NAME</th></tr></thead><tbody><?php foreach($routes as $item): ?><tr><td><span class="method"><?= $e($item['method']) ?></span></td><td><code><?= $e($item['path']) ?></code></td><td><?= $e($item['name'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div>
