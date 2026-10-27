# V1 to V2 migration

1. Keep a backup and record the current application revision. V1 is recoverable from v1.0.0.
2. Run `php corevia upgrade:check`. It only reads application files and prints findings plus manual-review items.
3. Register modules and application hooks before boot, and actions/flows/listeners inside Module::register. Existing modules need no new interfaces. Optional lifecycle hooks are documented in kernel.md.
4. Use a new Corevia instance after shutdown. Custom entrypoints should put boot and execution inside try/finally and call shutdown. Bundled HTTP/CLI entrypoints already release resources.
5. Keep the CVP/1 default while upgrading peers independently. Opt into 2.0 explicitly in PHP/Python; the new JavaScript createCorevia facade defaults to 2.0. CoreviaClient retains the old 1.0 default. The old fixture that rejected 2.0 now uses unsupported 9.0 because V2 is supported.
6. Authorize resume and approval operations. Deploy compatible action/condition code for paused runs or version the flow name. Inspect running/compensating jobs after crashes instead of blindly replaying side effects. Manual retry of a compensated run is rejected.
7. Review Composer name changes to corevia-framework/corevia. PDO is optional for core but still needed by the checkout demo. Settings/cache files now live in config/settings and private storage.
8. Configure bearer tokens or a signing policy for remote HTTP endpoints outside loopback. Embedded URL credentials are rejected. Loopback development workers can still run without a token, but production gateways must authorize every exposed action.
9. Run application tests, `php corevia test`, SDK contract checks, and live integration checks before deployment. A source compatibility scan cannot prove arbitrary user code safe.

The representative V1 fixture in tests/fixtures/v1 preserves the original Module, Action, flow, boot configuration and idempotency APIs and runs under V2. No migration command mutates application source automatically. Backward-incompatible protocol selection is explicit; CVP/1 has not been removed.
