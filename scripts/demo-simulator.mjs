import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { setTimeout } from 'node:timers/promises';
import { root, run, launch, python } from './process.mjs';
import { startDev } from './dev.mjs';
import { prepareDemo } from './prepare-demo.mjs';

const args = process.argv.slice(2);
const option = (name, fallback) => args.includes(name) ? args[args.indexOf(name) + 1] : fallback;
const vehicles = Number(option('--vehicles', 3));
const duration = Number(option('--duration', 360));
const speedup = Number(option('--speedup', 20));
const scenario = option('--scenario', 'normal');
if (!Number.isInteger(vehicles) || vehicles < 1 || vehicles > 50 || !Number.isFinite(duration) || duration <= 0 || !Number.isFinite(speedup) || speedup <= 0 || !['normal', 'rush_hour', 'incident', 'sparse'].includes(scenario)) throw new Error('Invalid demo simulator arguments.');
await prepareDemo();
const servers = await startDev({ apiPort: 0, webPort: 0, handleSignals: false, quiet: true });
let serverError;
servers.done.catch(error => { serverError = error; });
let child;
let done = false;
let code;
const observations = new Map();
const onStop = () => child?.kill('SIGINT');
process.once('SIGINT', onStop);
process.once('SIGTERM', onStop);
try {
  await run('php', ['artisan', 'commutelive:provision-simulator', `--vehicles=${vehicles}`,
    '--route=DEMO-SYNTHETIC-OUT', '--allow-synthetic-route', '--consent-simulated-tracking', `--api-url=${servers.apiUrl}`], 'backend');
  child = launch(python, ['simulate.py', '--scenario', scenario, '--vehicles', String(vehicles), '--seed', '42',
    '--speedup', String(speedup), '--duration', String(duration), '--allow-synthetic-route', '--revoke-tokens'], 'simulator', { stdio: ['ignore', 'pipe', 'pipe'] });
  child.stdout.on('data', data => process.stdout.write(data));
  child.stderr.on('data', data => process.stderr.write(data));
  child.once('error', error => { serverError = error; done = true; });
  child.once('exit', exitCode => { code = exitCode; done = true; });
  while (!done) {
    if (serverError) throw serverError;
    try {
      const response = await fetch(`${servers.apiUrl}/api/vehicles`, { signal: AbortSignal.timeout(3000) });
      if (response.ok) for (const vehicle of (await response.json()).data) {
        if (!vehicle.label.startsWith('SIMULATOR-')) continue;
        assert.equal(vehicle.is_simulated, true);
        const record = observations.get(vehicle.label) ?? { min_m: vehicle.distance_along_route_m, max_m: vehicle.distance_along_route_m, observations: 0, segment_speeds: {} };
        record.min_m = Math.min(record.min_m, vehicle.distance_along_route_m);
        record.max_m = Math.max(record.max_m, vehicle.distance_along_route_m);
        record.observations++;
        if (vehicle.speed_kph > 0 && vehicle.segment_id) {
          const speeds = record.segment_speeds[vehicle.segment_id] ?? { min_kph: vehicle.speed_kph, max_kph: vehicle.speed_kph };
          speeds.min_kph = Math.min(speeds.min_kph, vehicle.speed_kph);
          speeds.max_kph = Math.max(speeds.max_kph, vehicle.speed_kph);
          record.segment_speeds[vehicle.segment_id] = speeds;
        }
        observations.set(vehicle.label, record);
      }
    } catch (error) { if (error instanceof assert.AssertionError) throw error; }
    await setTimeout(250);
  }
  if (serverError) throw serverError;
  assert.equal(code, 0, 'Simulator must complete and confirm tracking cleanup');
  const metadata = JSON.parse(readFileSync(join(root, 'simulator/output/run.json'), 'utf8'));
  assert.equal(metadata.completed, true);
  const moved = [...observations.values()].filter(record => record.max_m - record.min_m > 1);
  assert.ok(moved.length, 'Public API must show a vehicle at changing matched positions');
  const remaining = (await (await fetch(`${servers.apiUrl}/api/vehicles`)).json()).data.filter(item => item.label.startsWith('SIMULATOR-'));
  assert.equal(remaining.length, 0, 'All simulator vehicles must be hidden after cleanup');
  const result = { is_simulated: true, public_api_verified_moving: true, moving_vehicles: moved.length, observations: Object.fromEntries(observations) };
  if (metadata.incident) {
    const snapshot = JSON.parse(readFileSync(join(root, 'simulator/output/routes.json'), 'utf8'));
    const targetRoute = snapshot.routes.find(item => item.data.code === metadata.incident.route_code);
    const targetSegment = targetRoute.segments.find(item => item.sequence === metadata.incident.segment_sequence);
    const bound = snapshot.parameters.free_flow_kph[targetRoute.data.mode] * snapshot.parameters.vehicle_speed_factor_max
      * Math.max(...snapshot.parameters.hourly_speed_factors) * metadata.incident.speed_multiplier;
    const low = [...observations.values()].map(record => record.segment_speeds[targetSegment.id]?.min_kph).filter(value => value > 0 && value <= bound + 1e-6);
    assert.ok(low.length, 'Public API must expose a near-zero moving speed on the incident segment; lower speedup if transport skips the short observation window');
    result.incident_observed_publicly = true;
    result.incident_min_speed_kph = Math.min(...low);
  }
  writeFileSync(join(root, 'simulator/output/live-verification.json'), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result, null, 2));
  console.log('Simulator HTTP demo passed; all owned tracking is OFF.');
} finally {
  if (child && !done) { child.kill('SIGINT'); for (let i = 0; i < 40 && !done; i++) await setTimeout(250); }
  await run('php', ['artisan', 'commutelive:stop-simulator'], 'backend');
  process.removeListener('SIGINT', onStop);
  process.removeListener('SIGTERM', onStop);
  servers.close();
}
