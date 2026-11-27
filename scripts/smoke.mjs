import assert from 'node:assert/strict';
import { setTimeout } from 'node:timers/promises';
import { startDev } from './dev.mjs';

const servers = await startDev({ apiPort: 0, webPort: 0 });
let serverError;
servers.done.catch(error => { serverError = error; });
try {
  let ready = false;
  for (let attempt = 0; attempt < 80; attempt++) {
    if (serverError) throw serverError;
    try {
      const direct = await fetch(`${servers.apiUrl}/api/health`, { signal: AbortSignal.timeout(1000) });
      if (!direct.ok) continue;
      const response = await fetch(`${servers.webUrl}/api/health`, { signal: AbortSignal.timeout(1000) });
      if (response.ok) {
        assert.deepEqual(await response.json(), { status: 'ok', service: 'CommuteLive API' });
        ready = true;
        break;
      }
    } catch { /* Servers can take a moment to start. */ }
    await setTimeout(250);
  }
  assert.ok(ready, 'Vite must proxy the health request to Laravel');
  const page = await fetch(servers.webUrl);
  assert.equal(page.status, 200);
  assert.match(await page.text(), /src\/main\.jsx/);
  const app = await fetch(`${servers.webUrl}/src/App.jsx`);
  assert.equal(app.status, 200);
  assert.match(await app.text(), /API OK/);
  const unauthorized = await fetch(`${servers.webUrl}/api/user`, { headers: { Accept: 'application/json' } });
  assert.equal(unauthorized.status, 401);
  const routes = await fetch(`${servers.webUrl}/api/routes`);
  assert.equal(routes.status, 200);
  assert.ok(Array.isArray((await routes.json()).data), 'Routes must return a JSON data array');
  const traffic = await fetch(`${servers.webUrl}/api/traffic`);
  assert.equal(traffic.status, 200);
  const trafficBody = await traffic.json();
  assert.ok(Array.isArray(trafficBody.data), 'Traffic must return a JSON data array');
  assert.equal(typeof trafficBody.contains_simulated_data, 'boolean');
  const review = await fetch(`${servers.webUrl}/routes`);
  assert.equal(review.status, 200);
  console.log('HTTP smoke passed: React served, Laravel health proxied, protected endpoint rejects anonymous access.');
} finally {
  servers.close();
}
