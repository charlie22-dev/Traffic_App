import { useCallback, useMemo, useState } from 'react';
import LiveMap from './LiveMap.jsx';
import Legend from './Legend.jsx';
import VehicleCard from './VehicleCard.jsx';
import ArrivalsList from './ArrivalsList.jsx';
import InstallPrompt from './InstallPrompt.jsx';
import PwaUpdate from './PwaUpdate.jsx';
import useResource, { fetchJson, useClock, useConnection } from './useResource.js';
import { ageLabel, ageSeconds, effectiveTraffic, nearestStops, occupancyLabels, visibleVehicles } from './live.js';

export default function App() {
  const online = useConnection();
  const now = useClock();
  const [routeId, setRouteId] = useState('all');
  const [stopId, setStopId] = useState(null);
  const [vehicleId, setVehicleId] = useState(null);
  const [showTiles, setShowTiles] = useState(true);
  const [position, setPosition] = useState(null);
  const [locationState, setLocationState] = useState('');
  const config = useResource('/api/client-config', { online, cacheKey: 'settings' });
  const settings = config.data?.data;
  const options = { online, timeout: settings?.request_timeout_ms ?? 8000, cacheDays: settings?.route_cache_days ?? 7 };
  const inventory = useResource('/api/routes', { ...options, cacheKey: 'routes', interval: settings?.routes_interval_ms ?? null });
  const routeList = inventory.data?.data ?? [];
  const ids = routeList.filter(route => routeId === 'all' || String(route.id) === routeId).map(route => route.id).join(',');
  const loadGeometry = useCallback(async signal => {
    const rows = await Promise.all(ids.split(',').filter(Boolean).map(async id => {
      const [route, segments] = await Promise.all([fetchJson(`/api/routes/${id}`, signal), fetchJson(`/api/routes/${id}/segments`, signal)]);
      return { ...route.data, segments: segments.data };
    }));
    return { data: rows };
  }, [ids]);
  const geometry = useResource(ids ? `/geometry/${ids}` : null, { ...options, cacheKey: ids ? `geometry:${ids}` : undefined, loader: loadGeometry });
  const routes = geometry.data?.data ?? [];
  const query = routeId === 'all' ? '' : `?route_id=${routeId}`;
  const canPoll = !!settings && !!ids;
  const liveOptions = { ...options, interval: settings?.poll_interval_ms ?? null };
  const vehiclesFeed = useResource(canPoll ? `/api/vehicles${query}` : null, liveOptions);
  const trafficFeed = useResource(canPoll ? `/api/traffic${query}` : null, liveOptions);
  const stops = useMemo(() => [...new Map(routes.flatMap(route => route.stops).map(stop => [stop.id, stop])).values()], [routes]);
  const stop = stops.find(item => item.id === stopId) ?? null;
  const arrivalsFeed = useResource(settings && stop ? `/api/stops/${stop.id}/arrivals` : null, liveOptions);
  const vehicles = useMemo(() => visibleVehicles(vehiclesFeed.data?.data ?? [], now, settings?.vehicle_stale_seconds ?? 0), [vehiclesFeed.data, now, settings]);
  const traffic = useMemo(() => (trafficFeed.data?.data ?? []).map(segment => ({ ...segment,
    displayStatus: effectiveTraffic(segment, now, settings?.traffic_stale_seconds ?? 0, !online || !!trafficFeed.error) })), [trafficFeed.data, trafficFeed.error, now, settings, online]);
  const arrivals = arrivalsFeed.data?.data ?? [];
  const etaFresh = !!settings && online && !arrivalsFeed.error && ageSeconds(arrivalsFeed.data?.as_of, now) <= settings.eta_stale_seconds;
  const vehicle = vehicles.find(item => item.id === vehicleId);
  const simulated = routeList.some(route => (routeId === 'all' || String(route.id) === routeId) && route.is_simulated)
    || routes.some(route => route.is_simulated) || vehicles.some(item => item.is_simulated) || traffic.some(item => item.is_simulated) || arrivals.some(item => item.is_simulated);
  const failed = config.error || inventory.error || geometry.error || vehiclesFeed.error || trafficFeed.error;
  const loading = inventory.loading || config.loading || geometry.loading;
  const paused = !online || !!failed;
  const lastUpdate = vehiclesFeed.updatedAt ?? inventory.updatedAt;
  const nearby = useMemo(() => position ? nearestStops(position, stops, settings?.nearest_stop_limit ?? 0) : [], [position, stops, settings]);
  function retry() { config.retry(); inventory.retry(); geometry.retry(); vehiclesFeed.retry(); trafficFeed.retry(); arrivalsFeed.retry(); }
  function chooseRoute(value) { setRouteId(value); setStopId(null); setVehicleId(null); }
  function revealOnPhone(selector) {
    if (window.matchMedia?.('(max-width: 780px)').matches) requestAnimationFrame(() => {
      document.querySelector(selector)?.scrollIntoView({ block: 'start', behavior: 'instant' });
    });
  }
  function chooseMapStop(id) { setStopId(id); revealOnPhone('.arrivals-section'); }
  function chooseVehicle(id) { setVehicleId(id); revealOnPhone('.vehicle-card'); }
  function locate() {
    if (!navigator.geolocation) { setLocationState('Location is unavailable in this browser. Choose a stop instead.'); return; }
    setLocationState('Finding nearby stops…');
    navigator.geolocation.getCurrentPosition(result => {
      setPosition({ lat: result.coords.latitude, lng: result.coords.longitude });
      setLocationState('Nearby stops are sorted by straight-line distance. Check crossings and boarding rules locally.');
    }, error => {
      setLocationState(error.code === 1 ? 'Location permission was declined. You can still choose any stop.' : 'Could not find your location. Try again or choose a stop.');
    }, { enableHighAccuracy: false, maximumAge: 0, timeout: settings?.request_timeout_ms ?? 8000 });
  }
  return <main className="commuter-page">
    <a className="skip-link" href="#ride-panel">Skip map and choose a stop</a>
    <header className="commuter-header"><a className="brand" href="/" aria-label="CommuteLive home"><span className="brand-mark" aria-hidden="true">c<span>·</span></span>CommuteLive</a>
      <div className="header-actions"><span className={`connection-pill ${paused ? 'paused' : ''}`}><i aria-hidden="true" />{loading ? 'Connecting' : paused ? 'Updates paused' : 'Connected'}</span><InstallPrompt /></div>
    </header>
    <PwaUpdate />
    <div className="page-intro"><div><p className="eyebrow">A little less guesswork</p><h1>Find your next sakay.</h1><p>Jeepneys, buses, and the stops ahead.</p></div>
      <div className="pilot-label">Local route pilot<span>{vehicles.length} recent {vehicles.length === 1 ? 'ride' : 'rides'}</span></div></div>
    {simulated && <div className="simulation-banner" role="note"><strong>Simulated data</strong><span>Demo rides and estimates are not real traffic measurements.{routes.some(r => r.is_simulated) ? ' This fictional route is not a boarding recommendation.' : ''}</span></div>}
    {paused && <div className="connection-notice" role="alert"><div><strong>{!online ? 'You’re offline.' : 'We can’t reach live updates.'}</strong><p>You can still explore the route. Positions expire as they age; estimates pause without fresh updates. {lastUpdate && `Last updated ${ageLabel(Math.max(0, Math.floor((now - lastUpdate) / 1000)))}.`}</p></div><button className="quiet-button" onClick={retry}>Try again</button></div>}
    <div className="commute-layout"><section className="map-panel" aria-label="Explore your commute">
      <div className="map-toolbar"><label className="route-filter">Route direction<select value={routeId} onChange={event => chooseRoute(event.target.value)}><option value="all">All pilot directions</option>{routeList.map(route => <option value={route.id} key={route.id}>{route.name} · {route.direction}</option>)}</select></label>
        <button className="location-button" onClick={locate} disabled={!stops.length || locationState === 'Finding nearby stops…'}><span aria-hidden="true">⌖</span> Use my location</button></div>
      <LiveMap routes={routes} vehicles={vehicles} traffic={traffic} selectedStop={stop} selectedVehicleId={vehicleId} position={position} showTiles={showTiles}
        animationMs={settings?.animation_ms ?? 0} onStop={chooseMapStop} onVehicle={chooseVehicle} />
      <div className="map-meta"><span>{loading ? 'Loading the pilot map…' : geometry.cached ? 'Saved route view' : `${routes.length} route ${routes.length === 1 ? 'direction' : 'directions'}`}</span><label><input type="checkbox" checked={showTiles} onChange={event => setShowTiles(event.target.checked)} /> Basemap</label></div>
      {!loading && !routes.length && <div className="empty-map" role="status"><strong>The pilot map is getting ready.</strong><p>{failed ? 'Route data is unavailable. Try again when connected.' : 'Routes and stops are still being verified. No service coverage is implied yet.'}</p></div>}
      <Legend />
      {routes.some(route => !route.is_simulated && route.verification_status !== 'field_verified') && <p className="verification-note">Route geometry needs local verification. Check current service and safe boarding places before travelling.</p>}
    </section>
    <aside className="ride-panel" id="ride-panel" aria-label="Stops and rides" tabIndex="-1">
      <ArrivalsList stop={stop} arrivals={arrivals} loading={arrivalsFeed.loading} error={arrivalsFeed.error} fresh={etaFresh} now={now} onVehicle={chooseVehicle} selectedVehicleId={vehicleId} onRetry={arrivalsFeed.retry} />
      <section className="stop-picker" aria-label="Choose a stop"><label htmlFor="stop-select">Choose a stop</label><select id="stop-select" value={stop?.id ?? ''} onChange={event => setStopId(Number(event.target.value) || null)}><option value="">Select a stop</option>{stops.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></section>
      {!!locationState && <div className="location-note" role="status"><p>{locationState}</p>{position && <button className="text-button" onClick={() => { setPosition(null); setLocationState(''); }}>Clear my location</button>}</div>}
      {!!nearby.length && <section className="nearby-stops" aria-label="Nearest stops"><h3>Near you</h3><p className="fine-print">Straight-line distance, not a walking route.</p><ul>{nearby.map(item => <li key={item.id}><button className="stop-row" onClick={() => setStopId(item.id)}><span>{item.name}{item.is_simulated && <small>Simulated stop</small>}</span><strong>{Math.round(item.distance_m)} m</strong></button></li>)}</ul></section>}
      {vehicle ? <VehicleCard vehicle={vehicle} route={routes.find(r => r.id === vehicle.route_id)} arrival={arrivals.find(a => a.vehicle_id === vehicle.id)} selectedStop={stop} now={now} etaFresh={etaFresh} onClose={() => setVehicleId(null)} />
        : vehicleId !== null ? <div className="inline-notice" role="status">This ride no longer has a fresh position.<button className="text-button" onClick={() => setVehicleId(null)}>Close vehicle details</button></div> : null}
      <section className="ride-list" aria-label="Recent vehicles"><div className="section-heading"><h3>On the route</h3><span>{vehicles.length}</span></div>{!vehicles.length && <p className="muted">No fresh vehicle positions right now. Routes can still be explored.</p>}
        <ul>{vehicles.map(item => <li key={item.id}><button className={`ride-row ${item.id === vehicleId ? 'is-selected' : ''}`} onClick={() => chooseVehicle(item.id)} aria-pressed={item.id === vehicleId}><span className="ride-glyph" aria-hidden="true">{item.mode === 'bus' ? 'B' : 'J'}</span><span><strong>{item.label}</strong><small>{occupancyLabels[item.occupancy] ?? occupancyLabels.unknown}</small></span><span className="position-age">{ageLabel(ageSeconds(item.recorded_at, now))}</span></button></li>)}</ul>
      </section>
      <p className="privacy-note">Your location stays on this device. It is only used after you tap “Use my location” and is cleared on reload. Basemap tiles are requested from OpenStreetMap; you can turn them off.</p>
    </aside></div>
    <footer className="commuter-footer"><span>Made for the daily sakay.</span><nav aria-label="Project information"><a href="/routes">Route sources</a><a href="/status">Connection check</a></nav></footer>
  </main>;
}
