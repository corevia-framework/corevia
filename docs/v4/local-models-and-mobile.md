# Local models and mobile validation

Model suitability belongs to the application: evaluate its actual prompts,
languages, retrieval data, refusals, accuracy, latency and cost. Framework
interoperability checks do not certify a model's quality or safety. External
model acceptance may be recorded as user-run; do not represent an untested model
as a framework pass.

## Installed Ollama models

The optional smoke runner selects only already-installed local models from the
literal loopback Ollama API. It rejects cloud-tag/remote metadata and tiny remote
placeholder manifests, downloads no weights and sends no user conversations.

```sh
python tests/v4/local-models.py --output=storage/local-model-check-new --models deepseek-coder:6.7b --max-tokens=8
```

Use an actual name from your own `ollama list`. The model above is an example,
not an installation request. The runner needs PHP and a running local Ollama
server. It exercises real Corevia SSE streaming, scoped cleanup, first-model
client-stream cancellation and schema-validated structured output. A model's
invalid JSON is recorded as rejected by the contract; no repair prompt or hidden
paid retry is sent. Cancellation stops the client's consumption; this check does
not prove the backend stopped computation or refunded usage. No embedding-model
or retrieval-quality pass is implied.

Each inference has the existing 120-second HTTP/work deadline. CPU inference and
cold loading may exceed it even with installed weights. Preserve the failed
receipt; reduce the explicit smoke workload or provision/warm a suitable model
server separately. Never remove deadlines merely to obtain a passing report.
The smoke token budget is recorded and must not be compared to a different
workload as a performance improvement. Prompts/output are not stored by this
runner; report/log files remain private under ignored storage. Local server
logging and retention are separate operator policies.

The report binds model digests, Ollama version, relevant source hashes and actual
results. `passed` requires unchanged source and successful selected checks;
`release_ready` remains false. A missing SDK/server or failed model is not a pass.
The runner is opt-in; normal application installs and CI need no model weights.
See [Ollama's compatibility reference](https://docs.ollama.com/api/openai-compatibility).

## Android testing without a permanent cable

| Method | PC connection | What it validates |
| --- | --- | --- |
| Android 11+ wireless debugging | Same-network pairing for installation/debugging; no USB cable | The actual phone and selected test application |
| Installed test APK against a reachable test backend | No ongoing PC/debug connection to use the app | Manual device/offline/restart/application workflows; backend must remain reachable when syncing |
| Android emulator on the development machine or CI | No personal phone needed | Repeatable native integration tests against the selected image; hardware/OEM differences remain |
| Optional hosted device lab | Build artifacts and an authorized account/service required | Selected real devices; costs, artifact upload and access need operator review |

A [disposable Flutter Android lab](mobile-device-lab.md) now supplies a generator,
file-backed synthetic server, offline note UI and optional device acceptance
runner. It can be installed as an ordinary app for independent offline practice.

A useful routine is emulator automation for changes, an installable test APK for
independent use, and physical-phone checks before a release. Native integration
requires an actual Flutter or React Native host app with a durable CAS adapter;
the existing example pages and Dart/JS HTTP fixtures alone are not device builds.
If inference runs on the PC, that PC still needs to serve inference when selected
by the backend. Moving the backend to a VPS does not move model weights there.

For Android 11+, enable Developer options and Wireless debugging on a trusted
network. The pairing popup's IP/port can differ from the main connection IP/port:

```sh
adb pair PHONE_IP:PAIRING_PORT
# Enter the short-lived pairing code in your own terminal, not logs or chat.
adb connect PHONE_IP:CONNECTION_PORT
adb devices
```

Use Google's official Platform Tools and verify the chosen archive against its
official repository metadata. Keep developer keys and device receipts out of Git.
The private Corevia workspace helper uses an isolated ADB server on port 15037;
commands against that helper's device must include `-P 15037`. It saves its device
receipt under ignored storage and does not save the pairing code. A successful
pairing is setup evidence, not an application test pass. Disable wireless
debugging when it is no longer needed. This is a development workflow; end users
do not need ADB to use a released app.

Primary references: [Android wireless debugging](https://developer.android.com/tools/adb),
[SDK user-state directories](https://developer.android.com/tools/variables),
[Flutter integration testing](https://docs.flutter.dev/testing/integration-tests),
and [Flutter physical-device performance limits](https://docs.flutter.dev/perf/ui-performance).
