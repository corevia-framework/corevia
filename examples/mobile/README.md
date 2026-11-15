# Mobile examples

`flutter/offline_notes_page.dart` and `react-native/OfflineNotes.jsx` demonstrate
the Corevia offline synchronization SDK. Applications supply their own durable
atomic storage and authenticated backend transport. Recreate the page and queue
when switching account or tenant.

For an installable Android development example, follow the
[native device lab guide](../../docs/v4/mobile-device-lab.md). Its `lab/*.stub`
sources are copied into a new private Flutter project by
`python tools/v4-mobile-lab.py --output=storage/android-lab-new --flutter=FLUTTER`.
Nested templates include Kotlin platform integration and device/widget/file tests.

The ordinary lab simulates the server inside the phone. It needs no running PC
after installation. Its plain file adapter holds synthetic notes only; choose
production storage, encryption and identity policy for real applications.
React Native and iOS require separate native hosts and acceptance.
