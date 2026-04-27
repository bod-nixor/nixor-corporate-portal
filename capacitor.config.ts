import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.nixorcorporate.portal',
  appName: 'Nixor Corporate Portal',
  webDir: 'public',
  plugins: {
    CapacitorCookies: {
      enabled: true
    }
  }
};

export default config;
