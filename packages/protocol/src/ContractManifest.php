<?php
declare(strict_types=1);
namespace Corevia\Protocol;
use Corevia\Core\CanonicalJson;

/** Versioned application contracts using an explicitly supported JSON Schema subset. */
final readonly class ContractManifest
{
    private function __construct(private array $data) {}
    public static function load(string $path): self
    {
        if (!is_file($path) || filesize($path) > 1048576) { throw new \InvalidArgumentException('Missing or oversized contract manifest.'); }
        return self::fromArray(json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR));
    }
    public static function fromArray(array $data): self
    {
        self::keys($data, ['format','name','version','$defs','actions','events','errors','auth']);
        if (($data['format'] ?? null) !== 1 || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $data['name'] ?? '') || !preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $data['version'] ?? '')) { throw new \InvalidArgumentException('Invalid contract identity or format.'); }
        foreach (['$defs','actions','events','errors','auth'] as $key) { $data[$key] ??= []; if (!is_array($data[$key]) || ($data[$key] !== [] && array_is_list($data[$key]))) { throw new \InvalidArgumentException('Contract sections must be maps.'); } }
        foreach ($data['auth'] as $name => $auth) {
            self::name($name); self::keys($auth, ['type','scopes']);
            if (!in_array($auth['type'] ?? null, ['session','bearer'], true)) { throw new \InvalidArgumentException('Unknown authentication scheme type.'); }
            self::names($auth['scopes'] ?? []);
        }
        foreach ($data['errors'] as $code => $error) {
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $code)) { throw new \InvalidArgumentException('Invalid error code.'); }
            self::keys($error, ['message','retryable']);
            if (!is_string($error['message'] ?? null) || !is_bool($error['retryable'] ?? null)) { throw new \InvalidArgumentException('Error metadata is required.'); }
        }
        foreach ($data['$defs'] as $name => $schema) { if (!preg_match('/^[A-Z][A-Za-z0-9_]{0,63}$/D', $name)) { throw new \InvalidArgumentException('Named schema types must use PascalCase identifiers.'); } self::schema($schema, $data['$defs'], [$name]); }
        foreach (['actions','events'] as $section) {
            foreach ($data[$section] as $name => $definition) {
                self::name($name); self::keys($definition, $section === 'actions' ? ['input','output','auth','errors'] : ['payload','auth']);
                if (!is_array($definition['auth'] ?? []) || !array_is_list($definition['auth'] ?? [])) { throw new \InvalidArgumentException('Authentication requirements must be a list.'); }
                foreach ($section === 'actions' ? ['input','output'] : ['payload'] as $key) { if (!isset($definition[$key])) { throw new \InvalidArgumentException('Missing contract schema: ' . $name); } self::schema($definition[$key], $data['$defs']); }
                foreach ($definition['auth'] ?? [] as $requirement) {
                    self::keys($requirement, ['scheme','scopes']); $scheme = $data['auth'][$requirement['scheme'] ?? ''] ?? null;
                    if ($scheme === null) { throw new \InvalidArgumentException('Unknown required authentication scheme.'); }
                    self::names($requirement['scopes'] ?? []);
                    if (array_diff($requirement['scopes'] ?? [], $scheme['scopes'] ?? [])) { throw new \InvalidArgumentException('Unknown authentication scope.'); }
                }
                if ($section === 'actions') { self::names($definition['errors'] ?? []); foreach ($definition['errors'] ?? [] as $code) { if (!isset($data['errors'][$code])) { throw new \InvalidArgumentException('Undeclared action error.'); } } }
            }
        }
        // Also rejects non-finite numbers, cycles and invalid UTF-8 in metadata.
        CanonicalJson::encode($data);
        return new self(json_decode(CanonicalJson::encode($data), true, 64, JSON_THROW_ON_ERROR));
    }
    private static function keys(array $value, array $allowed): void { if (array_diff(array_keys($value), $allowed)) { throw new \InvalidArgumentException('Unsupported contract/schema field.'); } }
    private static function name(string $name): void { if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.:-]{0,127}$/D', $name)) { throw new \InvalidArgumentException('Invalid contract name.'); } }
    private static function names(array $names): void { if (!array_is_list($names) || count(array_unique($names, SORT_REGULAR)) !== count($names)) { throw new \InvalidArgumentException('References must be unique lists.'); } foreach ($names as $name) { if (!is_string($name)) { throw new \InvalidArgumentException('Reference names must be strings.'); } self::name($name); } }
    private static function schema(array $schema, array $defs, array $stack = [], int $depth = 0): void
    {
        if ($depth > 40) { throw new \InvalidArgumentException('Schema nesting exceeds the limit.'); }
        self::keys($schema, ['$ref','type','properties','required','additionalProperties','items','enum','minLength','maxLength','minimum','maximum','minItems','maxItems','description','title']);
        foreach(['description','title'] as $annotation){if(isset($schema[$annotation]) && !is_string($schema[$annotation])){throw new \InvalidArgumentException('Schema annotations must be strings.');}}
        if (isset($schema['$ref'])) {
            self::keys($schema, ['$ref','description','title']);
            if (!is_string($schema['$ref']) || !preg_match('~^#/\$defs/([A-Z][A-Za-z0-9_]*)$~D', $schema['$ref'], $match) || !isset($defs[$match[1]]) || in_array($match[1], $stack, true)) { throw new \InvalidArgumentException('Unknown or recursive schema reference.'); }
            self::schema($defs[$match[1]], $defs, [...$stack, $match[1]], $depth + 1); return;
        }
        $types = is_array($schema['type'] ?? null) ? $schema['type'] : [$schema['type'] ?? null];
        if ($types === [] || count(array_unique($types, SORT_REGULAR)) !== count($types) || array_diff($types, ['object','array','string','integer','number','boolean','null'])) { throw new \InvalidArgumentException('Explicit supported schema types required.'); }
        foreach (['description','title'] as $key) { if (isset($schema[$key]) && !is_string($schema[$key])) { throw new \InvalidArgumentException('Schema annotations must be strings.'); } }
        foreach (['object'=>['properties','required','additionalProperties'], 'array'=>['items','minItems','maxItems'], 'string'=>['minLength','maxLength']] as $type => $keys) { foreach ($keys as $key) { if (array_key_exists($key, $schema) && !in_array($type, $types, true)) { throw new \InvalidArgumentException('Schema constraint does not apply to its type.'); } } }
        if ((isset($schema['minimum']) || isset($schema['maximum'])) && !array_intersect($types, ['number','integer'])) { throw new \InvalidArgumentException('Numeric constraints require numeric types.'); }
        foreach (['minLength','maxLength','minItems','maxItems'] as $key) { if (isset($schema[$key]) && (!is_int($schema[$key]) || $schema[$key] < 0 || $schema[$key] > 1048576)) { throw new \InvalidArgumentException('Invalid schema limit.'); } }
        foreach (['minimum','maximum'] as $key) { if (isset($schema[$key]) && ((!is_int($schema[$key]) && !is_float($schema[$key])) || !is_finite((float)$schema[$key]))) { throw new \InvalidArgumentException('Invalid numeric limit.'); } }
        foreach ([['minLength','maxLength'],['minItems','maxItems'],['minimum','maximum']] as [$min,$max]) { if (isset($schema[$min],$schema[$max]) && $schema[$min] > $schema[$max]) { throw new \InvalidArgumentException('Inverted schema limits.'); } }
        if (in_array('object', $types, true)) {
            if (!is_array($schema['properties'] ?? [])) { throw new \InvalidArgumentException('Properties must be a schema map.'); }
            if (isset($schema['additionalProperties']) && !is_bool($schema['additionalProperties'])) { throw new \InvalidArgumentException('additionalProperties must be boolean.'); }
            self::names($schema['required'] ?? []);
            if (array_diff($schema['required'] ?? [], array_keys($schema['properties'] ?? []))) { throw new \InvalidArgumentException('Required field has no schema.'); }
            foreach ($schema['properties'] ?? [] as $name => $child) { self::name($name); self::schema($child, $defs, $stack, $depth + 1); }
        }
        if (in_array('array', $types, true)) { if (!isset($schema['items'])) { throw new \InvalidArgumentException('Array items schema required.'); } self::schema($schema['items'], $defs, $stack, $depth + 1); }
        if (isset($schema['enum'])) {
            if (!is_array($schema['enum']) || !array_is_list($schema['enum']) || $schema['enum'] === []) { throw new \InvalidArgumentException('Enum must be a nonempty list.'); }
            foreach ($schema['enum'] as $value) { if ((!is_scalar($value) && $value !== null) || is_float($value) || (is_int($value) && abs($value) > 9007199254740991)) { throw new \InvalidArgumentException('Enums support portable scalar literals.'); } }
        }
    }
    public function data(): array { return $this->data; }
    public function json(): string
    {
        $data = $this->data;
        $schema = function (array $value) use (&$schema): array {
            if (isset($value['properties'])) { foreach ($value['properties'] as $key => $child) { $value['properties'][$key] = $schema($child); } $value['properties'] = (object)$value['properties']; }
            if (isset($value['items'])) { $value['items'] = $schema($value['items']); } return $value;
        };
        foreach ($data['$defs'] as $name => $definition) { $data['$defs'][$name] = $schema($definition); }
        foreach ($data['actions'] as &$action) { $action['input'] = $schema($action['input']); $action['output'] = $schema($action['output']); } unset($action);
        foreach ($data['events'] as &$event) { $event['payload'] = $schema($event['payload']); } unset($event);
        foreach (['$defs','actions','events','errors','auth'] as $key) { $data[$key] = (object)$data[$key]; }
        return CanonicalJson::encode($data);
    }
    public function fingerprint(): string { return hash('sha256', $this->json()); }
    public function actions(): array { return array_keys($this->data['actions']); }
    public function requirements(string $action): array { return ($this->data['actions'][$action] ?? throw new ContractViolation('Unknown action.'))['auth'] ?? []; }
    public function assertInput(string $action, mixed $value): void { $this->validate(($this->data['actions'][$action] ?? throw new ContractViolation('Unknown action.'))['input'], $value); }
    public function assertOutput(string $action, mixed $value): void { $this->validate(($this->data['actions'][$action] ?? throw new ContractViolation('Unknown action.'))['output'], $value); }
    public function assertEvent(string $event, mixed $value): void { $this->validate(($this->data['events'][$event] ?? throw new ContractViolation('Unknown event.'))['payload'], $value); }
    public function assertError(string $action, string $code, bool $retryable): void
    {
        if (!in_array($code, $this->data['actions'][$action]['errors'] ?? [], true) || $this->data['errors'][$code]['retryable'] !== $retryable) { throw new ContractViolation('Undeclared action error contract.'); }
    }
    private function validate(array $schema, mixed $value, int $depth = 0): void
    {
        if ($depth > 60) { throw new ContractViolation('Contract nesting exceeds limit.'); }
        if (isset($schema['$ref'])) { $this->validate($this->data['$defs'][substr($schema['$ref'], 8)], $value, $depth + 1); return; }
        $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']]; $matches = false;
        foreach ($types as $type) {
            $valid = match ($type) {
                'object' => $value instanceof \stdClass || (is_array($value) && !array_is_list($value)),
                'array' => is_array($value) && array_is_list($value), 'string' => is_string($value) && preg_match('//u', $value) === 1,
                'integer' => (is_int($value) || is_float($value)) && is_finite((float)$value) && floor((float)$value) === (float)$value && abs($value) <= 9007199254740991,
                'number' => (is_int($value) || is_float($value)) && is_finite((float)$value), 'boolean' => is_bool($value), 'null' => $value === null,
            }; if ($valid) { $matches = true; break; }
        }
        if (!$matches) { throw new ContractViolation('Contract type mismatch.'); }
        if (isset($schema['enum'])) { $found = false; foreach ($schema['enum'] as $item) { if ($value === $item || ((is_int($value) || is_float($value)) && is_int($item) && $value == $item)) { $found = true; break; } } if (!$found) { throw new ContractViolation('Contract enum mismatch.'); } }
        if (is_string($value)) { $length = preg_match_all('/./us', $value); if ($length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? PHP_INT_MAX)) { throw new ContractViolation('Contract string length mismatch.'); } }
        if (is_int($value) || is_float($value)) { if ($value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF)) { throw new ContractViolation('Contract numeric bound mismatch.'); } }
        if ($value instanceof \stdClass || (is_array($value) && !array_is_list($value))) {
            $fields = (array)$value;
            foreach ($schema['required'] ?? [] as $key) { if (!array_key_exists($key, $fields)) { throw new ContractViolation('Required contract field is missing.'); } }
            foreach ($fields as $key => $item) {
                if (isset($schema['properties'][$key])) { $this->validate($schema['properties'][$key], $item, $depth + 1); }
                elseif (!($schema['additionalProperties'] ?? false)) { throw new ContractViolation('Unexpected contract field.'); }
                else { $this->jsonValue($item, $depth + 1); }
            }
        } elseif (is_array($value)) {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) { throw new ContractViolation('Contract array length mismatch.'); }
            foreach ($value as $item) { $this->validate($schema['items'], $item, $depth + 1); }
        }
    }
    private function jsonValue(mixed $value, int $depth): void
    {
        if ($depth > 60 || (is_object($value) && !$value instanceof \stdClass) || is_resource($value) || (is_float($value) && !is_finite($value))) { throw new ContractViolation('Non-JSON contract value.'); }
        if (is_array($value) || $value instanceof \stdClass) { foreach ($value as $item) { $this->jsonValue($item, $depth + 1); } }
        elseif (is_string($value) && preg_match('//u', $value) !== 1) { throw new ContractViolation('Invalid UTF-8.'); }
    }
}
