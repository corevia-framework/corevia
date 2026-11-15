# Phase D: tenant isolation increment

Release update: this file records the development increment. The current V4.0 disposition is in the [final scope review](final-review.md), [release notes](release.md) and [deferred-target guide](optional-validation.md). Earlier pending statements are historical, not a claim that unverified targets later passed.

Phase D is in progress. Tenant-bound data/IO helpers are now accompanied by the [identity, audit and account-data APIs](identity-and-data.md) and refreshed multilingual starters. External service/provider/device, manual accessibility/security and final source-bound acceptance remain open.

## Resolve authority on the server

```php
$scope = (new Corevia\Auth\TenantAccess($gate))
    ->resolve($authenticatedContext, $requestedTenant, 'records.read');
```

`TenantAccess` checks current membership using `Gate`; an authenticated user cannot select another organization's tenant ID without permission. Resolve a separate capability for writes. API routes must also enforce bearer scopes through `ApiTokenMiddleware`. `TenantScope` accepts a server-owned authority callback for service integrations; constructing one directly is privileged application code, not authentication. Never deserialize it or trust a request-supplied authority flag.

Bound adapters revalidate authority on every operation:

- `TenantRecords($sql, $scope, 'records', ['id', 'title'])`: find/page/insert/update/delete with an unremovable tenant predicate and writable-field restrictions. The application schema needs `tenant_id VARCHAR(64)` and a unique `(tenant_id,id)` key. The tenant column contains a deterministic namespace digest, protecting distinct IDs even under case-insensitive SQL collations. No raw query or join escape hatch is exposed.
- `TenantDocuments($documents, $scope)`: separate deterministic collection namespaces. A caller cannot inject `tenant_id` through a document. Logical collection names retain existing document-store validation.
- `TenantCache($tenantDocuments)`: bounded-TTL value cache. It is neither a distributed lock nor an authoritative quota store.
- `TenantDisk($privateDisk, $scope)`: opaque leaf filenames within separate private tenant namespaces; no caller-selected paths. Secure the underlying disk against untrusted filesystem writers.
- `TenantVectors($index, $scope)`: bound retrieval adapter; the caller never supplies a tenant to search/put/delete. Provision a separate scope for read/write authorization as appropriate.

`TenantJobs($queue, $independentPrivateKey)` signs a bounded tenant/actor/task/input envelope before dispatch. `dispatch($scope, $task, $input, true)` requires an explicit retry-safety promise. Workers call `execute($claim, $resolveCurrentAuthority, $handler)`. The resolver must check current account/service credentials and membership, returning the exact matching scope. Missing/revoked authority, tampering and expiration fail closed. The handler receives a stable signed-envelope idempotency key even if a queue message is copied to a new claim. A signature proves dispatch integrity, not current membership; never replace the resolver with an unconditional true callback. Keys and application state must be restored together. Existing signed work requires reconciliation if its signing key is rotated without retaining it.

Use scoped adapters in application containers and handlers; do not expose underlying global stores/raw connections to untrusted extensions. These wrappers are application authorization boundaries, not a sandbox for hostile PHP code. Privileged cross-tenant administration remains a separate explicit application policy.

## Evidence and remaining work

`php tests/v4/tenants.php` verifies SQL/document/cache/file/vector/job escape negatives, signed-envelope tampering, authority mismatch and membership revocation. `python tests/v4/concurrency.py` exercises SQLite write races in independent processes. MySQL/PostgreSQL/MongoDB versions of these new tenant adapters still require real-service acceptance.

Optional WebAuthn, service-account/organization SSO, audit integrity/retention and account-data workflows now have working local implementations and fixtures. The three auth redesigns have local browser and automated accessibility coverage; native React account management passes SQLite/MFA/provider/accessibility browser checks. Actual provider/device/service validation and manual review remain required. No real provider login or accessibility certification is claimed.
