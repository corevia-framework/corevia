# Hosting recipes and bounded workers

V4 can emit private, reviewable recipes without loading application configuration,
executing deployment commands or accessing cloud accounts:

```sh
php corevia hosting:prepare --target=vps --output=vps-preview --dry-run
php corevia hosting:prepare --target=vps --output=vps-preview
```

Choose `shared`, `vps`, `aws` or `azure`. Output is relative to the application's
existing private `storage/`. Every component below storage must be new; existing
directories, symlinks, traversal, absolute paths and overwrites are rejected.
Preview writes nothing. Files use private permissions where supported and a
SHA-256 manifest. Windows ACLs still require host review. Interrupted writes can
leave a partial private directory; choose another destination after inspecting it.
`target_validated` and `production_ready` remain false.

| Recipe | Emitted files | Operator responsibilities |
| --- | --- | --- |
| Shared Apache | public rewrite rules; bounded locked cron example | Off-server dependency/asset build; private/public_html upload; supported CLI/cron/flock/extensions; private log rotation |
| VPS or EC2/VM | loopback nginx; dedicated FPM pool; queue oneshot service/timer | Dedicated account/paths/socket, private environment allowlist, HTTPS edge/firewall, storage, migrations and reviewed supervisor installation |
| AWS Elastic Beanstalk PHP | Apache proxy/document-root options and public rewrite rules | Select a supported platform branch; deploy ZIP layout, private/shared storage and secrets, HTTPS-only edge/security groups; no worker tier implied |
| Azure Linux App Service | nginx port 8080 and optional startup script | Review image/socket paths, configure HTTPS-only edge and private persistent storage, upload ZIP contents at root, review startup configuration; no durable queue worker implied |

These are capability profiles, not a claim that every host can run Python,
websockets, multi-instance coordination or workers. The emitted README describes
the checks before enabling traffic. An FPM pool with `clear_env=yes` requires an
explicit private environment allowlist or reviewed application loader. Setting
environment values on a service does not automatically pass them through FPM.
Do not deploy the example paths unchanged.

```sh
php corevia queue:work --max=20 --budget-ms=20000
```

`--budget-ms` accepts 1 through 3,600,000 milliseconds. The optional monotonic
budget is shared by the batch and inherited by each job's work context. The loop
checks before the next claim and preserves a finished acknowledgment if the
post-call check expires. Its JSON response adds `stopped` (`empty`, `maximum` or
`budget`) alongside the existing `processed` array. Omitting the option retains
the existing per-job lease budget. `--once` still processes at most one job.

Handlers and blocking I/O must cooperate; arbitrary blocked PHP cannot be
preempted by this budget. A supervisor may kill an overrun, leaving an uncertain
external effect and a lease to recover. Stable effect keys, downstream
idempotence and fenced acknowledgments remain required. The systemd timer waits
until its own previous oneshot is inactive; it does not coordinate separate
hosts. Cron's sample lock is single-host only. Mail workers retain their existing
maximum-count and adapter timeout behavior; `--budget-ms` is a queue-worker option.

## Validation scope

`php -n tests/v4/hosting-presets.php` checks write-free previews, content hashes,
private output confinement, symlinks on Unix and overwrite refusal.
`php tests/v4/workers.php` exercises 1,000 real SQL queue claims in one process,
including 40 deliberate handler failures, weak-reference release and scope/context
cleanup, followed by deterministic cooperative batch cancellation. Monotonic deadline expiry has separate contracts. This short bounded test reports
allocation growth and duration; it is not sustained-load acceptance or a speed
comparison with another framework.

Detached Composer installation checks also generate and hash all four recipes.
On the Windows development machine, setting `COREVIA_APACHE_PRESETS=1` for
`python tests/v4/phase-b.py` runs both Apache rewrite recipes with an isolated
Apache/FastCGI process and copied disposable bundle. Installed services remain
unchanged.

`tests/v4/hosting-linux.py` runs actual nginx and PHP-FPM using private fixture
configs, loopback listeners, substituted paths and the VPS pool template. It
checks both nginx recipes, front-controller/static behavior, hidden files and
other PHP denial, shell syntax and systemd unit/timer syntax. It never loads
global site/pool configs, installs services or executes the Azure startup script.
Its output must be under repository storage or `/var/www/corevia/.acceptance`.
Only its own process groups are stopped. Neither this fixture nor local Apache
certifies an actual shared host, EC2/Elastic Beanstalk or Azure App Service.

AWS configuration follows the [official PHP platform options](https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/create_deploy_PHP.container.html).
Azure recipes follow the [Linux PHP configuration guide](https://learn.microsoft.com/en-us/azure/app-service/configure-language-php)
and [ZIP layout requirements](https://learn.microsoft.com/en-us/azure/app-service/deploy-zip).
Actual platform, paid cloud, redeploy/storage, TLS, sustained-load, Docker and
security acceptance remain release gates; no cloud resource is provisioned here.
