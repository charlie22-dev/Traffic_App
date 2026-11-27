import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { createRequire } from 'node:module';
import { root, run, windows } from './process.mjs';

const requireWeb = createRequire(join(root, 'commuter-web/package.json'));
const edgePresent = windows && [process.env['ProgramFiles(x86)'], process.env.ProgramFiles]
  .some(folder => folder && existsSync(join(folder, 'Microsoft/Edge/Application/msedge.exe')));
export const browserChannel = process.env.PLAYWRIGHT_CHANNEL || (edgePresent ? 'msedge' : undefined);
export function browserTools() { return requireWeb('@playwright/test'); }
export async function ensureBrowser() {
  if (!browserChannel && !existsSync(browserTools().chromium.executablePath())) {
    await run(process.execPath, ['node_modules/playwright/cli.js', 'install', 'chromium'], 'commuter-web');
  }
}
