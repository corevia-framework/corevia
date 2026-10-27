# Learning the V2 kernel

Run from the repository root:

```powershell
php -n examples/v2/kernel.php
```

It prints `Welcome, Corevia developer`, the available capabilities, and the module hooks it visited. A second JSON result shows shutdown. No MySQL, SQLite, Composer installation, Node, or Python is needed for this example. Normal action logs go to standard error.

Read `examples/v2/kernel.php` from top to bottom. `GreetingPolicy` is a service containing a prefix. `Greet` receives that service through its constructor and adds a message to the flow state. `GreetingsModule` registers the action and its flow, declares configuration defaults, and binds the service during boot. The last lines create the application, execute the flow, and release resources.

## What happens when you boot

```text
create -> register modules -> boot modules -> ready modules -> application ready
                                                               |
                                                        execute actions/flows
                                                               |
application shutdown hooks -> module shutdown in reverse order -> shutdown
```

The kernel records `created`, `registering`, `booting`, `ready`, `shutting_down`, and `shutdown` in `$app->kernel->timeline()`. A kernel boot exception records `failed`. A second boot while ready does nothing. To restart a stopped application, create a new instance.

All registration finishes before module boot starts. Dependencies register, boot, and become ready before their dependents. `ModuleLifecycle` is optional: implement `boot`, `ready`, and `shutdown`, each receiving `ModuleContext`. During registration, use `actions`, `flows`, and `on`. Those registration methods are frozen afterward. During boot, initialize services and acquire resources. During ready, perform module initialization that needs other booted modules. Module ready hooks run before the action runtime is assembled; use the application `onReady` hook to execute flows.

Register application hooks before boot:

```php
$app->onReady(function (Corevia $app): void {
    // All healthy modules have booted; flow execution is now available.
});
$app->onShutdown(function (Corevia $app): void {
    // Flush application resources before module resources are released.
});
try {
    $app->boot();
    $result = $app->run('greet', ['name' => 'Ada']);
} finally {
    $cleanupErrors = $app->shutdown();
}
```

Application shutdown hooks run in reverse registration order, followed by module shutdown in reverse dependency order. Cleanup exceptions become returned `Error` objects and do not skip other hooks. The included HTTP and CLI adapters call shutdown at the end of execution. Custom workers must call it at the end of their lifetime, including after a boot exception. PHP process termination cannot guarantee cleanup.

A module hook failure disables that module and its transitive dependents while independent modules remain usable. Partially booted affected modules are cleaned up in reverse order. Inspect `$app->registry->health()` before accepting work that needs a particular module. A ready kernel means the surviving runtime is usable; it does not guarantee every module is healthy. Cleanup failures during failed-module rollback are recorded in module health and logs; normal shutdown returns errors from that shutdown operation.

## Manifests and services

Implement `ManifestProvider` to return a `ModuleManifest` with ID, version, dependencies, description, required capabilities, and configuration defaults. ID and dependencies must match the existing `Module` methods. V1 modules receive a synthesized manifest automatically.

The context exposes the application container and manifest. Existing constructor injection and `set` bindings still work. `bind($id, $factory)` creates a new object per resolution. `singleton($id, $factory)` creates one lazily and reuses it. Factories receive the container; class strings can serve as aliases. `has` checks explicit bindings. Factories must return objects, and circular service dependencies are rejected.

## Configuration

Pass a `ConfigRepository` to `Corevia::create(..., config: $config)`. Dot access such as `$config->get('app.name', 'fallback')` reads values. `set` changes them before boot. The repository freezes after module registration. Module config comes from `modules.<module-id>`; the existing `$app->boot(['module-id' => [...]])` overrides it. Manifest defaults are merged underneath both. Nested maps merge, lists replace, and an empty array clears a nested value.

To load files, make a configuration directory with trusted PHP files that return arrays:

```text
config/app.php                     -> ['name' => 'Corevia']
config/modules.php                 -> ['greetings' => ['prefix' => 'Hello']]
config/environments/development/modules.php
                                   -> ['greetings' => ['prefix' => 'Welcome']]
```

```php
$config = ConfigRepository::load(__DIR__ . '/config', 'development');
$config->cache(__DIR__ . '/storage/config-development.json', 'development');
$config = ConfigRepository::load(
    __DIR__ . '/config', 'development',
    __DIR__ . '/storage/config-development.json'
);
```

The cache is JSON, limited to 1 MiB, and validated against its environment and format version. It is loaded instead of PHP config files when present; regenerate or remove it when configuration changes. Create the storage directory first and keep it outside the public document root. Cached configuration can contain secrets, so restrict access with your operating system's permissions, including Windows ACLs. The loader executes configuration PHP files: load only your own trusted files. Environment variables are not loaded automatically; read them explicitly in configuration files. A cache stores resolved values.

## Capabilities and databases

`$app->capabilities->all()` lists declared features and metadata. Core declares runtime, flows, events, and its run-store adapter. HTTP declares its capability when a Router is constructed. Declare any required optional capability before boot. Capability discovery does not contact services or verify connectivity; defining `optional.mysql` does not open a database connection.

The example uses `MemoryRunStore`, which lasts only for that application instance. The task learning app uses JSON files for its task data. To connect a database later, construct PDO explicitly and bind a repository to the container. Follow `test-project/DATABASE.md` for the MySQL task-storage example. The existing `PdoRunStore` uses SQLite; it is separate from task storage and is not a MySQL run-store adapter.

Run the focused tests with `php -n tests/v2/kernel.php`. Continue with `test-project/README.md` to learn HTTP routes, events, and multi-step flows.
