import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout } from 'node:timers/promises';
import { root, run, launch, python } from './process.mjs';
import { prepareDemo } from './prepare-demo.mjs';
import { startDev } from './dev.mjs';
import { browserTools, browserChannel, ensureBrowser } from './browser.mjs';

await ensureBrowser();
await prepareDemo();
const servers = await startDev({ apiPort: 0, webPort: 0, handleSignals: false, quiet: true });
let serverError;
servers.done.catch(error => { serverError = error; });
let child, browser;
let childDone = false;
const interrupted = new AbortController();
const onStop = () => { interrupted.abort(); child?.kill('SIGINT'); };
process.once('SIGINT', onStop); process.once('SIGTERM', onStop);
const output = join(root, '.runtime/phase5');
mkdirSync(output, { recursive: true });
try {
  let ready = false;
  for (let i = 0; i < 80; i++) {
    if (serverError) throw serverError;
    try { ready = (await fetch(servers.apiUrl + '/api/health')).ok; } catch {}
    if (ready) break;
    await setTimeout(250, undefined, { signal: interrupted.signal });
  }
  assert.ok(ready, 'Demo API must start');
  await run('php', ['artisan', 'commutelive:provision-simulator', '--vehicles=2', '--route=DEMO-SYNTHETIC-OUT',
    '--allow-synthetic-route', '--consent-simulated-tracking', `--api-url=${servers.apiUrl}`], 'backend');
  child = launch(python, ['simulate.py', '--scenario', 'normal', '--vehicles', '2', '--seed', '42', '--speedup', '4',
    '--duration', '600', '--allow-synthetic-route', '--revoke-tokens'], 'simulator', { stdio: ['ignore', 'pipe', 'pipe'] });
  child.stdout.on('data', data => process.stdout.write(data)); child.stderr.on('data', data => process.stderr.write(data));
  child.once('error', error => { serverError = error; childDone = true; }); child.once('exit', () => { childDone = true; });
  const { chromium, expect } = browserTools();
  browser = await chromium.launch({ channel: browserChannel, headless: true });
  const page = await browser.newPage({ viewport: { width: 360, height: 800 }, hasTouch: true });
  const pageErrors = []; page.on('pageerror', error => pageErrors.push(error.message));
  // Avoid external tile requests in repeatable QA. Attribution and optional basemap remain in the UI.
  await page.route('https://tile.openstreetmap.org/**', route => route.abort());
  await page.goto(servers.webUrl);
  const list = (await (await fetch(servers.apiUrl + '/api/routes')).json()).data;
  const route = list.find(row => row.code === 'DEMO-SYNTHETIC-OUT');
  await page.getByRole('combobox', { name: 'Route direction' }).selectOption(String(route.id));
  await expect(page.locator('.vehicle-map-marker').first()).toBeVisible({ timeout: 30000 });
  await expect(page.locator('.simulation-banner')).toContainText('Simulated data');
  const marker = page.locator('.vehicle-map-marker').first();
  const initialPosition = await marker.getAttribute('style');
  await expect.poll(() => marker.getAttribute('style'), { timeout: 20000 }).not.toBe(initialPosition);
  await setTimeout(3500, undefined, { signal: interrupted.signal });
  await run('php', ['artisan', 'commutelive:update-traffic'], 'backend');
  await expect(page.locator('.live-map path[stroke="#16774b"], .live-map path[stroke="#b66b00"], .live-map path[stroke="#c43f3f"]').first()).toBeAttached({ timeout: 12000 });
  const detail = (await (await fetch(`${servers.apiUrl}/api/routes/${route.id}`)).json()).data;
  const target = detail.stops.at(-1);
  await page.getByRole('combobox', { name: 'Choose a stop', exact: true }).selectOption(String(target.id));
  const arrivals = page.getByRole('region', { name: 'Stop arrivals' });
  await expect(arrivals.locator('.arrival-row').first()).toBeVisible({ timeout: 12000 });
  const firstEta = await arrivals.locator('.arrival-time').first().textContent();
  await expect.poll(() => arrivals.locator('.arrival-time').first().textContent(), { timeout: 10000 }).not.toBe(firstEta);
  await arrivals.locator('.arrival-row').first().click();
  await expect(page.getByRole('region', { name: 'Vehicle details' })).toBeVisible();
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
  await page.screenshot({ path: join(output, 'live-simulator-360.png'), fullPage: true });
  servers.stopApiForCheck();
  await expect(page.getByRole('alert')).toContainText('We can’t reach live updates', { timeout: 20000 });
  await expect(page.locator('.arrival-time').first()).toHaveText('Paused', { timeout: 12000 });
  await expect(page.getByRole('heading', { name: 'Find your next sakay.' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Live vehicles, stops and traffic map' })).toBeVisible();
  await page.screenshot({ path: join(output, 'api-stopped-360.png'), fullPage: true });
  assert.deepEqual(pageErrors, []);
  const result = { is_simulated: true, viewport: { width: 360, height: 800 }, actual_simulator_api: true,
    moving_marker: true, colored_traffic: true, countdown_eta: true, first_eta: firstEta, vehicle_card: true,
    owned_api_killed: true, friendly_error_and_map_retained: true, paused_estimates: true, horizontal_overflow: false, page_errors: pageErrors };
  writeFileSync(join(output, 'live-commuter-verification.json'), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result, null, 2));
} finally {
  if (child && !childDone) {
    child.kill('SIGINT');
    for (let i = 0; i < 40 && !childDone; i++) await setTimeout(250);
  }
  await browser?.close();
  await run('php', ['artisan', 'commutelive:stop-simulator'], 'backend');
  process.removeListener('SIGINT', onStop); process.removeListener('SIGTERM', onStop);
  servers.close();
}
