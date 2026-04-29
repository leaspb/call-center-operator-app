import type { CapacitorConfig } from '@capacitor/cli';

const isLocalAndroid = process.env.CAPACITOR_ANDROID_LOCAL === '1';

const config: CapacitorConfig = {
  appId: 'com.callcenteroperator.app',
  appName: 'Call Center Operator',
  webDir: 'dist',
  android: {
    allowMixedContent: isLocalAndroid,
  },
  server: {
    // Release default is HTTPS-only. Local emulator builds opt into HTTP through
    // CAPACITOR_ANDROID_LOCAL=1 and the Android debug network security overlay.
    androidScheme: isLocalAndroid ? 'http' : 'https',
    cleartext: isLocalAndroid,
  },
  cordova: {
    // Do not emit Cordova's default wildcard <access origin="*" />.
    accessOrigins: [],
  },
};

export default config;
