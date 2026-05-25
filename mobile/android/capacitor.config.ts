import type { CapacitorConfig } from '@capacitor/cli';

/**
 * Produkční build: optica.lowpartners.net
 * Beta test APK: před `cap sync` změň server.url na https://lowpartners.net
 */
const config: CapacitorConfig = {
  appId: 'net.lowpartners.optica',
  appName: 'Optický sklad',
  webDir: 'www',
  server: {
    url: 'https://optica.lowpartners.net',
    cleartext: false,
    androidScheme: 'https',
    allowNavigation: [
      'optica.lowpartners.net',
      'lowpartners.net',
    ],
  },
  android: {
    allowMixedContent: false,
  },
};

export default config;
