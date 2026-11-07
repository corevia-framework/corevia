# V4 hosting portability and competitive targets

Status: planned requirements, not implemented hosting certification. This extends roadmap items 8 and 19. No infrastructure is provisioned and no existing site is changed by this plan.

## Definition of broad hosting support

Support a normal Corevia PHP application on hosts meeting the published PHP/version/extension, filesystem, HTTPS and routing requirements. Declare each optional capability's extra prerequisites. An obsolete PHP runtime, an inaccessible private storage directory or a provider policy prohibiting required work cannot be corrected by a framework.

Keep deployment/hosting presets separate from application runtime profiles (`web`, `api`, `worker`, `realtime`, `ai`, `full`). A deployment selects both the application's modules and where those modules execute. Diagnose unsupported combinations before activation; never silently disable authentication, tenant boundaries or durable delivery.

## Target deployment matrix

| Target | Baseline application | Jobs and realtime | AI and additional services |
| --- | --- | --- | --- |
| Constrained shared PHP hosting, including cPanel/Plesk-managed accounts | Upload a prebuilt bundle; run PHP routes, templates, compiled React assets and selected database adapters without root access or on-host Composer/Node builds. Require supported PHP/extensions, HTTPS and private writable storage. | Use bounded CLI cron workers where the provider permits them; persistent daemons and custom listening ports are not assumed. Offer explicit external workers/realtime adapters where configured. | Call a configured HTTPS model service if outbound access is permitted; Python/model hosting is external unless explicitly supported by the provider. |
| VPS or dedicated server | Document Apache, nginx/PHP-FPM and selected Windows/IIS configurations; keep OS-specific process management in deployment adapters. | Supervised workers, scheduler and websocket processes with readiness, restart and graceful-shutdown policies. | Optional separately supervised Python/model services; resource and accelerator capacity remain operator responsibilities. |
| AWS | First target EC2 and a documented Elastic Beanstalk PHP deployment; use explicit database, storage, session and secrets configuration. | Separately deployed workers/realtime; optional adapters for selected managed services with real integration contracts. | External/privately hosted model services with bounded authenticated access. Container/serverless deployment is a separately validated target, not implied by AWS support. |
| Azure | First target Linux App Service and Azure VMs; document the runtime, public root, startup configuration, durable storage and secrets handling. | Use supported separate worker/realtime services; do not assume a managed web process is a durable job supervisor. | Configured HTTPS model services or separately deployed Python compute; validate each selected adapter. |

AWS and Azure are collections of services. A tested VM target does not establish compatibility with every managed, container or serverless service. Publish support by exact target and tested runtime rather than an unrestricted provider badge. Other providers can use the validated generic PHP/VPS/container contract where their actual capabilities match it.

## Required implementation

1. **Build elsewhere, upload once.** Produce a versioned bundle containing required PHP dependencies, built frontend assets and a file manifest, with no developer credentials or runtime data. Validate the target PHP/extensions before activation. Support deployment without shell access; operator actions requiring CLI must have a documented off-server preparation or supported control-panel path.
2. **Protect private files by layout.** Support configurable public/private paths and a tested `public_html` layout with application code, dependencies, configuration, keys and storage outside the document root. If private separation is impossible, refuse the unsupported layout rather than relying on filename secrecy. Verify direct HTTP denial for representative private files.
3. **Explicit routing and configuration.** Supply tested Apache rewrite rules, nginx configuration and selected IIS rules; document any front-controller fallback. Validate trusted origin/proxy settings, HTTPS, filesystem permissions and storage persistence. Never place a public setup/debug console in the production bundle.
4. **Bounded scheduled execution.** Add a one-pass/time/job-budget mode with leases, overlap protection and restart-safe progress. Honor provider cron frequency/time limits. Without an allowed cron/worker service, report deferred work as unavailable; do not substitute unsafe synchronous execution or unauthenticated public trigger URLs.
5. **Replaceable infrastructure.** Define explicit local/shared storage, cache, queue, notification, realtime and AI adapter capabilities. Do not claim local JSON/filesystem stores coordinate multiple hosts. Shared deployments must select validated shared adapters.
6. **Capability report.** Extend deployment diagnostics to inspect runtime/extensions, disabled functions, usable paths, memory/time limits and configured capabilities without revealing credentials. Reports must distinguish measured facts from operator declarations and provider-policy checks.
7. **Portable process behavior.** Keep a conventional request/response execution path. Optional persistent-worker acceleration requires lifecycle/leak tests and supervision; no application must require a daemon merely to serve a normal page.
8. **Target guides and smoke tests.** Document upload, configuration, database setup, public root, storage, scheduling, logs, upgrades and restoration for each advertised target. Test subdirectory deployments, unwritable directories, read-only code, absent CLI and reverse-proxy HTTPS where relevant.

No credentials, paid cloud resources or changes to existing production infrastructure are authorized by this roadmap. Target acceptance uses disposable environments when they are available and authorized. Until then, distinguish recipe-only, fixture-tested and target-validated support.

## What outperforming Laravel and CodeIgniter means

The objective is an advantage developers can reproduce. Do not present more features, successful unit tests or a synthetic route benchmark as overall superiority.

| Dimension | Evidence to publish |
| --- | --- |
| Installation and portability | Time and required steps from clean download to a working authenticated app on each supported hosting class; off-server build requirements and production safeguards. |
| Daily development | Reproducible tasks covering CRUD, validation, authorization, migrations, API contracts, jobs and an upgrade of an edited starter; count manual integration and configuration steps. |
| Request performance | Comparable HTML, JSON, authenticated and database-backed workloads; cold/warm latency, p50/p95/p99, throughput, CPU, memory and error rates. State concurrency and resource limits. |
| Durable and mixed-runtime work | Recoverable workflow behavior, duplicate delivery handling, queue latency, PHP/Python contract failures and cancellation/cleanup under injected failures. Compare equivalent application semantics. |
| AI application operation | Time to first token, stream cancellation, retrieval correctness, tenant isolation and budget enforcement using the same chosen provider/model or clearly labeled transport fixtures. Separate model/network time from framework overhead. |
| Maintainability and reliability | Upgrade conflicts, public API compatibility, soak-test memory growth, worker recovery, tested dependency/runtime matrix, security-review scope and release/support policy. |

Pin framework/package versions, PHP/runtime versions, database schema/data, hardware, configuration and optional infrastructure. Include harnesses, raw results, failed requests and methodology. Use equivalent security/caching/durability settings and disclose optimizations. Report tradeoffs and regressions; make no preselected claim that Corevia must win every workload.

## Hosting release gate

For each advertised target, record an install/update/restore smoke run, private-file protection, auth/session/CSRF behavior, selected database persistence, frontend asset delivery, configured mail/model connectivity and the actual job/realtime execution strategy. Test only selected optional features; show unsupported features explicitly. Publish the exact target/runtime and validation level.

## Official platform references

- [cPanel cron jobs](https://docs.cpanel.net/cpanel/advanced/cron-jobs/): providers can enable or disable cron access; its presence is not a universal shared-host guarantee.
- [AWS Elastic Beanstalk PHP platform](https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/create_deploy_PHP.container.html): PHP platform and proxy configuration must match the deployment.
- [Azure App Service PHP quickstart](https://learn.microsoft.com/en-us/azure/app-service/quickstart-php): the documented native PHP target is App Service on Linux.

Recheck platform support and runtime versions during implementation and release acceptance; these references are not a framework compatibility certificate.
