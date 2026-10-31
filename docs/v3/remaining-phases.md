# Six remaining V3 workstreams: local implementation

Final scope/security/upgrade review is now documented in [final review](final-review.md). Live OAuth and Docker validation were explicitly deferred by the maintainer; [optional self-tests](optional-validation.md) remain available to application owners. GitHub CI is still a required release gate.

Follow-up production acceptance has now exercised real MySQL/PostgreSQL/MongoDB and private TLS nginx/PHP-FPM on a separate Linux host. See [production acceptance](production-readiness.md) for current scope, policy hardening and remaining gates; the initial local-fixture milestones below are historical.

These six workstreams are included in the V3 release-preparation branch, with framework/SDK metadata 3.0.0. Local fixtures are explicitly selected. Actual database, private TLS/FPM and SMTP acceptance is documented in [production acceptance](production-readiness.md); GitHub CI remains required before a stable tag/release. Live OAuth and the optional Docker recipe have [deferred user self-tests](optional-validation.md).

| Workstream | Local implementation | Acceptance still needed |
| --- | --- | --- |
| Validation | Reusable local acceptance runner, source fingerprint/evidence, real loopback TLS/SMTP, PHP→Python/browser streaming and negative security fixtures | Real OAuth/SMTP, isolated MySQL/PostgreSQL/MongoDB runs and GitHub CI |
| Additional adapters | GitHub OAuth, Google/pinned OIDC RS256 code client, generic CAS auth store and MongoDB backend | Live provider/driver acceptance; OIDC certification is not claimed |
| Shared infrastructure | Existing SQL sessions plus shared SQL queue, encrypted shared mail, leased workers, stale-owner checks and fencing | Multi-host load/failover testing and downstream idempotence/fencing integration |
| AI | Compatible streaming chat, embeddings, tenant-indexed retrieval, bounded Python model serving, background-handler example, JS/React/Dart consumption | Acceptance with an application-selected real model/provider and its operational limits |
| Deployment/developer tooling | Additive `starter:ai`, deployment checks, PHP-FPM/nginx/container templates, worker/migration commands | Container build and real target deployment; Docker is unavailable locally |
| Release preparation | Upgrade/release notes, local evidence and deny-default `release:check` | Original 14-point, security and upgrade reviews plus external acceptance |

## Try the AI starter

In a fresh local V3 application, choose one auth kit, then add AI:

```sh
php corevia starter:auth --stack=bootstrap
php corevia starter:ai
php corevia serve
```

Tailwind and React auth kits use the same AI backend/page. Installation refuses overwrites; `starter:ai --dry-run` lists proposed files, and `--path=...` targets another existing V3 application with auth installed. The AI page is `/ai`. It uses existing sessions/CSRF, per-user request limits, a server-selected model and a fixed 256-token request budget. Anonymous requests are denied. No provider key is exposed to the browser. AI is disabled until `AI_ENABLED=true` is configured; it does not substitute an echo response for real inference.

For a transport-only development demonstration, start an explicit echo fixture in another terminal. This fixture is **not an LLM** and refuses production mode:

```powershell
$env:PYTHONPATH = 'sdk/python'
$env:MODEL_WORKER_TOKEN = '<at-least-32-random-characters>'
python -m corevia.model_server --demo --port=8111
```

In the PHP server's terminal:

```powershell
$env:AI_ENABLED = 'true'
$env:AI_LOCAL = 'true'
$env:AI_BASE_URL = 'http://127.0.0.1:8111/v1'
$env:AI_MODEL = 'local'
$env:AI_API_KEY = '<the-same-private-worker-token>'
php corevia serve
```

Register/sign in, then open `/ai`. The framework does not load `.env` implicitly. Generate real random credentials rather than using the placeholder. For hosted inference, set the provider's HTTPS base ending in `/v1`, explicitly choose `AI_MODEL`, provide the server-side API key, and disable `AI_LOCAL`. `AI_TOKEN_FIELD` can be `max_tokens` or `max_completion_tokens` according to the provider. The adapter implements the [Chat Completions streaming protocol](https://developers.openai.com/api/reference/resources/chat/subresources/completions/streaming-events), not every endpoint/model option in the OpenAI API. No model alias is selected automatically.

## Code against AI services

```php
use Corevia\Ai\{AiClient, CompatibleProvider};
use Corevia\Core\ScopeManager;
use Corevia\Http\NativeHttpClient;

$scopes = new ScopeManager();
$provider = new CompatibleProvider(new NativeHttpClient(), $httpsBase, $model, $privateKey);
$ai = new AiClient($provider, $scopes);
$text = $ai->complete([['role' => 'user', 'content' => $message]], 256);
$ai->stream([['role' => 'user', 'content' => $message]], function (string $delta) {
    // Send/store a bounded delta; return false to cancel.
}, 256);
```

Callers must authorize the user and bound spending before inference. The kit accepts one user-message string, not browser-supplied system roles, model URLs or arbitrary tools. Context is capped at 64 KiB/64 text messages, output at 1 MiB, and token requests at 8,192 in the service API; the starter imposes its smaller fixed budget. Provider errors are generic in HTTP output. Prompts and API credentials do not enter framework flow logs or default persistence.

`NativeHttpClient` verifies TLS/hostnames, refuses redirects/credential-bearing URLs, limits body sizes and applies a read deadline. HTTP is accepted only for explicit literal loopback addresses when enabled. SSE parsing handles partial/UTF-8/CRLF frames and rejects truncated streams. The client closes upstream resources on completion, cancellation and errors.

`Response::events()` emits named SSE events and generic stream failures. Its producer runs at send time after the ordinary buffered HTTP dispatch scope has closed. Resolve resources inside a stream scope; do not capture already-disposed request-scoped services. The generated route enters `http.stream`, and inference enters `ai.inference`, so cleanup runs before the terminal event. HEAD never executes the producer. Reverse proxies must allow streaming; the nginx template disables buffering.

React/browser code can import `streamAi` from `@corevia/js/ai`; the generated page uses the same helper and writes output through `textContent`. Use an AbortController to cancel. React Native needs a runtime supporting streaming fetch/ReadableStream; provider credentials still remain on your backend. Dart/Flutter can pass an authenticated backend response byte stream to `coreviaAiText()` from `package:corevia/ai.dart`. These clients consume the application's named SSE events, not a private model worker directly.
`contracts/ai.json` declares the named delta/done/error schemas and session metadata. Generate application PHP/JS/TS/Python/Dart bindings with `php corevia contract:compile contracts/ai.json --output=path/to/application-bindings`. This manifest describes SSE payloads; it does not expose a CVP action or replace the route's session/CSRF checks.

## Host an actual Python model

Provide an operator-owned factory in an application Python module:

```python
class MyModel:
    def startup(self):
        pass  # Load application-selected installed model/weights here.

    def stream(self, messages, maximum):
        # Yield text from the application's inference runtime.
        # Cooperate with cancellation/generator close and honor maximum.
        yield from self.runtime.generate(messages, max_tokens=maximum)

    def embed(self, texts):
        return self.runtime.embed(texts)

    def shutdown(self):
        pass  # Release model/process resources.

def create_model():
    return MyModel()
```

Set the module search path to your trusted model code and run:

```sh
python tools/model-host.py --factory=my_models:create_model --model=local --port=8111 --concurrency=1
```

Dynamic factory loading belongs to this operator CLI, outside the SDK boundary. Requests cannot select/import a Python module. The SDK hosts a supplied model adapter, authenticates every endpoint, binds loopback, bounds request/concurrency/output, and enters a scope for inference. Models load once and shut down after serving threads finish. No third-party Python package, model weight download or GPU change runs automatically. The application installs and pins its inference engine/weights and owns GPU capacity, licenses and model suitability.

The 60-second generation budget is checked between yielded chunks. It cannot preempt blocked native/GPU code; use supervised process isolation/hard timeouts and a cooperative model adapter where required. Client disconnect closes generators after control returns from the current model step. Public exposure needs an authenticated TLS proxy. A model server is not a sandbox for untrusted Python code.

## Embeddings and retrieval

`CompatibleProvider` also implements `EmbeddingProvider`; explicitly supply its embedding model name. `Retrieval` chunks bounded UTF-8 text, embeds batches and queries a `VectorIndex`. `MemoryVectorIndex` runs without a database; `SqlVectorIndex` supports explicit `migrate()` and portable SQL persistence. These are bounded exact-search reference indexes (at most 5,000 searched chunks per tenant); implement `VectorIndex` for ANN/vector-database scale.

```php
$retrieval = new \Corevia\Ai\Retrieval($embeddingProvider, $index);
$retrieval->ingest($authorizedTenant, 'manual-revision-1', $text);
$context = $retrieval->context($authorizedTenant, $question);
// $context contains untrusted excerpts and source IDs for application-controlled prompting.
```

Tenant IDs must come from server-side authorization, not unchecked request claims. Every index query filters the selected tenant before ranking. Use immutable document revision IDs; ingestion is not an all-chunks database transaction, and reusing a shorter revision can leave prior chunks. Delete retired chunk IDs under an application revision policy. Embedding dimensions/model must remain consistent within an index namespace. Retrieved text is labeled untrusted; this label alone does not solve prompt injection. No tool/SQL/network action is executed from model output.

## OAuth/OIDC and MongoDB auth

Newly generated auth config supports `AUTH_GITHUB=true` with `AUTH_GITHUB_ID/SECRET`, or `AUTH_GOOGLE=true` with `AUTH_GOOGLE_ID/SECRET`. The existing explicit account-linking and MFA requirements still apply; email claims never automatically link accounts. Register the exact `/auth/oauth/github/callback` or `/auth/oauth/google/callback` URL with the provider.

GitHub uses its fixed authorization/token/user endpoints and stable numeric user ID, including the PKCE verifier in exchange, following [GitHub's flow](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps). Google uses pinned HTTPS OIDC endpoints. `OidcCodeProvider` can configure another trusted issuer. OIDC start generates a browser-flow nonce, which the signed token must match. The RS256 verifier checks signature/trusted JWKS key, issuer, audience/authorized party, timestamps, subject and nonce; it rejects unknown algorithms, critical extensions and ambiguous keys. Keys come only from the configured HTTPS JWKS endpoint, never token-header URLs. This is a focused authorization-code client; OIDC certification/all algorithms/flows are not claimed. See [OIDC validation](https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation) and [Google's protocol](https://developers.google.com/identity/openid-connect/openid-connect).

For NoSQL auth, set `AUTH_STORE=mongodb`, `AUTH_MONGODB_URI`, `AUTH_MONGODB_DATABASE` and an operator-selected `AUTH_SCOPE`. Enable ext-mongodb. The backend holds a bounded aggregate per scope (8 MiB), uses primary/majority reads and majority acknowledged version-conditional writes, and retries optimistic conflicts at most 16 times. All existing identity invariants reuse `AtomicAuthStore`, including credential/session/token revocation and unique provider bindings. The generic MongoDB document adapter is not sufficient by itself: another NoSQL implementation must satisfy `SnapshotStore`'s committed-read/atomic-CAS contract.

This aggregate is a reference adapter for bounded directories, not an unbounded indexed user database. Reads also CAS the snapshot; high contention can return STORE_BUSY rather than weakening consistency. Operator scope selection is not tenant authorization. Schema/driver/live replica failover acceptance still needs the target environment. `auth:migrate` has no DDL to perform for MongoDB; it does not claim a successful connection test or import existing JSON/SQL accounts.

## Shared sessions, queues and mail

SQL auth already shares versioned sessions across hosts. Hosts must use the intended common database and provision the same auth encryption key; `AUTH_KEY_FILE` supports an existing private/read-only 32-byte file. Do not create independent keys for shared encrypted data. JSON auth remains single-host.

Set a named SQL `QUEUE_CONNECTION` (SQLite locally, MySQL/PostgreSQL for a shared deployment), then:

```sh
php corevia coordination:migrate
php corevia queue:dispatch my.task --input=private-job.json --idempotent
php corevia queue:work --once
php corevia queue:status
```

Define trusted handlers or a factory returning handlers in `routes/jobs.php`. The factory receives `WorkQueue` and `ScopeManager`, so nested service scopes can share the worker lifecycle. `examples/v3/ai-jobs.php` shows opt-in background inference. Do not queue passwords/bearer credentials; ordinary job input/results are private SQL data, not automatically encrypted. Queued inference may repeat and incur duplicate provider charges after uncertain acknowledgement; use only where repeatable work is acceptable or implement manual-review/reservation semantics.

Claims carry random private ownership tokens and increasing attempts. A 120-second worker lease expires after a crash; stale workers cannot renew/complete another owner's claim. Persistent failures back off and eventually become dead jobs; expired jobs are pruned when claiming. Unknown tasks become dead jobs, so worker pools sharing tables must register the same intended handlers. Choose different prefixes for independent queues. Long handlers must renew before expiry and honor `idempotencyKey()` at downstream services. Completion/retries are at least once, not exactly once.

`SqlCoordinator::clock()` uses the database clock. Use it when passing timestamps to coordination APIs. Resource leases provide persistent increasing fencing numbers: downstream systems must reject stale fences. Releasing/expiring a lease does not undo an external side effect. Preserve fencing history; do not truncate lease tables while old actors can still write. Coordination serializes transitions through a short database mutex and prioritizes correctness; benchmark/partition namespaces or replace `WorkQueue` when throughput warrants it.

For shared email, choose `AUTH_DELIVERY=sqlsmtp` and `MAIL_CONNECTION`, run `mail:migrate`, then the existing `mail:work`/`mail:status` commands. Mail has separate `cv_mail` tables by default, encrypted message bodies/recipients/links, a stable Message-ID, expiry checks and the same leased worker protections. `smtp` retains its single-host encrypted file spool; `sqlsmtp` is the shared alternative. Do not mix general job handlers into the mail queue namespace.

## Deployment, acceptance and release

Use [deployment templates](../../deploy/README.md) for the PHP-FPM/nginx image and secret/storage/worker layout. `deploy:check --multi-host` checks selected drivers, shared storage policy, key provisioning, transport and signed source bytes. It performs no live service probes and does not replace `app:verify` for runtime config/graph.

Run a complete local acceptance pass:

```sh
python tools/v3-verify.py --browsers
php corevia release:check
```

Install `tools` dependencies/browser first (or set `COREVIA_BROWSER`) and set `PYTHON` if needed. The runner uses isolated fixtures and records private `storage/v3-verification.json`, including an exact working-tree source fingerprint. Changed source invalidates evidence. Archive installation checks and Dart/TypeScript SDK checks remain additional release checks; live services are never enabled by the local runner. `COREVIA_DB_TESTS=1` is only for explicit isolated service suites with the documented CI-only databases, not production databases.

`release:check` remains false until matching local/browser evidence, a clean worktree, original 14-point/security/upgrade reviews and external acceptance are present. Operator-supplied records are not independent certification. No command pushes, merges, tags, publishes or changes the framework version. See [V3 upgrade notes](upgrade.md) before merging generated files into an existing site.
