<?php
declare(strict_types=1);
namespace Corevia\Core;

/** Structural validation finishes before any module's register() is executed. */
final readonly class CompositionPlan implements \JsonSerializable
{
    private function __construct(public array $order, public array $definitions, public array $manifests) {}

    /** @param array<string, Module> $modules @param list<string> $disabled */
    public static function resolve(array $modules, array $disabled = []): self
    {
        ksort($modules, SORT_STRING);
        $definitions = []; $manifests = [];
        foreach ($modules as $id => $module) {
            $dependencies = $module->dependencies();
            $manifest = $module instanceof ManifestProvider ? $module->manifest() : new ModuleManifest($id, dependencies: $dependencies);
            sort($dependencies, SORT_STRING); $declared = $manifest->dependencies; sort($declared, SORT_STRING);
            if ($manifest->id !== $id || $declared !== $dependencies) { throw new \LogicException('Manifest disagrees with module: ' . $id); }
            $requires = $manifest->requires; sort($requires, SORT_STRING);
            $source = (new \ReflectionClass($module))->getFileName();
            $definitions[$id] = ['class' => $module::class, 'source_hash' => $source === false ? null : hash_file('sha256', $source), 'version' => $manifest->version, 'enabled' => !in_array($id, $disabled, true), 'dependencies' => $dependencies, 'requires' => $requires];
            $manifests[$id] = $manifest;
        }
        $order = []; $visited = []; $active = [];
        $visit = function (string $id) use (&$visit, &$order, &$visited, &$active, $definitions): void {
            if (isset($visited[$id])) { return; }
            if (isset($active[$id])) { throw new \LogicException('Circular composition dependency: ' . $id); }
            if (!isset($definitions[$id]) || !$definitions[$id]['enabled']) { throw new \LogicException('Unavailable composition dependency: ' . $id); }
            $active[$id] = true;
            foreach ($definitions[$id]['dependencies'] as $dependency) { $visit($dependency); }
            unset($active[$id]); $visited[$id] = true; $order[] = $id;
        };
        foreach ($definitions as $id => $definition) { if ($definition['enabled']) { $visit($id); } }
        return new self($order, $definitions, $manifests);
    }
    public function jsonSerialize(): array { return ['format' => 1, 'order' => $this->order, 'modules' => $this->definitions]; }
    public function fingerprint(): string { return CanonicalJson::hash($this->jsonSerialize()); }
}
