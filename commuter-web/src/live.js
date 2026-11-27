// Display helpers do not send, store or reverse-geocode a commuter's position.
export const trafficLabels = { green: 'Flowing', amber: 'Slowing', red: 'Heavy', unknown: 'No data' };
export const trafficColors = { green: '#16774b', amber: '#b66b00', red: '#c43f3f', unknown: '#78817e' };
export const occupancyLabels = { empty: 'Empty', seats: 'Seats available', full: 'Full', unknown: 'Load unknown' };

export function ageSeconds(timestamp, now = Date.now()) {
  const value = Date.parse(timestamp);
  return Number.isFinite(value) ? Math.max(0, Math.floor((now - value) / 1000)) : Infinity;
}

export function ageLabel(seconds) {
  if (!Number.isFinite(seconds)) return 'Not updated yet';
  return seconds < 60 ? `${seconds}s ago` : `${Math.floor(seconds / 60)}m ago`;
}

export function etaLabel(arrival, now = Date.now()) {
  const seconds = Math.max(0, Math.ceil((Date.parse(arrival) - now) / 1000));
  if (!Number.isFinite(seconds)) return 'ETA unavailable';
  if (!seconds) return 'Due / unconfirmed';
  return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
}

export function haversine(a, b) {
  const rad = Math.PI / 180;
  const h = Math.sin((b.lat - a.lat) * rad / 2) ** 2
    + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin((b.lng - a.lng) * rad / 2) ** 2;
  return 6371008.8 * 2 * Math.asin(Math.sqrt(Math.min(1, h)));
}

export function nearestStops(position, stops, limit) {
  const unique = new Map();
  for (const stop of stops) if (!unique.has(stop.id)) unique.set(stop.id, { ...stop, distance_m: haversine(position, stop) });
  return [...unique.values()].sort((a, b) => a.distance_m - b.distance_m).slice(0, limit);
}

export function visibleVehicles(rows, now, staleSeconds) {
  return rows.filter(row => ageSeconds(row.recorded_at, now) <= staleSeconds);
}

export function effectiveTraffic(segment, now, staleSeconds, offline) {
  if (offline || ageSeconds(segment.computed_at, now) > staleSeconds) return 'unknown';
  return trafficLabels[segment.status] ? segment.status : 'unknown';
}
