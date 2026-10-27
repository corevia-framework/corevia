<p class="eyebrow">DATABASE CONNECTIONS</p><h1>Choose where<br><span>your data lives.</span></h1><p class="lede">Start with local JSON. Connect SQL through PDO or use MongoDB when your application needs it. Connections open when you use them.</p>
<div class="database-grid"><section class="card"><span class="tag">SQL</span><h2>MySQL · PostgreSQL · SQLite</h2><p>Named PDO connections, prepared queries, transactions, and a small query builder.</p><pre>$db-&gt;sql('mysql')
   -&gt;table('users')
   -&gt;where('active', 1)
   -&gt;limit(20)-&gt;get();</pre><code>config/settings/database.php</code></section><section class="card"><span class="tag">DOCUMENTS</span><h2>MongoDB · local JSON</h2><p>Read and write JSON-compatible documents using explicit collection names and string IDs.</p><pre>$documents = $db-&gt;documents('local');
$documents-&gt;put('notes', 'first', [
    'title' =&gt; 'Hello, Corevia',
]);</pre><p class="muted">MongoDB uses the optional PHP mongodb extension.</p></section></div>
<section class="section-heading"><h2>Configured connections</h2><span class="muted">CONFIGURATION ONLY · NO SERVER PROBE</span></section><div class="table-wrap"><table><thead><tr><th>NAME</th><th>DRIVER</th><th>STATE</th></tr></thead><tbody><?php foreach($connections as $name=>$connection): ?><tr><td><?= $e($name) ?></td><td><code><?= $e($connection['driver']) ?></code></td><td><?= $connection['connected']?'Opened':'Available on demand' ?></td></tr><?php endforeach; ?></tbody></table></div><p class="note">Set credentials in your process environment, then clear or rebuild cached configuration. See <code>docs/v2/databases.md</code> for connection, schema, and deployment examples.</p>
