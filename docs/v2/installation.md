# Installation and package boundaries

The application skeleton is named `corevia-framework/corevia`. The monorepo retains the working package directories and starter application; the core package has its own Composer manifest at `packages/core/composer.json` for isolated installation tests. There is no mandatory PDO, Python, Node, Redis, or broker dependency in the PHP core.

Download the repository ZIP or clone `https://github.com/corevia-framework/corevia.git`, then open a terminal in the project directory. Repository visibility is controlled by the owner; a private repository requires access until it is made public. These package identities have not been published to Packagist, so `composer create-project corevia-framework/corevia myapp` and `composer require corevia-framework/core` need a configured VCS/path repository. `tests/v2/install.py` verifies both installation flows using local repositories and committed source archives.

```powershell
composer install
php corevia about
php corevia serve
```

Open http://127.0.0.1:8081 for the database-free web starter. The optional task tutorial is in test-project, and the checkout demonstration at /demo.html uses SQLite. See [Getting started](../getting-started.md) for application directories, [database setup](databases.md) for supported connections, and [web routing](web.md) for controllers and views.

Source archives omit repository automation and local implementation records. Tests, framework sources, public documentation, the environment template and empty storage placeholders remain included. `.gitignore` excludes machine-specific/generated files from future commits; it does not remove anything from earlier Git history or protect source files from modification.

The global installer remains a future package; no installer or package registry publication occurs during local implementation. Distribution and stable release acceptance are separate from preparing and testing the manifests.
