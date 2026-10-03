# Specification analysis and implementation record

Source: `Corevia_V1_Codex_Implementation_Spec.docx`, provided by the user. The document was read as project requirements; the user's request authorized implementation in the current project directory. No deployment, publication, account access or external messaging was requested.

The specification describes a backend runtime and protocol ecosystem. It does not ask for a conventional MVC framework or a marketing website. The implementation therefore focuses on explicit modular execution, separate adapters, failure semantics, cross-language transport and inspectable run records. A small browser page demonstrates the runtime.

## Milestone mapping

| Milestone | Implemented artifact / evidence |
| --- | --- |
| M1 skeleton | Composer project, PSR-4 namespaces, zero external PHP runtime packages, test/lint commands, MIT license, CI matrix |
| M2 core | Container, transactional module registration, dependency/cycle checks, scoped diagnostics, ActionRunner and explicit Error/ActionResult |
| M3 flows | State subtypes, sequential/conditional steps, critical/non-critical semantics, bounded retry policy, timing, store interface, manual retry and idempotency |
| M4 HTTP | Five routing verbs, parameters, middleware, JSON, CORS, request/trace headers, flow adapter and opt-in authorized action gateway |
| M5 events | EventBus contract, synchronous listener implementation, isolated errors and demo OrderCreated handler |
| M6 realtime | Independent WebSocket process, masked-frame codec/fragmentation, channels, private authorization, heartbeat, rate/size limits and authenticated loopback broadcast IPC |
| M7 protocol | CVP/1 serializer/validator, JSON Schema, shared cross-language fixtures and stable errors |
| M8 SDKs | JavaScript HTTP/realtime/reconnect/errors/types, optional React helper, Python worker/client and PHP RemoteAction |
| M9 persistence | PDO SQLite runs/steps/idempotency tables, atomic reservations and exclusive retry locks |
| M10 developer tools | CLI commands/generators/doctor, three-module checkout, Python and browser examples, architecture/API/protocol/deployment documentation |

## Important implementation choices

1. Modules and Actions implement explicit interfaces. Flow definitions are value/build objects; named or registered class steps are accepted. The sample avoids static mutable facades/global application state.
2. The example action signatures in the document are illustrative; PHP interfaces require compatible method signatures. All actions receive `State` and `ExecutionContext`; domain State subclasses provide typed accessors. Returned state must preserve the expected subtype.
3. PDO storage is SQLite-specific in the reference adapter. SQL stays outside core and other stores can implement RunStore. The optional failed-operations table is omitted; failed operations/retry metadata are already persisted in flow steps.
4. Realtime uses standard PHP streams and JSON. HTTP processes send broadcasts to a local, secret-authenticated control listener because PHP-FPM and realtime have separate process memory. This is a local IPC adapter, not a broker.
5. The minimal PHP demo uses a local risk rule. Setting `COREVIA_FRAUD_URL` selects real HTTP Python fraud checks; cross-process tests exercise that route. No SDK response is simulated.
6. Sample payments/email are explicitly demonstrations. Unique operation keys guard the demo ledger and show how a provider integration must protect side effects.
7. Idempotency reserves the whole flow before executing steps. It reuses persisted runs and rejects changed input. Crash recovery of interrupted critical work is explicitly outside this implementation; exactly-once business effects are not claimed.
8. React/mobile glue stays optional and transport-focused. No React application or native mobile project is required to run the PHP runtime.

## Local validation

Validated on Windows with PHP 8.3.35 (PDO SQLite), Node.js 22.14.0 and Python 3.13.3. The Windows app-store Python aliases were inaccessible; tests used the installed executable directly. The implementation is dependency-free at runtime; Composer generates autoloading without downloading runtime libraries.

Automated checks include:

- PHP unit/integration suite for container, modules, actions, flows, events, validation, logging, protocol, HTTP and PDO.
- JavaScript SDK shared fixtures, auth/results/IDs, errors/timeouts, reconnect, unsubscribe and private denial.
- Python shared fixtures, registration/dispatch, authorization, trace/error behavior, network roundtrip and non-finite validation.
- Real-process integration: PHP → Python action; public/private WebSocket subscription; origin/size/rate limits, ping/pong and fragmentation; HTTP checkout → Python risk → isolated notification warning → JS event; persistent duplicates/conflicts; critical payment stop; CLI inspect/manual retry/generators; JS gateway call and reconnect/restored event delivery.

CI is configured, but hosted GitHub Actions has not run in this local workspace. See `docs/validation.md` for final local command results. Known operational/security limits are listed in `docs/deployment.md`.
