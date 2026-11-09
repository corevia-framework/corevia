# Native Android device lab

The optional lab builds an Android Flutter host around the real Corevia Dart SDK.
It is a development fixture, not a production application or a required framework
dependency. Ordinary Corevia PHP applications do not need Flutter, Java or Android
tools on their hosting server.

The normal app simulates its server inside the phone and keeps synthetic notes
in its own private app directory. Once installed, it can demonstrate queuing,
conflicts, lost acknowledgments and reopening state without a PC connection.
Real applications still need their configured backend when they synchronize.

## Generate and validate

Install a compatible Flutter SDK, Java and Android SDK on a build machine. The
reference setup uses Flutter 3.47.9/Dart 3.13.7, Java 21, Android platform 36 and
build-tools 36.0.0 and Flutter's selected NDK 28.2.13676358. The fixture uses
the template's supported NDK selection and standard native-library stripping.
Read and follow any license prompts from your chosen SDK installer. Do not bypass
host requirements or treat a missing build tool as a passing test.

```sh
python tools/v4-mobile-lab.py --output=storage/android-lab-new --flutter=/path/to/flutter
python tests/v4/mobile-lab.py --project=storage/android-lab-new --flutter=/path/to/flutter --android-sdk=/path/to/android-sdk
```

Use a new output directory. The generator copies only selected public Dart SDK,
license and fixture source files; generated user authentication files are not
included. It does not install an app or publish artifacts. The acceptance runner
checks Flutter analysis, widget behavior, real-file storage contracts and an APK
build. Its report remains `native_device_tested: false` without a selected device.
SDK/compiler/dependency versions and the generated lock files must accompany any
release-specific acceptance; a source hash alone is not a cross-toolchain binary
reproducibility guarantee.

The proxy's TLS/body-framing regression can run without a phone or Flutter:

```sh
python tests/v4/mobile-proxy.py --output=storage/mobile-proxy-new
```

It requires OpenSSL and validates fixed-length/chunked forwarding, the body cap
and malformed/ambiguous framing against disposable loopback servers.

Windows Flutter/Android SDK tools need paths without spaces. For the current
workspace, a temporary `K:` mapping points to ignored
`storage/android-device-lab/`; all mapped writes stay inside that directory.
Do not reuse a drive letter already assigned to another location. Build caches,
SDKs, keys, APKs, device identity and logs belong in ignored private storage.
The generator creates a dedicated `corevia-lab-debug.keystore` beside its output
directories and records an ownership hash. It reuses only a matching lab key so
repeat fixture installs keep the same signature. This uses the conventional
development password `android`; never use this key to sign a production release.

## Phone or emulator acceptance

Pair an authorized Android device or start an emulator. Then run:

Keep the phone unlocked with **Corevia Lab** visible throughout automated device
tests. Flutter's live test binding waits for app frames; switching to another app
can leave a test waiting even when the phone and ADB connection are healthy. The
integration-test entrypoint may show a blank screen. This requirement applies to
the automated suite, not ordinary use of the installed standalone example.

```sh
python tests/v4/mobile-lab.py --project=storage/android-lab-new --flutter=/path/to/flutter --android-sdk=/path/to/android-sdk --device=ADB_SERIAL --adb-port=5037
```

For the prepared private wireless toolkit use ADB port **15037**, rather than the
standard 5037. The runner selects the APK architecture from the device ABI; an
explicit `--target-platform` is also supported. Emulator runs validate that native
image, not a physical phone or an OEM's background/battery behavior.

The device mode:

1. Checks the selected device and builds a debug APK.
2. Refuses to replace an existing application without a matching prior lab
   receipt. It operates only on `dev.corevia.lab.corevia_mobile_lab`.
3. Starts a disposable loopback PHP/SQLite sync fixture and a private TLS frontend.
   A temporary ADB reverse mapping connects the selected device to that frontend;
   no public or LAN web listener is opened.
4. Tests filesystem reopen/CAS/receipt/conflict/tombstone behavior on Android and
   actual HTTPS synchronization, fresh tokens and tenant/auth separation against
   Corevia. It rejects the untrusted test certificate and a wrong hostname before
   using the explicit test CA for the correct host.
5. Seeds a synthetic queued edit, installs the ordinary standalone entrypoint,
   removes the reverse mapping and stops the PC backend, then force-stops and
   relaunches only the lab app twice. It verifies the queued probe and a fresh
   standalone launch marker in that app's own private files.
6. Records its actual results, source/driver hashes, APK digest and cleanup state
   under the generated project's `acceptance/` directory.

The test CA is trusted only in the integration-test process. It is not installed
in the phone's system trust store. The private CA key stays on the build machine.
No production account, model, email, site or application data is used. A successful
device receipt does not grant release readiness automatically.

For a separate graphics-compatibility check, generate a fresh project with
`--legacy-renderer`. This opts only that fixture into Flutter's OpenGL renderer;
the runner records `android_renderer: legacy-opengl` and applies the same option
to its test launch. A pass in that mode does not certify the default Impeller or
Vulkan path on that device. Inspect app-scoped logs when frames fail to arrive;
do not silently waive a failed test.

## Repeatable testing without a permanent PC connection

Use the installed **Corevia Lab** app for manual offline demonstrations. Wireless
debugging can be turned off after installation; it is needed again for updating
or collecting automated test results, not for opening the normal app. An APK can
also be built on a separate authorized build machine and installed later.

For routine automation, run the same fixture against a configured Android emulator
on a build machine or CI runner. Keep a physical-device pass for releases. The
repository's no-device SDK tests remain useful, but cannot substitute for an APK
build, platform networking, storage plugins or actual device lifecycle checks.

This lab's file adapter is intentionally limited to synthetic data and one Dart
isolate owning a path. It uses serialized compare/exchange and flushed same-volume
replacement. It is not an encrypted production store, multi-isolate transaction
service or guarantee against power loss. Application authors must supply their
required durable/encrypted storage, current user/tenant binding and server policy.
Re-key the example page when changing authenticated identity.

React Native, iOS, additional Android versions, real application adapters,
assistive technology, OS background modes and sustained device workloads need
their own acceptance. This fixture does not silently mark those targets passed.

References: [Flutter Android setup](https://docs.flutter.dev/platform-integration/android/setup),
[manual SDK paths](https://docs.flutter.dev/install/manual),
[Flutter integration testing](https://docs.flutter.dev/testing/integration-tests),
and [Android wireless debugging](https://developer.android.com/tools/adb).
