# CommuteLive Driver

Android-only Flutter starter, generated with Flutter 3.47.6 and Dart 3.13.5. Run root `npm run setup` first. With an emulator or USB-connected Android device ready, run `flutter run` here.

The Phase 0 screen shows **Tracking OFF**. No GPS permission, background service or trip broadcast exists yet. Later phases must introduce explicit driver consent and a visible tracking indicator before collecting location.

`flutter analyze` and `flutter test` run through the root test command. Build a development APK with `flutter build apk --debug`; output: `build/app/outputs/flutter-apk/app-debug.apk`. It is ignored by Git. Production signing and GPS behavior are later-phase work.
