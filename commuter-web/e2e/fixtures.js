export async function syntheticApi(page) {
  const stops = [
    { id: 1, name: 'Synthetic origin', lat: 14.65, lng: 120.95, sequence: 1, distance_along_route_m: 0, is_simulated: true },
    { id: 2, name: 'Synthetic middle stop', lat: 14.65, lng: 120.955, sequence: 2, distance_along_route_m: 538, is_simulated: true },
    { id: 3, name: 'Synthetic destination', lat: 14.65, lng: 120.96, sequence: 3, distance_along_route_m: 1076, is_simulated: true },
  ];
  const line = { type: 'LineString', coordinates: [[120.95, 14.65], [120.96, 14.65]] };
  const routes = [1, 2].map(id => ({ id, code: `E2E-SYNTHETIC-${id}`, name: id === 1 ? 'Fictional jeepney line' : 'Fictional bus line',
    mode: id === 1 ? 'jeepney' : 'bus', direction: 'Synthetic outbound', is_simulated: true, verification_status: 'needs_review',
    geometry_geojson: line, stops, source_metadata: { source: 'Synthetic browser fixture' } }));
  const segments = routes.flatMap(r => [0, 1, 2, 3].map(i => ({ id: r.id * 10 + i, sequence: i + 1, route_id: r.id,
    start_m: i * 269, end_m: (i + 1) * 269, length_m: 269, is_simulated: true,
    polyline: { type: 'LineString', coordinates: [[120.95 + i * .0025, 14.65], [120.95 + (i + 1) * .0025, 14.65]] } })));
  const state = { fail: false, polls: 0, requests: [], arrivalAt: new Date(Date.now() + 90000).toISOString() };
  await page.route('https://tile.openstreetmap.org/**', route => route.abort());
  await page.route('**/api/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    state.requests.push({ path: url.pathname + url.search, body: request.postData() });
    if (state.fail) return route.abort('connectionrefused');
    const now = new Date().toISOString();
    const routeId = Number(url.searchParams.get('route_id'));
    let payload;
    if (url.pathname === '/api/client-config') payload = { data: { poll_interval_ms: 4000, routes_interval_ms: 30000,
      request_timeout_ms: 8000, eta_stale_seconds: 12, route_cache_days: 7, animation_ms: 900, nearest_stop_limit: 3,
      vehicle_stale_seconds: 60, traffic_stale_seconds: 90 } };
    else if (url.pathname === '/api/routes') payload = { data: routes };
    else if (/^\/api\/routes\/\d+\/segments$/.test(url.pathname)) payload = { data: segments.filter(s => s.route_id === Number(url.pathname.split('/')[3])) };
    else if (/^\/api\/routes\/\d+$/.test(url.pathname)) payload = { data: routes.find(r => r.id === Number(url.pathname.split('/')[3])) };
    else if (url.pathname === '/api/traffic') payload = { data: segments.filter(s => !routeId || s.route_id === routeId).map((s, i) => ({
      ...s, segment_id: s.id, status: ['green', 'amber', 'red', 'unknown'][i % 4], computed_at: now, is_simulated: true,
    })), contains_simulated_data: true, as_of: now };
    else if (url.pathname === '/api/vehicles') {
      state.polls++;
      payload = { data: routes.filter(r => !routeId || r.id === routeId).map(r => ({ id: r.id, route_id: r.id, label: `SIM-RIDE-0${r.id}`, mode: r.mode,
        lat: 14.65, lng: 120.951 + state.polls * .00015, occupancy: r.id === 1 ? 'seats' : 'full', recorded_at: now, is_simulated: true })), contains_simulated_data: true };
    } else if (/^\/api\/stops\/\d+\/arrivals$/.test(url.pathname)) payload = { data: [1, 2].map(id => ({ vehicle_id: id, route_id: id,
      label: `SIM-RIDE-0${id}`, stop_id: Number(url.pathname.split('/')[3]), occupancy: id === 1 ? 'seats' : 'full', confidence: 'medium',
      predicted_arrival_at: state.arrivalAt, prediction_id: id, is_simulated: true })), as_of: now, contains_simulated_data: true };
    else return route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ message: 'Unknown synthetic fixture endpoint' }) });
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(payload) });
  });
  return state;
}
