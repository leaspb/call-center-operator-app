# Call Center Operator Frontend

Vue 3 + Vite operator workspace for the Laravel backend, packaged for both web and Android through Capacitor.

## Local web run

```bash
cp frontend/.env.example frontend/.env
cd frontend
npm install
npm run dev
```

Default web API URL is `http://localhost:8000/api/v1`. Reverb is optional: the UI subscribes to broadcasts when `VITE_REVERB_*` is configured and always keeps a 12-second polling fallback plus heartbeat for active assignments.

## Android packaging

The Android wrapper is generated with Capacitor and lives in `frontend/android`.

### Emulator environment

```bash
cp frontend/.env.android.example frontend/.env.android
```

The example points API/Reverb traffic to `10.0.2.2`, which is the Android Studio emulator alias for the host machine. For a physical device, replace it with an HTTPS endpoint or a reachable LAN host.

### Sync web assets into Android

```bash
cd frontend
npm run android:sync
```

This runs a Vite Android-mode build and `cap sync android`.

### Open in Android Studio

```bash
cd frontend
npm run android:open
```

### Build debug APK from CLI

```bash
cd frontend
npm run android:build
```

Prerequisites for CLI build: JDK 21 or newer, Android SDK, Android platform SDK matching `compileSdkVersion`, and Android build tools. If multiple JDKs are installed, run with an explicit Java home, for example `JAVA_HOME=$(/usr/libexec/java_home -v 21) npm run android:build` on macOS. If the SDK is installed but not on `PATH`, Android Studio can still build/open the project; for CLI builds set `ANDROID_HOME` or `ANDROID_SDK_ROOT` and include platform tools in `PATH`.

## Verification

```bash
npm test
npm run build
npm run android:sync
npm run android:build
```
