import { ageLabel, ageSeconds, etaLabel, occupancyLabels } from './live.js';

export default function VehicleCard({ vehicle, route, arrival, selectedStop, now, etaFresh, onClose }) {
  return <section className="vehicle-card" aria-label="Vehicle details">
    <div className="section-heading"><p className="eyebrow">Your selected ride</p><button className="icon-button" onClick={onClose} aria-label="Close vehicle details">×</button></div>
    <h2>{vehicle.label}</h2>
    <p className="route-caption">{route?.name ?? 'Route'} <span>· {route?.direction ?? vehicle.mode}</span></p>
    {vehicle.is_simulated && <span className="simulation-tag">Simulated data</span>}
    <div className="vehicle-facts"><span className={`occupancy occupancy-${vehicle.occupancy}`}>{occupancyLabels[vehicle.occupancy] ?? occupancyLabels.unknown}</span>
      <span>Position {ageLabel(ageSeconds(vehicle.recorded_at, now))}</span></div>
    {selectedStop ? <div className="vehicle-eta"><span>To {selectedStop.name}</span>
      <strong>{!etaFresh ? 'Estimate paused' : arrival ? etaLabel(arrival.predicted_arrival_at, now) : 'No upcoming arrival'}</strong>
      <small>{arrival ? `${arrival.confidence} confidence · estimate, not a guarantee` : 'This stop may be behind this ride or lack a fresh position.'}</small>
    </div> : <p className="muted">Choose a stop to see its upcoming rides and this vehicle’s ETA.</p>}
  </section>;
}
