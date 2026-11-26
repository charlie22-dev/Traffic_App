import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFileSync, writeFileSync } from 'node:fs';
import { randomBytes, randomUUID } from 'node:crypto';
import { join } from 'node:path';
import { setTimeout } from 'node:timers/promises';
import { root, run } from './process.mjs';
import { startDev } from './dev.mjs';

// Local-only synthetic demo: no device GPS and no credentials sent off-machine.
const argumentsList = process.argv.slice(2);
const holdIndex = argumentsList.indexOf('--hold-seconds');
const holdSeconds = holdIndex < 0 ? 15 : Number(argumentsList[holdIndex + 1]);
if (!Number.isInteger(holdSeconds) || holdSeconds < 0 || holdSeconds > 60) throw new Error('--hold-seconds must be a whole number from 0 to 60.');
await run('php', ['artisan', 'config:clear'], 'backend');
const { stdout } = await promisify(execFile)('php', ['-r',
  "require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(['password' => config('commutelive.demo_password'), 'environment' => app()->environment()]);"],
{ cwd: join(root, 'backend'), windowsHide: true });
const runtimeConfig = JSON.parse(stdout);
if (!['local', 'testing'].includes(runtimeConfig.environment)) throw new Error('The synthetic demo runs only in local/testing environments.');
let password = runtimeConfig.password;
if (!password) {
  password = randomBytes(24).toString('base64url');
  const envPath = join(root, 'backend', '.env');
  let contents = readFileSync(envPath, 'utf8');
  contents = /^DEMO_PASSWORD=.*$/m.test(contents)
    ? contents.replace(/^DEMO_PASSWORD=.*$/m, `DEMO_PASSWORD=${password}`)
    : `${contents.trimEnd()}\nDEMO_PASSWORD=${password}\n`;
  writeFileSync(envPath, contents);
  console.log('Generated demo credentials in ignored backend/.env. Credentials and tokens are not printed.');
}
if (typeof password !== 'string' || password.length < 12) throw new Error('Set DEMO_PASSWORD to at least 12 characters in backend/.env.');
await run('php', ['artisan', 'migrate', '--no-interaction'], 'backend');
await run('php', ['artisan', 'db:seed', '--no-interaction'], 'backend');
const servers = await startDev({ apiPort: 0, webPort: 0, handleSignals: false });
const stopRequested = new AbortController();
const onStop = () => { console.log('Ending synthetic tracking…'); stopRequested.abort(); };
process.once('SIGINT', onStop);
process.once('SIGTERM', onStop);
let serverError;
servers.done.catch(error => { serverError = error; });
let token;
let tripId;
let ended = false;
async function request(path, method = 'GET', body, cleanup = false) {
  if (stopRequested.signal.aborted && !cleanup) throw new Error('Demo interrupted; ending synthetic tracking.');
  const response = await fetch(servers.apiUrl + '/api' + path, {
    method, signal: AbortSignal.timeout(10000),
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  const data = await response.json();
  if (!response.ok) throw new Error(`${method} ${path} failed (${response.status}): ${data.message || 'API error'}`);
  return data;
}
try {
  let ready = false;
  for (let i = 0; i < 80; i++) {
    if (serverError) throw serverError;
    try { if ((await fetch(servers.apiUrl + '/api/health')).ok) { ready = true; break; } } catch {}
    await setTimeout(250);
  }
  assert.ok(ready, 'Local demo API did not start');
  token = (await request('/login', 'POST', { email: 'driver1@commutelive.test', password })).data.token;
  const vehicle = (await request('/driver/vehicles')).data.find(vehicle => vehicle.label === 'SIM-JEEP-01');
  assert.ok(vehicle?.is_simulated, 'The demo must use an explicitly simulated vehicle');
  const privacy = (await request('/privacy/tracking')).data;
  // Running this explicit demo authorizes synthetic tracking only.
  const start = await request('/driver/trips/start', 'POST', { vehicle_id: vehicle.id, tracking_consent: true,
    privacy_notice_version: privacy.version, start_battery_pct: 100 });
  tripId = start.data.id;
  console.log('Simulated data — Tracking ON for synthetic demo vehicle only. No device GPS is used.');
  const ping = { client_ping_uuid: randomUUID(), lat: 14.65, lng: 120.951, speed_kph: 20, heading: 90,
    accuracy_m: 5, recorded_at: new Date().toISOString(), is_simulated: true };
  const batch = await request('/driver/pings', 'POST', { trip_id: tripId, pings: [ping, ping] });
  assert.equal(batch.data.counts.accepted, 1);
  assert.equal(batch.data.counts.duplicates, 1);
  await request('/driver/status', 'PUT', { trip_id: tripId, occupancy: 'seats' });
  const position = (await request(`/vehicles?route_id=${vehicle.route_id}`)).data.find(item => item.id === vehicle.id);
  assert.ok(position?.is_simulated);
  assert.equal(position.occupancy, 'seats');
  assert.ok(position.segment_id);
  console.log(JSON.stringify({ accepted: 1, duplicates: 1, vehicle: position }, null, 2));
  console.log(`Live JSON: ${servers.apiUrl}/api/vehicles?route_id=${vehicle.route_id}`);
  console.log(`Synthetic route review: ${servers.webUrl}/routes (choose the synthetic demo line).`);
  if (holdSeconds) { console.log(`Tracking ON for ${holdSeconds} seconds, then the script ends the trip.`); await setTimeout(holdSeconds * 1000, undefined, { signal: stopRequested.signal }); }
  await request(`/driver/trips/${tripId}/end`, 'POST', { end_battery_pct: 99 });
  ended = true;
  assert.ok(!(await request('/vehicles')).data.some(item => item.id === vehicle.id), 'Ended vehicles must be hidden');
  console.log('Tracking OFF. Verified the ended vehicle is absent from the live feed.');
} finally {
  if (token && tripId && !ended) {
    try { await request(`/driver/trips/${tripId}/end`, 'POST', undefined, true); console.log('Tracking OFF. Synthetic trip ended.'); } catch { console.error('Demo cleanup could not confirm trip end; sign out or end the demo trip before re-running.'); }
  }
  if (token) { try { await request('/logout', 'POST', undefined, true); } catch { /* Server shutdown still follows. */ } }
  process.removeListener('SIGINT', onStop);
  process.removeListener('SIGTERM', onStop);
  servers.close();
}
