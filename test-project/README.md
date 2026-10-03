# Learn Corevia by building a task app

This is your separate practice project. It uses the Corevia runtime already present in the parent directory. You can create tasks, list them and mark them complete. It needs only PHP 8.2+; no MySQL, SQLite, Node.js, Python or WebSocket server is required.

The example uses the included autoloader, so you can start it without running Composer.

Tasks are saved in a JSON file. This means they survive refreshes and server restarts without a database server. Corevia flow execution records use memory in this example and are returned in each HTTP response.

## Start here

Open PowerShell in `J:\New folder\corevia`:

```powershell
php test-project/console.php serve
```

Keep that terminal running and open **http://127.0.0.1:8091**. Press Ctrl+C in the terminal to stop it. This command starts the learning app, independently of the original checkout demo.

Try these three things:

1. Create a task called **Learn Corevia**. All four flow steps should complete.
2. Select **Simulate a reminder failure**, then create another task. Status becomes `completed_with_warnings`, and the task still appears in the list.
3. Submit an empty title. Status becomes `failed`; only validation executes, and no task is saved.

Click **Complete** next to a task to run the separate completion flow. The page shows the source file for each executed action and lets you expand the full JSON response.

## What the framework does

Think of Corevia as the coordinator for your business operations. You write the work in Actions, register those actions inside Modules, and tell a Flow which actions to execute in order. A route or CLI command starts the flow.

| Concept | Plain meaning | In this project |
| --- | --- | --- |
| Module | A feature's registration point | TasksModule registers the task feature |
| Action | One piece of work | ValidateTitle, SaveTask, CompleteTask |
| Flow | An ordered list of actions | tasks.create validates, saves, emits an event, creates a reminder |
| State | Data moving through the actions | title first; saved task is added later |
| Result | The outcome and execution history | status, state, steps, timings and errors |
| Event | An announcement other code can listen for | TaskCreated causes a log entry |
| Container | Supplies services an action asks for | Gives SaveTask the TaskRepository |
| Repository | Application code that reads/writes data | JsonTaskRepository reads/writes tasks.json |

The repository is a pattern used by this example, not a required Corevia base class. You can use ordinary PHP services too.

## Follow one request through the code

Read these files in this order:

1. **`public/index.php`** receives the HTTP request and loads the app.
2. **`bootstrap.php`** chooses storage, creates Corevia, registers services/modules and defines routes.
3. **`src/Tasks/TasksModule.php`** assigns action names to classes and registers flows/events.
4. **`src/Tasks/Flows/CreateTaskFlow.php`** defines execution order and failure rules.
5. **`src/Tasks/Actions/ValidateTitle.php`** checks and normalizes the title.
6. **`src/Tasks/Actions/SaveTask.php`** saves it through TaskRepository.
7. **`src/Tasks/JsonTaskRepository.php`** writes the JSON file.
8. **`src/Tasks/Actions/PublishTaskCreated.php`** emits an event; TasksModule's listener writes a log entry.
9. **`src/Tasks/Actions/CreateReminder.php`** creates a suggestion or demonstrates an expected failure.

The browser sends:

```json
{"title":"Learn Corevia","simulate_reminder_failure":false}
```

This route in bootstrap starts the flow:

```php
$router->flow('POST', '/api/tasks', 'tasks.create');
```

Corevia passes the request JSON into State:

```php
$state->data['title']; // "Learn Corevia"
```

Validation returns the same State with a trimmed title. SaveTask adds:

```php
$state->data['task'] = $this->tasks->create($state->data['title']);
```

Later actions can now read `$state->data['task']`. At the end, Corevia returns a run record. In the HTTP response, look at:

```text
result.status                  completed / completed_with_warnings / failed
result.state.task              the saved task
result.steps                   which actions ran and how long they took
result.trace_id                 ties this operation to its log entries
```

## How to write an Action

Open `SaveTask.php`:

```php
final class SaveTask implements Action
{
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public function handle(State $state, ExecutionContext $context): State
    {
        $state->data['task'] = $this->tasks->create($state->data['title']);
        return $state;
    }
}
```

The constructor says this action needs task storage. Bootstrap tells the container which storage object to supply:

```php
$app->container->set(TaskRepository::class, $tasks);
```

You do not construct SaveTask manually. Corevia resolves it through the container when the flow executes its registered name.

`handle()` contains your business operation. It receives State and ExecutionContext. Return State on success, or an explicit ActionResult for an expected failure:

```php
return ActionResult::failure('INVALID_TITLE', 'Please enter a title.');
```

Corevia catches unexpected action exceptions, logs them and turns them into structured failures. It does not automatically undo a saved file or payment; external side effects need their own transaction/idempotency strategy.

## How to write a Flow

Our create flow is:

```php
return Flow::define('tasks.create')
    ->step('tasks.validateTitle')->critical()
    ->step('tasks.save')->critical()
    ->step('tasks.publishCreated')->nonCritical()
    ->step('tasks.createReminder')->nonCritical();
```

Order matters. SaveTask runs only after validation succeeds. A critical validation/storage failure stops the flow. A non-critical event/reminder failure records a warning after the task is saved.

Actions do not become available just because their files exist. TasksModule explicitly registers them:

```php
$context->actions(['tasks.save' => SaveTask::class]);
$context->flows([CreateTaskFlow::define()]);
```

Bootstrap registers the module before booting:

```php
$app->modules([TasksModule::class]);
$app->boot();
```

## Call a Flow directly from PHP

From a PHP file placed inside `test-project`:

```php
<?php
declare(strict_types=1);

$boot = require __DIR__ . '/bootstrap.php';
$runtime = $boot();
$result = $runtime['app']->run('tasks.create', ['title' => 'My first action flow']);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
```

The same flow can be used from HTTP, CLI or other application code. Keep business logic in the actions so you do not rewrite it for each entry point.

## Use the CLI or API

From the root directory:

```powershell
php test-project/console.php create "Learn actions"
php test-project/console.php create "See failure isolation" --fail-reminder
php test-project/console.php list
php test-project/console.php complete task_COPY_THE_REAL_ID_HERE
php test-project/console.php routes
```

HTTP from a second PowerShell terminal while the server is running:

```powershell
$body = @{ title = 'Task from PowerShell'; simulate_reminder_failure = $false } | ConvertTo-Json
$response = Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8091/api/tasks -ContentType application/json -Body $body
$response.result.steps
Invoke-RestMethod http://127.0.0.1:8091/api/tasks
```

To complete the task:

```powershell
$id = $response.result.state.task.id
Invoke-RestMethod -Method Patch -Uri "http://127.0.0.1:8091/api/tasks/$id/complete"
```

## Your first code changes

Start with a small edit you can see immediately:

1. Open `src/Tasks/Actions/CreateReminder.php` and change the reminder text.
2. Save the file, create a new task and expand the JSON response. Your new text appears under `result.state.reminder`.
3. Open `ValidateTitle.php` and change `'max' => 120` to `'max' => 20`. Update the error text too. Try a longer title to see validation fail.

For a new action, follow this recipe:

1. Create a class under `src/Tasks/Actions/` implementing `Action`.
2. Put its work in `handle(State $state, ExecutionContext $context)`.
3. Register its name/class in `TasksModule.php`.
4. Add `->step('your.registered.name')` to the flow, choosing critical or non-critical.
5. Test the behavior before using it in the UI.

For example, a `tasks.setPriority` action could add `$state->data['priority'] = 'normal'`. It belongs before saving if you also extend TaskRepository's data shape to store priority. Adding a state field alone does not automatically add it to the JSON file.

## Database-free tests

```powershell
php test-project/tests/run.php
```

Eight tests cover valid creation, invalid input stopping the flow, isolated reminder failure, event logging, HTTP routes, completion, file persistence and corruption handling. Tests use a temporary directory and leave your task list untouched.

You can run the tests even with PDO extensions disabled:

```powershell
php -n test-project/tests/run.php
```

For a real HTTP/CLI process check, Node.js is an optional testing tool:

```powershell
node test-project/tests/http.mjs
```

The browser app itself does not require Node.js.

## Database connection later

Read [DATABASE.md](DATABASE.md). A PDO connection factory, optional PdoTaskRepository and MySQL table script are included for later. They are never loaded by the default app. Swapping the TaskRepository binding changes task storage without rewriting actions or flows.

## What persists here

- **Tasks:** `storage/tasks.json`, across requests/restarts.
- **Logs:** `storage/runtime.log`, across requests/restarts.
- **Flow run records/idempotency keys:** MemoryRunStore, only during one PHP execution. There is no cross-request manual retry/history/idempotency guarantee in this small example.

Creating a task twice creates two tasks. SaveTask has no automatic retry policy. Completion is safe to repeat because it sets `completed` to true. JSON files are useful for learning and small local experiments; they are not a crash-safe transactional database. Use this app on localhost. No authentication, production reminder delivery or task scheduler is included.

## Directory map

```text
test-project/
  README.md                 this walkthrough
  DATABASE.md               connect PDO/MySQL later
  autoload.php              load Corevia + Learning namespace
  bootstrap.php             assemble the app and routes
  console.php               local server and task CLI
  public/
    index.php               HTTP entry point
    index.html              task UI and flow inspector
  src/Tasks/
    TasksModule.php         registrations and event listener
    TaskRepository.php      storage contract
    JsonTaskRepository.php  default database-free storage
    PdoTaskRepository.php   optional database storage
    Actions/                individual operations
    Flows/                  execution plans
    Events/                 event value objects
  database/                 optional PDO factory + MySQL schema
  storage/                  local task data/logs, ignored by Git
  tests/                    separate learning-app tests
```
