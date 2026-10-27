# Observability

Runs persist a timeline, per-action attempts with timing and exception-class names, critical/non-critical status, checkpoints, and compensation records. `php corevia flow:inspect <id>` prints this execution report. Structured action and protocol logs retain request/trace correlation IDs. Exception messages and stack traces remain out of production result envelopes.

The JSON inspector output is intended for local development or an authenticated operator interface. Flow state can contain application data; do not expose it on a public route. The task learning page remains a development example showing individual steps and errors.

Database diagnostics are opt-in and live in the PDO adapter, not core:

```php
$diagnostics = new QueryDiagnostics($logger, $traceId, threshold: 5);
$store = new PdoRunStore($pdo, $diagnostics);
// $diagnostics->snapshot() contains query fingerprints, counts, and elapsed time.
```

Repeated SELECT patterns emit one warning at the threshold. Only a query hash and counts enter the diagnostic log; bound parameters and raw SQL are excluded. It is a heuristic for investigation, not proof of an N+1 defect. Create diagnostics per request/trace to bound memory and correlate the report. MemoryRunStore and JsonRunStore do not activate database diagnostics.
