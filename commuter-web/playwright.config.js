import { defineConfig } from '@playwright/test';
import { browserChannel } from '../scripts/browser.mjs';

export default defineConfig({
  testDir: './e2e', timeout: 45000, fullyParallel: false, workers: 1,
  reporter: 'list', outputDir: 'test-results',
  use: { baseURL: process.env.COMMUTELIVE_WEB_URL || 'http://127.0.0.1:4173',
    viewport: { width: 360, height: 800 }, deviceScaleFactor: 1, hasTouch: true,
    browserName: 'chromium', channel: browserChannel,
    trace: 'retain-on-failure', screenshot: 'only-on-failure',
  },
  webServer: process.env.COMMUTELIVE_WEB_URL ? undefined : {
    command: 'node node_modules/vite/bin/vite.js preview --host 127.0.0.1 --port 4173 --strictPort',
    url: 'http://127.0.0.1:4173', reuseExistingServer: false,
  },
});
