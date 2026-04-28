import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.callcenteroperator.app',
  appName: 'Call Center Operator',
  webDir: 'dist',
  server: {
    androidScheme: 'https'
  }
};

export default config;
