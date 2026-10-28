# Build your first Corevia application

Download and extract the repository ZIP, or clone it into your application's directory. PHP 8.2+ with JSON is enough for the welcome page. Run `composer install` if Composer is available, then `php corevia serve`. Open http://127.0.0.1:8081.

## Where to write your code

| Path | Purpose |
| --- | --- |
| `routes/web.php` | Your URLs, named routes, controllers and middleware |
| `app/` | Your controllers, modules, actions and business logic |
| `resources/views/` | Your PHP page templates |
| `public/assets/` | Your CSS, JavaScript and public images |
| `config/settings/` | Application settings and database connections |
| `storage/` | Generated private keys, logs, caches and runtime data; ignored by Git |
| `packages/` | Corevia framework source; keep application changes outside this directory |
| `tests/` | Framework checks; add your own application tests alongside them |
| `examples/`, `test-project/`, `sdk/` | Optional learning examples and language integrations |

Do not ignore or delete `packages/`, `autoload.php`, `corevia`, or the bootstrap files: the installation needs them. Keeping framework files tracked allows reproducible downloads and fixes. If you want to change the framework itself, use a separate branch and follow CONTRIBUTING.md.

## Add a page

Add a route inside the route registration callback in `routes/web.php`:

```php
$route->get('/my-page', static fn (): string => '<h1>My first Corevia page</h1>')
    ->name('my-page');
```

Open `/my-page`. For templates, use `$router->view('/about', 'about')` after creating `resources/views/about.php`. Templates receive `$e()` for escaping dynamic values. For larger features, use a controller and put business work into module actions. See [web routing](v2/web.md) and [kernel/modules](v2/kernel.md).

## Add a database when you need one

The default local JSON connection does not need a server. MySQL/MariaDB, PostgreSQL and SQLite use optional PDO drivers; MongoDB uses its optional PHP extension. Set credentials in your process environment and configure `config/settings/database.php`. `.env.example` lists supported environment names; Corevia does not automatically read a copied `.env` file.

```sh
php corevia db:status
php corevia db:check local
php corevia route:list
```

Follow [database setup](v2/databases.md) before changing drivers. `db:migrate` creates Corevia's SQL run-store tables, not your application schema.

## Track your own project

A ZIP download has no Git history, so you can run `git init` and connect it to your own repository. A clone retains the Corevia upstream history; change the remote to your own project before pushing application work. Keep `.gitignore`, `.env.example`, `composer.json` and `composer.lock` tracked. Do not commit the local `.env`, authentication files, signing keys, databases, uploads or generated storage contents.

## Deploy

Point the web server document root at `public/`. The built-in `serve` command is for development. Keep private storage and configuration outside the document root, configure HTTPS and appropriate authentication, and read [deployment guidance](deployment.md). The welcome form is a validation preview and does not send email or save business data.
