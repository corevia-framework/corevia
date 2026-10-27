# Installation and package boundaries

The application skeleton is named `corevia-framework/corevia`. The monorepo retains the working package directories and starter application; the core package has its own Composer manifest at `packages/core/composer.json` for isolated installation tests. There is no mandatory PDO, Python, Node, Redis, or broker dependency in the PHP core.

These package identities are prepared but have not been published to a public registry. Until publication, clone the authorized repository or configure a Composer VCS/path repository. Do not expect a public `composer create-project corevia-framework/corevia myapp` or `composer require corevia-framework/core` to resolve without such a repository. `tests/v2/install.py` demonstrates both flows with local path repositories and committed archives. It performs real Composer installs into fresh directories and runs the starter and core with PDO disabled.

```powershell
composer install
php corevia about
php -n test-project/console.php serve
```

The task starter is database-free. The checkout/realtime demonstration at the root uses optional PDO SQLite. See `test-project/DATABASE.md` before adding MySQL task storage; the reference runtime store is a separate SQLite adapter. `examples/v2/kernel.php` demonstrates the kernel without extensions, and `docs/v2/flows.md` explains durable JSON storage.

The global installer remains a future package; no installer or package registry publication occurs during local implementation. Distribution and stable release acceptance are separate from preparing and testing the manifests.
