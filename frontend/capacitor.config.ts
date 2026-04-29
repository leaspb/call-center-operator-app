import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.callcenteroperator.app',
  appName: 'Call Center Operator',
  webDir: 'dist',
  server: {
    // Local Android emulator uses HTTP to reach Docker on the host through 10.0.2.2.
    // Production builds should point VITE_API_BASE_URL to HTTPS and can remove cleartext.
    androidScheme: 'http',
    cleartext: true,
    allowMixedContent: true,
  }
};

export default config;
