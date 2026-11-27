import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import VehicleCard from './VehicleCard.jsx';
import ArrivalsList from './ArrivalsList.jsx';
import Legend from './Legend.jsx';
import { effectiveTraffic, nearestStops, visibleVehicles } from './live.js';

const now = Date.parse('2026-11-27T02:00:00Z');
const vehicle = { id: 1, label: 'SIM-JEEP-01', mode: 'jeepney', occupancy: 'seats', recorded_at: new Date(now - 12000).toISOString(), is_simulated: true };
const stop = { id: 2, name: 'Synthetic destination' };
const arrival = { vehicle_id: 1, label: vehicle.label, occupancy: 'seats', confidence: 'medium', is_simulated: true, predicted_arrival_at: new Date(now + 30000).toISOString() };

describe('commuter widgets', () => {
  it('vehicle card names simulation, route, occupancy, position age and a countdown', () => {
    const close = vi.fn();
    const props = { vehicle, route: { name: 'Fictional route', direction: 'Outbound' }, selectedStop: stop, arrival, etaFresh: true, onClose: close };
    const { rerender } = render(<VehicleCard {...props} now={now} />);
    expect(screen.getByText('Simulated data')).toBeInTheDocument();
    expect(screen.getByText('Seats available')).toBeInTheDocument();
    expect(screen.getByText('Position 12s ago')).toBeInTheDocument();
    expect(screen.getByText('30s')).toBeInTheDocument();
    rerender(<VehicleCard {...props} now={now + 10000} />);
    expect(screen.getByText('20s')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Close vehicle details' }));
    expect(close).toHaveBeenCalledOnce();
  });
  it('never keeps counting a stale estimate as fresh', () => {
    render(<VehicleCard vehicle={vehicle} selectedStop={stop} arrival={arrival} etaFresh={false} now={now} onClose={() => {}} />);
    expect(screen.getByText('Estimate paused')).toBeInTheDocument();
    expect(screen.queryByText('30s')).not.toBeInTheDocument();
  });
  it('prompts for a selected stop without inventing arrivals', () => {
    render(<ArrivalsList stop={null} arrivals={[]} now={now} />);
    expect(screen.getByRole('heading', { name: 'Where are you waiting?' })).toBeInTheDocument();
    expect(screen.queryByRole('list')).not.toBeInTheDocument();
  });
  it('allows choosing an arrival and includes occupancy and confidence', () => {
    const choose = vi.fn();
    render(<ArrivalsList stop={stop} arrivals={[arrival]} now={now} fresh onVehicle={choose} />);
    fireEvent.click(screen.getByRole('button', { name: /SIM-JEEP-01/ }));
    expect(choose).toHaveBeenCalledWith(1);
    expect(screen.getByText('Simulated · medium confidence')).toBeInTheDocument();
  });
  it('shows a recoverable paused arrivals list and hides its countdown', () => {
    const retry = vi.fn();
    render(<ArrivalsList stop={stop} arrivals={[arrival]} now={now} fresh={false} error="offline" onRetry={retry} />);
    expect(screen.getByText('Paused')).toBeInTheDocument();
    expect(screen.queryByText('30s')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry arrivals' }));
    expect(retry).toHaveBeenCalledOnce();
  });
  it('legend explains colors and occupancy in words', () => {
    render(<Legend />);
    fireEvent.click(screen.getByText('Map legend'));
    for (const label of ['Flowing', 'Slowing', 'Heavy', 'No data', 'Empty', 'Seats available', 'Full', 'Load unknown']) expect(screen.getByText(label)).toBeInTheDocument();
  });
  it('expires local vehicle snapshots even if the API is gone', () => {
    const rows = [{ ...vehicle, recorded_at: new Date(now - 60000).toISOString() }];
    expect(visibleVehicles(rows, now, 60)).toHaveLength(1);
    expect(visibleVehicles(rows, now + 1000, 60)).toHaveLength(0);
  });
  it('shows grey for expired, offline and unknown traffic', () => {
    const row = { status: 'red', computed_at: new Date(now - 90000).toISOString() };
    expect(effectiveTraffic(row, now, 90, false)).toBe('red');
    expect(effectiveTraffic(row, now + 1000, 90, false)).toBe('unknown');
    expect(effectiveTraffic(row, now, 90, true)).toBe('unknown');
  });
  it('nearest stops use local spherical distance and deduplicate shared stops', () => {
    const stops = [{ id: 1, lat: 0, lng: 1 }, { id: 2, lat: 0, lng: 0 }, { id: 2, lat: 0, lng: 0 }];
    const result = nearestStops({ lat: 0, lng: 0 }, stops, 3);
    expect(result).toHaveLength(2);
    expect(result[0].id).toBe(2);
    expect(result[1].distance_m).toBeCloseTo(111195.0802, 3);
  });
});
