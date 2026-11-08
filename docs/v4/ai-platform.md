# AI revisions, contracts and permissioned tools

These optional APIs extend the V3 AI client. They do not require a database, Python, vector service or model for ordinary framework pages. No public tool-execution/approval endpoint or external provider is enabled by installing the framework.

## Database-free provider testing and a local knowledge example

```sh
php tests/v4/ai-platform.php
php examples/ai/knowledge.php migrate
php examples/ai/knowledge.php seed
php examples/ai/knowledge.php ask "PHP"
```

The first command uses explicit fake model output and a fake HTTP vector service. The knowledge example requires optional PDO SQLite and writes only ignored `storage/ai-knowledge-example/`. Its word-count embeddings are a teaching fixture, not a semantic model. It contacts no provider and incurs no inference charges. Application code lives in the example; framework behavior lives in the installed packages.

## Structured output

`StructuredOutput($aiClient, $actionContract, 'answer')` buffers one complete answer, decodes JSON and validates the named action's output contract. `ContractManifest` implements the runtime-neutral `ActionContract` port, sharing the existing documented schema subset with PHP/Python/Dart/JS contracts. Use an explicit object schema with required fields, bounds and `additionalProperties: false` as appropriate.

```php
$structured = new Corevia\Ai\StructuredOutput($client, $manifest, 'answer');
$value = $structured->complete([['role' => 'user', 'content' => $question]]);
```

The schema instruction is included in a system message; it is not a provider-native JSON-mode promise. Results are validated before being returned. Markdown fences, malformed JSON and contract mismatches fail; there are no repair prompts, silent coercions or automatic paid retries. Schemas are limited to 16 KiB, outputs to 64 KiB, with bounded JSON depth and existing client message/output limits. Object/list/null distinctions are preserved. Application code must still authorize and interpret a valid result: a schema match is not evidence that a model statement or requested action is safe or correct.

## Immutable document revisions

```php
$catalog = new Corevia\Persistence\SqlRevisionCatalog($dedicatedSql);
$vectors = new Corevia\Ai\SqlRevisionVectors($vectorSql); // bounded exact-search reference
$catalog->migrate(); $vectors->migrate(); // operator setup
$knowledge = new Corevia\Ai\KnowledgeBase($embeddings, $vectors, $catalog, 'pinned-model-v1');
$created = $knowledge->replace($scope, 'handbook', expectedVersion: 0, text: $text);
$hits = $knowledge->search($scope, 'How do I start?', limit: 5);
$knowledge->delete($scope, 'handbook', expectedVersion: $created['version']);
$knowledge->collect($scope); // explicit bounded removal of retired/failed vectors
```

Resolve `TenantScope` from current server authority with `ai.documents.read`/`ai.documents.write`; never construct it from an unverified tenant header. Documents hold immutable staging revisions and one atomic SQL active pointer. UTF-8 input is limited to 64 KiB, split into at most 128 chunks of 1000 Unicode characters, embedded in batches of 16. Provider batch cardinality, dimensions and finite nonzero vectors are validated. Network inference/vector writes happen outside catalog transactions.

All chunks are written before pointer activation. Competing writers use the expected document version; one wins and stale writers fail explicitly. Queries pass the **active revision list into the index**, so more-similar staging/retired chunks cannot crowd out current results. Results are bounded/validated and active metadata is checked again before return; a concurrent pointer change produces `KNOWLEDGE_REVISION_CHANGED`. Deleted document versions remain as tombstones, preventing stale revision-0 recreation. A model-identity mismatch requires explicit reindexing; names alone do not attest model binaries. Pin provider/model/dimension/chunking configuration operationally.

If activation commits but its reply is lost, cleanup checks catalog state and preserves the active vectors. Failed/retired vectors can be collected idempotently; active and staging revisions are refused. A revoked authority or unavailable catalog can leave staging resources for an operator to reconcile after proving the original writer is quiescent. Do not arbitrarily mark a running ingestion failed. There is no automatic provider retry or refund after an uncertain embedding call.

The catalog is a bounded reference: at most 128 retained document identities and 128 unfinished revisions per tenant. Document tombstones and revision metadata remain until an explicit application retention/migration policy handles them. SQL exact search caps active chunks at 5000. Larger document catalogs require an explicit sharding/catalog/index design; these bounds are not an unlimited-scale claim. Qdrant supports service-backed approximate search within the catalog's explicit revision bound.

Catalog/vector data are application data. Register them in the application's export/deletion adapters and legal-retention policy. SQL/catalog backups and external vector snapshots must correspond; do not restore one side independently and assume retrieval remains coherent. Staging and revision IDs namespace chunks; generic V3 retrieval/index APIs remain available and do not acquire this revision lifecycle automatically.

## Optional Qdrant index

```php
$vectors = new Corevia\Ai\QdrantVectorIndex(
    'https://vectors.internal.example', 'handbook_v1', dimensions: 384, apiKey: $privateKey,
);
$vectors->createCollection(); // explicit provisioning; fails if the collection already exists
$vectors->assertCollection(); // validate configured dimensions and cosine distance
```

The adapter implements both `VectorIndex` and `RevisionVectorIndex`. It uses fixed operator-owned origins/collection names, bounded requests/responses, deterministic tenant-qualified point IDs and mandatory tenant predicates. Returned payload ownership/point IDs are checked before returning text. Revision queries additionally filter and validate the active revision set. Provisioning creates tenant/revision keyword indexes; existing collections need operator-reviewed index setup. Deletes bind tenant and point/revision. API keys remain server-side.

Collection migration/deletion is not automatic. Use a separate collection for embedding configuration changes; a dimension mismatch fails. The native transport verifies HTTPS and rejects redirects, and propagates current work budgets. Literal loopback HTTP requires explicit development flags and an appropriate development transport. Custom transports must preserve those guarantees. Qdrant v1.20.0 is the actual staging-tested version; other versions are not certified by interface compatibility. See the official [query](https://api.qdrant.tech/api-reference/search/query-points) and [upsert](https://api.qdrant.tech/api-reference/points/upsert-points) contracts.

## Retrieval evaluation

`RetrievalEvaluation($knowledge)->run($scope, $cases, limit: 5)` accepts 1–100 labeled cases (`id`, `question`, unique expected document IDs). It computes hit rate, mean precision/recall and mean reciprocal rank over distinct retrieved documents. `top_k_chunks` states that the underlying retrieval limit is chunks; repeated chunks do not become multiple relevant documents. Reports exclude questions and retrieved text. All cases are validated before querying; each query uses current tenant authority and work budgets. Running it against a real embedding provider makes real configured calls. These deterministic retrieval metrics do not evaluate generated-answer truthfulness, prompt-injection resistance or tool safety.

## Permissioned tools and human approval

Register a `ToolDefinition` with a static name, capability, action contract, pinned implementation revision, handler, approval requirement (default true) and timeout (1–30000 ms). `ToolBroker` is request/run-scoped, permits at most 16 calls (default 8), and never discovers plugins or executes model-supplied source code. Models can propose names/JSON arguments only; registration, capabilities and approval policy belong to the host application.

```php
$approvals = new Corevia\Persistence\SqlToolApprovals($approvalSql);
$approvals->migrate();
$definition = new Corevia\Ai\ToolDefinition(
    'inventory.read', 'inventory.read', 'inventory.read', 'implementation-v1',
    new Corevia\Ai\CallableTool($trustedApplicationHandler), requiresApproval: true,
);
$broker = new Corevia\Ai\ToolBroker($manifest, $approvals, $app->scopes, [$definition]);
$proposal = $broker->propose($requesterScope, 'inventory.read', $arguments);
// Present the validated proposal arguments in an application-owned human review screen.
// Approve with HumanToolApproval below, then execute the exact same name/arguments/request ID.
$result = $broker->execute($requesterScope, 'inventory.read', $arguments, $proposal['request_id']);
```

The requester needs the tool's own capability plus `ai.tools.request`/`ai.tools.execute`. Approval fingerprints bind tenant, requester, arguments, tool capability/action/revision, schemas, approval mode and timeout. Changing any of them requires a new proposal. `SqlToolApprovals` stores hashes/metadata, not raw tool arguments. It enforces an explicit, different approver actor with `ai.tools.approve`, expiry and single-use consumption. Its low-level scope API trusts the host's identity classification; use the following human-session helper for browser review:

```php
$review = new Corevia\Auth\HumanToolApproval($identityStore, $auth, $tenantAccess, $approvals);
$review->approve($request, $humanAuthContext, $tenant, $proposalId, $reviewedFingerprint);
```

This requires a current human session, fresh password/MFA confirmation and current tenant role; API bearer credentials are refused. The application must mount its own authenticated, CSRF-protected review route, display the exact validated arguments corresponding to the fingerprint, enforce any tool-specific reviewer policy and audit the decision. Never expose approval as an AI tool or accept a model's claim that a human approved it. No approval endpoint or email notification is installed automatically.

Approval is consumed **before** execution. The handler receives server authority, a cooperative budget and stable `tool:<request-id>` effect key. Use the key for downstream idempotency. Failure, timeout, invalid output or an uncertain external effect does not restore approval or silently retry. Reconcile first, then request a new review if appropriate. Non-approval tools still require capabilities, a one-use policy request and the run budget. Expired receipts have an explicit bounded retention operation; configure it for long-lived deployments.

`CallableTool` executes trusted application code in-process and is not a sandbox. `TransportTool` uses an explicitly registered `ActionTransport` target, with `ProcessTransport` or `HttpActionTransport` adapters. Process calls use fixed executable/argument arrays, bounded output/time and an optional explicit child environment allowlist. They do not invoke a shell. A child still has its OS user's filesystem/network privileges: run untrusted tools under an actual restricted OS identity/container/remote service with approved mounts/network/resources. An environment allowlist is not an OS sandbox. Real remote authorization, process descendants and untrusted-code isolation require their own deployment acceptance. Scope resources close on success/failure; model data never receives an application container or arbitrary file/command API.

## V4 AI starter

`php corevia starter:ai` remains additive and requires an auth starter first. It ships prebuilt CSS/JS, uses a common server-rendered page across Bootstrap/Tailwind/React installations and does not load the React auth shell on that page. It includes English/Urdu/Arabic/RTL presentation, light/dark preferences, keyboard focus, an honest empty/unconfigured state, text-only streamed responses, cancellation/partial-response status, and clear/error states. No conversation history or provider key enters browser storage. Clearing the page does not erase a provider's records; the configured provider/application owns that policy. Automated accessibility scans and translation catalogs are not manual screen-reader/translation acceptance.

## Evidence and open gates

`tests/v4/ai-platform.php`, `knowledge.php`, `tools.php` and `human-tool-review.php` cover validation, revision/CAS/uncertain-commit behavior, stale/tombstone/model/tenant checks, one-use approvals and human-session boundaries. Eight-process SQLite WAL races cover one document activation and one approval consumer. The disposable SQL suite includes knowledge/tool contracts. The optional `COREVIA_VECTOR_TESTS=1` contract requires a dedicated literal loopback Qdrant fixture with `COREVIA_TEST_QDRANT_ORIGIN` and `COREVIA_TEST_QDRANT_KEY`; it creates/removes only a random `cv_test_*` collection.

Actual provider/worker/model acceptance, sustained vector/catalog load, OS-isolated tool execution, mobile/offline SDK workflows and final cloud/release validation remain separate gates. No live existing application or real email is used by these fixtures.
