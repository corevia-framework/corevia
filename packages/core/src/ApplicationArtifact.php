<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Private deterministic build/config artifact. Keys live separately from the artifact. */
final readonly class ApplicationArtifact
{
    private function __construct(private array $document) {}
    public static function defaultSources(string $basePath): array
    {
        return array_values(array_filter(['app','packages','config','routes','contracts','autoload.php','composer.json'], static fn(string $path):bool=>file_exists($basePath.'/'.$path)));
    }
    public static function build(Corevia $app, string $environment, array $sourcePaths, string $key): self
    {
        self::key($key);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $environment)) { throw new \InvalidArgumentException('Invalid artifact environment.'); }
        foreach ($app->registry->health() as $health) { if ($health['status'] !== 'healthy' && $health['status'] !== 'disabled') { throw new \LogicException('Cannot compile an unhealthy application.'); } }
        $sources = self::sources($app->basePath, $sourcePaths);
        $payload = ['format' => 1, 'environment' => $environment, 'framework' => Version::VERSION, 'graph' => $app->graph(), 'configuration' => $app->effectiveConfiguration(), 'sources' => $sources];
        return new self(['payload' => $payload, 'fingerprint' => hash_hmac('sha256', CanonicalJson::encode($payload), $key)]);
    }
    private static function key(string $key): void { if (strlen($key) < 32) { throw new \InvalidArgumentException('Artifact signing key must contain at least 32 bytes.'); } }
    private static function sources(string $basePath, array $paths): array
    {
        $root = realpath($basePath); if ($root === false) { throw new \InvalidArgumentException('Invalid application root.'); }
        if ($paths === []) { throw new \InvalidArgumentException('Declare source roots/files for the build.'); }
        $files = [];
        foreach ($paths as $path) {
            if (!is_string($path) || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/(^|\/)\.\.(\/|$)|:/', $path)) { throw new \InvalidArgumentException('Source paths must be relative to the application.'); }
            $partPath=$root;foreach(explode('/',$path) as $part){$partPath.='/'.$part;if(is_link($partPath)){throw new \InvalidArgumentException('Source symlinks are not supported.');}}
            $resolved = realpath($root . '/' . $path);
            if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) { throw new \InvalidArgumentException('Source escapes or is missing: ' . $path); }
            $candidates = is_dir($resolved) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) : [new \SplFileInfo($resolved)];
            foreach ($candidates as $file) {
                if ($file->isLink()) { throw new \InvalidArgumentException('Source symlinks are not supported.'); }
                if (!$file->isFile()) { continue; }
                $real = $file->getRealPath();
                if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || $file->isLink()) { throw new \InvalidArgumentException('Source symlinks are not supported.'); }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
                $hash=hash_file('sha256',$real);if($hash===false){throw new \RuntimeException('Cannot read declared source.');}$files[$relative]=$hash;
            }
        }
        if (is_file($root . '/composer.lock')) {
            if (is_link($root . '/composer.lock')) { throw new \InvalidArgumentException('Dependency lock symlinks are not supported.'); }
            $files['composer.lock'] = hash_file('sha256', $root . '/composer.lock');
        }
        ksort($files, SORT_STRING); return $files;
    }
    public function write(string $path): void
    {
        $wire = CanonicalJson::encode($this->document);
        if (strlen($wire) > 4194304) { throw new \LengthException('Build artifact exceeds 4MiB.'); }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try { if (file_put_contents($temporary, $wire, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) { throw new \RuntimeException('Cannot write private artifact.'); } }
        finally { if (is_file($temporary)) { unlink($temporary); } }
    }
    public static function load(string $path, string $key, string $environment): self
    {
        self::key($key);
        if (!is_file($path) || filesize($path) > 4194304) { throw new \InvalidArgumentException('Missing or oversized artifact.'); }
        $document = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $payload = $document['payload'] ?? null;
        if (!is_array($payload) || ($payload['format'] ?? null) !== 1 || ($payload['environment'] ?? null) !== $environment || ($payload['framework'] ?? null) !== Version::VERSION || !is_string($document['fingerprint'] ?? null) || !hash_equals(hash_hmac('sha256', CanonicalJson::encode($payload), $key), $document['fingerprint'])) { throw new \InvalidArgumentException('Artifact integrity, framework or environment mismatch.'); }
        return new self($document);
    }
    public function configuration(): ConfigRepository { return new ConfigRepository($this->document['payload']['configuration']['application']); }
    public function fingerprint(): string { return $this->document['fingerprint']; }
    /** Offline source check; runtime config/graph still require verify(). */
    public function verifySources(string $basePath,array $paths):void{if(self::sources($basePath,$paths)!==$this->document['payload']['sources']){throw new \LogicException('Declared application sources changed.');}}
    /** Safe to print: no resolved configuration or secret-derived unkeyed hashes. */
    public function summary(): array
    {
        $payload = $this->document['payload'];
        return ['format' => 1, 'framework' => $payload['framework'], 'environment' => $payload['environment'], 'fingerprint' => $this->fingerprint(), 'modules' => $payload['graph']['plan']['order'], 'source_files' => count($payload['sources'])];
    }
    public function verify(Corevia $app, array $sourcePaths, string $key): void
    {
        $current = self::build($app, $this->document['payload']['environment'], $sourcePaths, $key);
        if (!hash_equals($current->fingerprint(), $this->fingerprint())) { throw new \LogicException('Application source, graph, dependency lock or configuration changed.'); }
    }
}
