import { expect, test } from '@playwright/test';
import { syntheticApi } from './fixtures.js';

test('360px commuter map moves markers, counts down, filters and keeps location local', async ({ page, context }) => {
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const state = await syntheticApi(page);
  await context.grantPermissions(['geolocation']);
  await context.setGeolocation({ latitude: 14.652345, longitude: 120.952345 });
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Find your next sakay.' })).toBeVisible();
  await expect(page.locator('.simulation-banner')).toContainText('Simulated data');
  await expect(page.locator('.live-map path[stroke="#c43f3f"]').first()).toBeAttached();
  await expect(page.getByRole('link', { name: 'OpenStreetMap' })).toBeVisible();
  const marker = page.locator('.vehicle-map-marker[data-vehicle-id="1"]');
  await expect(marker).toBeVisible();
  const before = await marker.getAttribute('style');
  await expect.poll(() => marker.getAttribute('style'), { timeout: 10000 }).not.toBe(before);
  await page.getByRole('button', { name: 'Stop: Synthetic destination', exact: true }).click();
  const arrivals = page.getByRole('region', { name: 'Stop arrivals' });
  await expect(arrivals.getByRole('heading', { name: 'Synthetic destination' })).toBeVisible();
  const row = arrivals.getByRole('button', { name: /SIM-RIDE-01/ });
  await expect(row).toBeVisible();
  const firstEta = await row.locator('.arrival-time').textContent();
  await expect.poll(() => row.locator('.arrival-time').textContent()).not.toBe(firstEta);
  await row.click();
  await expect(page.getByRole('region', { name: 'Vehicle details' })).toContainText('Seats available');
  await page.getByRole('combobox', { name: 'Route direction' }).selectOption('1');
  await expect(page.locator('.vehicle-map-marker[data-vehicle-id="2"]')).toHaveCount(0);
  await page.getByRole('button', { name: 'Use my location' }).click();
  await expect(page.getByRole('region', { name: 'Nearest stops' })).toBeVisible();
  expect(state.requests.every(r => r.body === null && !r.path.includes('14.652345') && !r.path.includes('120.952345'))).toBe(true);
  const storage = await page.evaluate(() => JSON.stringify({ ...localStorage }));
  expect(storage).not.toContain('14.652345'); expect(storage).not.toContain('120.952345');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.getByText('Map legend', { exact: true }).click();
  await expect(page.getByText('No data', { exact: true })).toBeVisible();
  await page.screenshot({ path: 'test-results/commuter-360.png', fullPage: true });
  await page.reload();
  await expect(page.getByRole('region', { name: 'Nearest stops' })).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('API failure preserves the map, pauses ETAs, expires vehicles and reloads offline', async ({ page, context }) => {
  const state = await syntheticApi(page);
  await page.clock.install();
  await page.goto('/');
  await expect(page.locator('.vehicle-map-marker').first()).toBeVisible();
  await page.getByRole('combobox', { name: 'Choose a stop', exact: true }).selectOption('3');
  await expect(page.locator('.arrivals-list')).toBeVisible();
  await page.evaluate(() => navigator.serviceWorker.ready.then(() => true));
  state.fail = true;
  await expect(page.getByRole('alert')).toContainText('We can’t reach live updates', { timeout: 12000 });
  await expect(page.locator('.arrival-time').first()).toHaveText('Paused');
  await expect(page.getByRole('region', { name: 'Live vehicles, stops and traffic map' })).toBeVisible();
  await page.clock.fastForward(61000);
  await expect(page.locator('.vehicle-map-marker')).toHaveCount(0);
  await context.setOffline(true);
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Find your next sakay.' })).toBeVisible();
  await expect(page.getByRole('alert')).toContainText('You’re offline');
  await expect(page.getByText('Saved route view', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Stop: Synthetic destination', exact: true })).toBeVisible();
  await expect(page.locator('.vehicle-map-marker')).toHaveCount(0);
  const cached = await page.evaluate(async () => (await Promise.all((await caches.keys()).map(async key => (await (await caches.open(key)).keys()).map(r => r.url)))).flat());
  expect(cached.every(url => !new URL(url).pathname.startsWith('/api/') && !url.includes('tile.openstreetmap.org'))).toBe(true);
  await page.screenshot({ path: 'test-results/commuter-offline-360.png', fullPage: true });
});

test('manifest is installable and permission denial leaves manual stop selection usable', async ({ page }) => {
  await syntheticApi(page);
  await page.addInitScript(() => Object.defineProperty(navigator, 'geolocation', { value: {
    getCurrentPosition: (_, error) => error({ code: 1 }),
  } }));
  await page.goto('/');
  await page.getByRole('button', { name: 'Use my location' }).click();
  await expect(page.getByText(/Location permission was declined/)).toBeVisible();
  await page.getByRole('combobox', { name: 'Choose a stop', exact: true }).selectOption('2');
  await expect(page.getByRole('heading', { name: 'Synthetic middle stop' })).toBeVisible();
  const manifest = await (await page.request.get('/manifest.webmanifest')).json();
  expect(manifest.display).toBe('standalone'); expect(manifest.start_url).toBe('/');
  for (const icon of manifest.icons) {
    const response = await page.request.get(icon.src); expect(response.ok()).toBe(true);
    expect(icon.sizes).toMatch(/192x192|512x512/);
  }
  await expect(page.getByRole('button', { name: 'Install app' })).toBeVisible();
});
