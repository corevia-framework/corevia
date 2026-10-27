# Web routes, controllers and views

Start the prebuilt website:

```powershell
php -n corevia serve
```

Open http://127.0.0.1:8081. The home page, routing guide, database guide, parameter example and protected form all run without a database extension. The original checkout demonstration is available at `/demo.html`; its checkout API still needs SQLite.

Routes are defined in `routes/web.php`, controllers in `app/Http`, and trusted PHP templates in `resources/views`. This follows a familiar Laravel-style organization while using Corevia's own runtime and APIs.

```php
$route->get('/users/{id}', [UsersController::class, 'show'])
    ->name('users.show')->where('id', '[0-9]+');
$route->post('/users', [UsersController::class, 'store']);
$router->url('users.show', ['id' => 42]); // /users/42
```

Controllers and typed services resolve through the container. Request arguments receive the current request, route arguments match by parameter name, and integer parameters are validated. Closures work too. Return a Response, an array for JSON, or a string for HTML. Existing `add` and `flow` routing APIs remain available. GET routes answer HEAD without emitting a body through Response::send.

```php
$router->group('/admin', function (Router $route) {
    $route->get('/reports', [ReportsController::class, 'index'])->name('reports');
}, [$auth], 'admin.');
// Named URL: admin.reports

$router->resource('/posts', PostsController::class, 'posts');
// index, create, store, show, edit, update (PUT/PATCH), destroy
```

Resource routes only wire controller methods; they do not create authorization, models or tables. Add appropriate authentication/authorization middleware and CSRF protection to browser-write routes. `view('/about', 'about', $data)` renders through the registered View service; `redirect('/docs', '/guide')` returns a local redirect. Route names must be unique. Run `php corevia route:list` for named web routes; `php corevia routes` retains the legacy checkout route list.

## Views and forms

```php
$html = $view->render('users.show', ['name' => $name]);
return Response::html($html);
```

In `resources/views/users/show.php`, write `<?= $e($name) ?>` to escape text and attribute values. Templates are trusted PHP and are not sandboxed. View names cannot traverse outside the view directory. The starter layout composes already-rendered template HTML and loads a local responsive stylesheet; it needs no Node build step or external fonts.

Request::input reads JSON or URL-encoded forms. The contact preview demonstrates validation and escaped output; it does not send email or store a message. To persist business data, call an action or repository from your controller.

Csrf middleware signs an expiring HttpOnly/SameSite cookie and provides `csrf_token` in request attributes. Render it as a hidden `_token`, then validate the submitted token before handling a write. The middleware works without PHP sessions. Set APP_HTTPS=true behind a correctly configured HTTPS deployment to mark the cookie Secure. The private `storage/web-signing.secret` is generated locally and must stay out of Git and the public document root.

The public front controller serves only explicit static assets. Source/configuration/storage paths return 404. `/checkout`, `/cvp` and `/health` remain reserved for the original checkout runtime; use your own routes or an explicitly authorized web action gateway for new applications.

Tests: `php -n tests/features/web.php` and `node tests/features/web.mjs`. They cover controllers, named URLs, groups, constraints, redirects, templates, private-file denial, HEAD, validation, escaping and real form CSRF behavior.
