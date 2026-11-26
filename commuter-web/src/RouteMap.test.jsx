import { StrictMode } from 'react';
import { render } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import L from 'leaflet';
import RouteMap from './RouteMap.jsx';

it('draws route, segment and stops with real Leaflet and survives StrictMode and basemap changes', () => {
  // jsdom has SVG elements but does not advertise their browser rendering capability.
  vi.spyOn(L.Browser, 'svg', 'get').mockReturnValue(true);
  vi.stubGlobal('ResizeObserver', class { observe() {} disconnect() {} });
  const route = {
    geometry_geojson: { type: 'LineString', coordinates: [[120.95, 14.65], [120.96, 14.65]] },
    stops: [{ lat: 14.65, lng: 120.95, name: 'Test fixture stop', sequence: 1, distance_along_route_m: 0, verification_status: 'needs_review' }],
  };
  const segments = [{ sequence: 1, start_m: 0, end_m: 250, length_m: 250, polyline: route.geometry_geojson }];
  const { container, rerender, unmount } = render(<StrictMode><RouteMap route={route} segments={segments} showTiles={false} /></StrictMode>);
  expect(container.querySelectorAll('.leaflet-overlay-pane path')).toHaveLength(3);
  rerender(<StrictMode><RouteMap route={route} segments={segments} showTiles /></StrictMode>);
  expect(container.querySelectorAll('.leaflet-overlay-pane path')).toHaveLength(3);
  unmount();
  expect(container.querySelector('.leaflet-container')).toBeNull();
});
