import { useEffect, useState } from 'react';
import RouteMap from './RouteMap.jsx';

async function json(url, signal) {
  const response = await fetch(url, { signal, headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error(`Request failed (${response.status})`);
  return response.json();
}

export default function RouteReview() {
  const [routes, setRoutes] = useState([]);
  const [id, setId] = useState('');
  const [detail, setDetail] = useState(null);
  const [segments, setSegments] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [showTiles, setShowTiles] = useState(true);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const timeout = setTimeout(() => controller.abort(), 10000);
    setError('');
    setLoading(true);
    json('/api/routes', controller.signal).then(result => {
      if (!active) return;
      setRoutes(result.data);
      setId(previous => result.data.some(route => String(route.id) === previous) ? previous : String(result.data[0]?.id ?? ''));
      setLoading(false);
    }).catch(() => {
      if (active) { setError('Could not load routes. Check that the API is running and retry.'); setLoading(false); }
    }).finally(() => clearTimeout(timeout));
    return () => { active = false; clearTimeout(timeout); controller.abort(); };
  }, [attempt]);

  useEffect(() => {
    if (!id) { setDetail(null); setSegments([]); return; }
    const controller = new AbortController();
    let active = true;
    const timeout = setTimeout(() => controller.abort(), 10000);
    setDetail(null);
    setSegments([]);
    setError('');
    setLoading(true);
    Promise.all([json(`/api/routes/${id}`, controller.signal), json(`/api/routes/${id}/segments`, controller.signal)])
      .then(([route, result]) => {
        if (active) { setDetail(route.data); setSegments(result.data); setLoading(false); }
      }).catch(() => {
        if (active) { setError('Could not load this direction and its segments. Retry to reload the route list.'); setLoading(false); }
      }).finally(() => clearTimeout(timeout));
    return () => { active = false; clearTimeout(timeout); controller.abort(); };
  }, [id, attempt]);

  return (
    <main className="review-page">
      <header><a href="/">CommuteLive</a><span>Route data review</span></header>
      <h1>Pilot routes, stop by stop.</h1>
      <p>Review the path, ordered stops and fixed-length segments. This page shows static route data; it collects no location.</p>
      <div className="review-controls">
        <label>Route direction
          <select value={id} onChange={event => setId(event.target.value)} disabled={!routes.length}>
            {!routes.length && <option value="">No imported routes</option>}
            {routes.map(route => <option key={route.id} value={route.id}>{route.name} · {route.direction}</option>)}
          </select>
        </label>
        <label className="checkbox"><input type="checkbox" checked={showTiles} onChange={event => setShowTiles(event.target.checked)} /> Show OpenStreetMap basemap</label>
        <button onClick={() => setAttempt(value => value + 1)} disabled={loading}>Reload routes</button>
      </div>
      {loading && <p role="status">Loading route data…</p>}
      {error && <p role="alert">{error}</p>}
      {!loading && !error && routes.length === 0 && <p role="status">No source-backed route data has been imported. Complete the GeoJSON and stops.csv, then run the import and build-segments commands.</p>}
      {detail && <>
        {detail.is_simulated && <p className="simulation-badge" role="status">Simulated data</p>}
        <div className="review-notice" role="note">
          <strong>{detail.verification_status === 'field_verified' ? 'Recorded as field verified' : 'Needs local verification'}</strong>
          <p>{detail.source_metadata.notes || 'OSM or imported source data does not establish current service operation or legal boarding locations.'}</p>
          <p>Source: {detail.source_metadata.source} · License: {detail.source_metadata.license}</p>
        </div>
        <p>{(detail.length_m / 1000).toFixed(2)} km · {detail.stops.length} stops · {segments.length} segments. Alternating colors distinguish segments; they do not indicate traffic.</p>
        {!segments.length && <p role="alert">Segments have not been built for this geometry. Run commutelive:build-segments.</p>}
        <RouteMap route={detail} segments={segments} showTiles={showTiles} />
        <p className="scope">You can turn the basemap off to review the geometry without external tile requests. <a href="https://www.openstreetmap.org/fixthemap" target="_blank" rel="noreferrer">Report a map issue</a></p>
        <h2>Stops in travel order</h2>
        <div className="table-scroll"><table><thead><tr><th>Order</th><th>Stop</th><th>Along route</th><th>Offset</th><th>Verification</th></tr></thead>
          <tbody>{detail.stops.map(stop => <tr key={stop.sequence}><td>{stop.sequence}</td><td>{stop.name}</td><td>{stop.distance_along_route_m.toFixed(0)} m</td><td>{stop.offset_m.toFixed(1)} m</td><td>{stop.verification_status}</td></tr>)}</tbody>
        </table></div>
      </>}
    </main>
  );
}
