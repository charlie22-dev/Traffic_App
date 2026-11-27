import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { setTimeout } from 'node:timers/promises';
import { run } from './process.mjs';
import { prepareDemo } from './prepare-demo.mjs';
import { startDev } from './dev.mjs';

const password = await prepareDemo();
const servers = await startDev({ apiPort: 0, webPort: 0, handleSignals: false, quiet: true });
let serverError;
servers.done.catch(error => { serverError = error; });
let token, tripId, vehicle;
const interrupted = new AbortController();
const onStop = () => interrupted.abort();
process.once('SIGINT', onStop);
process.once('SIGTERM', onStop);
async function request(path, method = 'GET', body) {
  const response = await fetch(`${servers.apiUrl}/api${path}`, {
    method, signal: AbortSignal.timeout(15000),
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });
  const json = await response.json();
  assert.ok(response.ok, `${method} ${path}: ${response.status} ${json.message ?? ''}`);
  return json;
}
try {
  let ready = false;
  for (let i = 0; i < 80; i++) {
    if (serverError) throw serverError;
    if (interrupted.signal.aborted) throw new Error('Demo interrupted.');
    try { if ((await request('/health')).status === 'ok') { ready = true; break; } } catch {}
    await setTimeout(250);
  }
  assert.ok(ready);
  token = (await request('/login', 'POST', { email: 'driver2@commutelive.test', password })).data.token;
  vehicle = (await request('/driver/vehicles')).data.find(v => v.label === 'SIM-JEEP-02');
  assert.ok(vehicle?.is_simulated);
  const route = (await request(`/routes/${vehicle.route_id}`)).data;
  const target = route.stops.at(-1);
  const privacy = (await request('/privacy/tracking')).data;
  tripId = (await request('/driver/trips/start', 'POST', { vehicle_id: vehicle.id, tracking_consent: true, privacy_notice_version: privacy.version })).data.id;
  console.log('Simulated data: tracking ON for fictional ETA demo.');
  async function ping(lng, speed) {
    if (interrupted.signal.aborted) throw new Error('Demo interrupted.');
    const response = await request('/driver/pings', 'POST', { trip_id: tripId, pings: [{
      client_ping_uuid: randomUUID(), lat: 14.65, lng, speed_kph: speed, heading: 90, accuracy_m: 5,
      recorded_at: new Date().toISOString(), is_simulated: true,
    }] });
    assert.equal(response.data.counts.accepted, 1);
  }
  await request('/driver/status', 'PUT', { trip_id: tripId, occupancy: 'seats' });
  await ping(120.951, 5);
  await ping(120.9511, 5);
  await ping(120.9512, 5);
  await run('php', ['artisan', 'commutelive:update-traffic'], 'backend');
  const traffic = await request(`/traffic?route_id=${vehicle.route_id}`);
  assert.equal(traffic.contains_simulated_data, true);
  assert.ok(traffic.data.every(s => s.is_simulated));
  assert.equal(traffic.data[0].status, 'red');
  const arrivalsPath = `/stops/${target.id}/arrivals`;
  const before = (await request(arrivalsPath)).data.find(v => v.vehicle_id === vehicle.id);
  assert.ok(before?.prediction_id && before.is_simulated);
  assert.equal(before.occupancy, 'seats');
  await setTimeout(1100, undefined, { signal: interrupted.signal });
  await ping(120.955, 20);
  const after = (await request(arrivalsPath)).data.find(v => v.vehicle_id === vehicle.id);
  assert.ok(after.eta_seconds < before.eta_seconds);
  console.log(JSON.stringify({ is_simulated: true, first_segment_status: traffic.data[0].status,
    baseline_source: traffic.data[0].baseline_source, eta_before_seconds: before.eta_seconds,
    eta_after_seconds: after.eta_seconds, logged_prediction_ids: [before.prediction_id, after.prediction_id] }, null, 2));
  // Explicit positional jump is a scripted API smoke check, not a travel-time measurement.
  await setTimeout(1100, undefined, { signal: interrupted.signal });
  await ping(120.96, 0);
  assert.ok(!(await request(arrivalsPath)).data.some(v => v.vehicle_id === vehicle.id));
  console.log('Arrival matching hides the reached stop. Scenario timing/accuracy is evaluated by the notebook.');
} finally {
  if (token && tripId) {
    try {
      await request(`/driver/trips/${tripId}/end`, 'POST');
      assert.ok(!(await request('/vehicles')).data.some(v => v.id === vehicle.id));
    } catch (error) { console.error('Trip cleanup failed:', error.message); process.exitCode = 1; }
  }
  if (token) {
    try { await request('/logout', 'POST'); } catch (error) { console.error('Token cleanup failed:', error.message); process.exitCode = 1; }
  }
  process.removeListener('SIGINT', onStop);
  process.removeListener('SIGTERM', onStop);
  servers.close();
  console.log('Synthetic ETA demo finished; cleanup requested tracking OFF and token revocation.');
}
