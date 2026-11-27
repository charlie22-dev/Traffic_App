import { occupancyLabels, trafficLabels } from './live.js';

export default function Legend() {
  return <details className="legend">
    <summary>Map legend</summary>
    <div><h3>Traffic along the route</h3><ul className="legend-items">
      {Object.entries(trafficLabels).map(([key, name]) => <li key={key}><span className={`traffic-line traffic-${key}`} aria-hidden="true" />{name}</li>)}
    </ul><h3>Passenger load</h3><ul className="legend-items">
      {Object.entries(occupancyLabels).map(([key, name]) => <li key={key}><span className={`load-symbol load-${key}`} aria-hidden="true">{key === 'full' ? 'F' : key === 'seats' ? 'S' : key === 'empty' ? 'E' : '?'}</span>{name}</li>)}
    </ul><p className="muted">Colors show sampled speeds. Grey means there is not enough recent data.</p></div>
  </details>;
}
