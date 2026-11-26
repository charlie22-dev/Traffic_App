import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RouteReview from './RouteReview.jsx';

vi.mock('./RouteMap.jsx', () => ({ default: ({ route, segments }) => <div data-testid="map">{route.code}: {segments.length} segments</div> }));

const route = {
  id: 1, code: 'TEST-OUT', name: 'Test fixture', direction: 'outbound', length_m: 400,
  verification_status: 'needs_review', source_metadata: { source: 'test fixture', license: 'test', notes: 'Do not use as a real route.' },
  stops: [{ id: 1, sequence: 1, name: 'Test stop', distance_along_route_m: 0, offset_m: 0, verification_status: 'needs_review' }],
};

function respond(data) { return Promise.resolve({ ok: true, json: async () => data }); }

describe('route data review', () => {
  it('explains the empty database instead of drawing invented geometry', async () => {
    vi.stubGlobal('fetch', vi.fn(() => respond({ data: [] })));
    render(<RouteReview />);
    expect(await screen.findByText(/No source-backed route data has been imported/)).toBeInTheDocument();
    expect(screen.queryByTestId('map')).not.toBeInTheDocument();
  });

  it('loads a direction, its stop order and segments, with an unverified warning', async () => {
    vi.stubGlobal('fetch', vi.fn(url => {
      if (url === '/api/routes') return respond({ data: [route] });
      if (url.endsWith('/segments')) return respond({ data: [{ sequence: 1 }, { sequence: 2 }] });
      return respond({ data: route });
    }));
    render(<RouteReview />);
    expect(await screen.findByText('Needs local verification')).toBeInTheDocument();
    expect(screen.getByTestId('map')).toHaveTextContent('TEST-OUT: 2 segments');
    expect(screen.getByRole('cell', { name: 'Test stop' })).toBeInTheDocument();
    expect(screen.getByText(/they do not indicate traffic/)).toBeInTheDocument();
  });

  it('handles API failure and lets the user retry', async () => {
    const fetch = vi.fn().mockRejectedValueOnce(new Error('offline')).mockImplementation(() => respond({ data: [] }));
    vi.stubGlobal('fetch', fetch);
    render(<RouteReview />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load routes');
    fireEvent.click(screen.getByRole('button', { name: 'Reload routes' }));
    expect(await screen.findByText(/No source-backed route data/)).toBeInTheDocument();
  });

  it('does not show a stale direction when changing selection during a pending request', async () => {
    let resolveFirst;
    vi.stubGlobal('fetch', vi.fn(url => {
      if (url === '/api/routes') return respond({ data: [route, { ...route, id: 2, name: 'Return fixture' }] });
      if (url === '/api/routes/1') return new Promise(resolve => { resolveFirst = resolve; });
      if (url.endsWith('/segments')) return respond({ data: [] });
      return respond({ data: { ...route, id: 2, code: 'TEST-RETURN' } });
    }));
    render(<RouteReview />);
    await waitFor(() => expect(resolveFirst).toBeTypeOf('function'));
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '2' } });
    expect(await screen.findByTestId('map')).toHaveTextContent('TEST-RETURN');
    resolveFirst({ ok: true, json: async () => ({ data: route }) });
    await waitFor(() => expect(screen.getByTestId('map')).toHaveTextContent('TEST-RETURN'));
  });
});
