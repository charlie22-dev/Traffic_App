import { etaLabel, occupancyLabels } from './live.js';

export default function ArrivalsList({ stop, arrivals, loading, error, fresh, now, onVehicle, selectedVehicleId, onRetry }) {
  return <section className="arrivals-section" aria-label="Stop arrivals">
    <div className="section-heading"><p className="eyebrow">Next at this stop</p><span className="stop-glyph" aria-hidden="true">S</span></div>
    <h2>{stop ? stop.name : 'Where are you waiting?'}</h2>
    {!stop && <p className="muted">Tap a stop on the map or choose one below to see upcoming rides.</p>}
    {stop && loading && <p role="status">Checking upcoming rides…</p>}
    {stop && (error || !fresh) && !loading && <div className="inline-notice" role="status"><p>Arrival updates are paused. We’ll check again when connected.</p><button className="text-button" onClick={onRetry}>Retry arrivals</button></div>}
    {stop && !loading && !error && fresh && !arrivals.length && <p className="muted">No upcoming vehicles right now. Vehicles may have passed this stop or stopped sharing.</p>}
    {stop && !!arrivals.length && <ul className="arrivals-list">{arrivals.map(arrival => <li key={arrival.vehicle_id}>
      <button className={`arrival-row ${arrival.vehicle_id === selectedVehicleId ? 'is-selected' : ''}`} onClick={() => onVehicle(arrival.vehicle_id)} aria-pressed={arrival.vehicle_id === selectedVehicleId}>
        <span className="ride-glyph" aria-hidden="true">R</span><span className="arrival-info"><strong>{arrival.label}</strong>
          <span>{occupancyLabels[arrival.occupancy] ?? occupancyLabels.unknown}</span>
          <small>{arrival.is_simulated ? 'Simulated · ' : ''}{arrival.confidence} confidence</small></span>
        <span className="arrival-time">{fresh ? etaLabel(arrival.predicted_arrival_at, now) : 'Paused'}</span>
      </button>
    </li>)}</ul>}
    {!!arrivals.length && <p className="fine-print">Estimates can change with traffic. Confidence describes available samples, not certainty.</p>}
  </section>;
}
