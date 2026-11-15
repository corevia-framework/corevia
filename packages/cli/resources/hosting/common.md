# Hosting recipe preview

These files are reviewable examples, not an installer or target certification.
No command has installed a service, provisioned infrastructure or changed a host.
The manifest intentionally reports `target_validated: false`.

Build the application with dependencies and frontend assets on your build machine.
Run `php corevia hosting:check --target=TARGET` and `deploy:check` on an isolated
target. Keep only `public/` exposed; configuration, vendor, keys and storage stay
private. Set APP_ENV=production, APP_URL to the HTTPS origin and APP_HTTPS=true
only behind an enforced HTTPS edge. Deny direct public access to origin HTTP.
Do not trust arbitrary forwarded headers. Disable query-string access logs at
the edge too: OAuth callbacks can contain credentials.

Replace example paths, user/group, TLS host and PHP socket/version after review.
Provide environment variables privately; these recipes contain no secrets.
Keep code read-only to the web user; grant write access only to private storage.
Back up and test restoration before migrations. Run migrations explicitly once
per deployment, never from a web request or every worker start.

Shared/multi-instance sessions, queues, flows, throttles and authority need the
appropriate shared adapters. Local JSON/SQLite and process metrics are not
multi-host coordination. Optional Python, websocket and worker capabilities
require a host that actually permits them; a shared PHP host cannot run every
runtime profile. Outbound HTTPS and extensions must be checked on that host.

Queue commands require configured SQL coordination tables and routes/jobs.php.
`queue:work --max=20 --budget-ms=20000` bounds a cooperative foreground batch.
I/O adapters need timeouts; PHP cannot preempt an arbitrary blocked callback.
Supervisor hard termination may leave leases until expiry and external effects
uncertain. Handlers must use stable idempotency keys and fenced acknowledgments.
Do not schedule another batch over the same single-host cron worker: use a host
lock, or deliberately provision shared concurrent workers. Never use cron for
an HTTP endpoint that runs shell commands.

Acceptance before declaring support: TLS/HTTPS-only, public/private file probes,
rewrite/static assets, install/upgrade/rollback, selected database drivers,
storage persistence/redeploy, authorization, real queue expiry/crash recovery,
streaming/timeouts, logs/redaction and sustained workload. Cloud credentials,
paid resources and deployments require separate operator authorization.

Sources:
- AWS PHP configuration: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/create_deploy_PHP.container.html
- Azure Linux PHP configuration: https://learn.microsoft.com/en-us/azure/app-service/configure-language-php
- Azure ZIP root contents: https://learn.microsoft.com/en-us/azure/app-service/deploy-zip
