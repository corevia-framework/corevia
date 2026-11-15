# Bounded SQL scheduler

The optional scheduler works from a once-per-minute hosting cron or a supervised CLI process. It requires PDO SQLite, MySQL/InnoDB or PostgreSQL. The framework's database-free HTTP pages do not load it. Local SQLite contracts and separate-process races pass; real-driver and hosting acceptance are recorded separately.

Try the private, non-networked example from a checkout:

```sh
php examples/scheduler/run.php migrate
php examples/scheduler/run.php tick
php examples/scheduler/run.php work
php examples/scheduler/run.php tick
php examples/scheduler/run.php work
```

The first worker completes the minutely job. Repeated ticks within the same minute return its existing ID; another worker is idle. Example data stays under ignored `storage/scheduler-example/`. A later minute produces a new job. Migration is an operator setup step, outside request handling. A detached application can use the same classes through its Composer autoloader.

## Define and run

```php
$queue = new Corevia\Persistence\SqlCoordinator($connection, 'app_schedule');
$scheduler = new Corevia\Persistence\SqlScheduler(
    $queue, new Corevia\Core\ScopeManager(), 'billing', [
        new Corevia\Core\ScheduleEntry(
            'daily-report', new Corevia\Core\CronExpression('0 9 * * 1-5'),
            'Asia/Karachi', 'reports.daily', ['format' => 'summary'],
            timeoutSeconds: 300, repeatedMinute: 'once',
        ),
    ],
);
$scheduler->tick(); // bounded: at most 100 definitions, current database-clock minute only
$result = $scheduler->workOnce([
    'reports.daily' => function ($input, $claim, $deadline, $scope, $fence): array {
        $deadline->check();
        // Write using $claim->idempotencyKey(); pass remainingMilliseconds() to I/O adapters.
        // Register per-job resources with $scope->onClose(...).
        return ['ok' => true];
    },
]);
```

Use a **dedicated queue prefix and connection** for each scheduler. A scheduler worker rejects unknown tasks and stale definition fingerprints permanently; it must not share a queue with unrelated mail/application workers. Schedule definitions and input order must be identical on all hosts. Roll out definition changes after draining old work; changed definitions inside the same occurrence fail explicitly instead of silently executing another payload. Input is a copied JSON value, at most 64 KiB. Keep secrets and personal data out of static schedule definitions.

Five numeric cron fields support `*`, lists, inclusive ranges and range/wildcard steps. Sunday is `0`; names, `7`, seconds and macros are rejected. When both day fields are restricted they use OR; a wildcard day field uses the other field. Timezones must be explicit IANA identifiers. Cron does not catch up downtime: a tick evaluates only the current UTC minute. Schedule intentional backfills through an application-controlled operation with their own stable effect keys.

For DST, missing spring minutes are skipped. `repeatedMinute: 'once'` identifies an occurrence by the local wall minute, so the repeated fall minute dispatches once. `'each'` identifies it by UTC minute, so both dispatch. Atomic dispatch reservations survive completed/expired queue payloads for seven days. They are not a permanent event ledger. Keep database clocks synchronized; arbitrary historical ticks and clock rollbacks beyond the reservation window are outside this contract.

## Ownership, retries and limits

Handlers must be idempotent. One lease per schedule ID skips overlapping occurrences while another worker owns the lease. This policy may deliberately skip a minute; it does not defer it. Claim leases last one hour; each resource lease lasts its configured execution timeout plus 60 seconds. Execution timeouts are 1–3000 seconds. Resource fences and job owner tokens reject stale acknowledgments; a replaced worker cannot release its successor's lease. Pass fences into application storage that supports fenced writes when appropriate.

Deadlines are cooperative. A suspended process or adapter ignoring its timeout can still cause an external effect after lease expiry. Use real connection/read/write timeouts, process supervision and downstream idempotency. No exactly-once external delivery guarantee is made. Exceptions and cancellation use bounded queue retries and backoff; execution resources close on every path. Error responses include the exception class, never the exception message or input.

`SqlCoordinator::inspect('dead', limit: 50)` returns at most 100 rows of metadata, excluding payloads, results and owner credentials. `retryDead($id, $queue->clock())` is an explicit **operator-only** action for an unexpired dead job. It resets attempts and preserves the job/effect key; it cannot revive an expired payload or running job. The library supplies no public retry route. Host applications must authorize and audit operator actions and reconcile uncertain effects before retrying.

Cron hosts should call a short script that invokes `tick()` and a bounded number of `workOnce()` calls, under the host's execution/time limits. Keep PHP scripts, the database and configuration outside `public_html`. Use an absolute PHP CLI path and file paths in hosting cron. Configure the host's cron separately; this example installs no system tasks. VPS/AWS/Azure deployment and sustained-load behavior still require acceptance.
