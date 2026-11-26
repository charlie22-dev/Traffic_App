import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const colors = ['#1565c0', '#b23d00', '#7b1fa2', '#00796b'];

export default function RouteMap({ route, segments, showTiles }) {
  const container = useRef(null);
  useEffect(() => {
    if (!route) return;
    const map = L.map(container.current, { scrollWheelZoom: false });
    if (showTiles) {
      L.tileLayer(import.meta.env.VITE_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · ODbL',
        maxZoom: 19,
      }).addTo(map);
    } else {
      map.attributionControl.addAttribution('Route data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors / source shown above');
    }
    const base = L.geoJSON(route.geometry_geojson, { style: { color: '#123c35', weight: 9, opacity: 0.45 } });
    // Initialize the map view before attaching vector renderers, including in StrictMode.
    map.fitBounds(base.getBounds(), { padding: [24, 24], maxZoom: 16 });
    base.addTo(map);
    for (const [index, segment] of segments.entries()) {
      const layer = L.geoJSON(segment.polyline, { style: { color: colors[index % colors.length], weight: 5, opacity: 1 } }).addTo(map);
      const label = document.createElement('span');
      label.textContent = `Segment ${segment.sequence}: ${segment.start_m.toFixed(0)}–${segment.end_m.toFixed(0)} m (${segment.length_m.toFixed(1)} m)`;
      layer.bindPopup(label);
    }
    for (const stop of route.stops) {
      const marker = L.circleMarker([stop.lat, stop.lng], {
        radius: 6, color: '#123c35', fillColor: '#fff', fillOpacity: 1, weight: 2,
      }).addTo(map);
      const label = document.createElement('span');
      label.textContent = `${stop.sequence}. ${stop.name} · ${stop.distance_along_route_m.toFixed(0)} m · ${stop.verification_status}`;
      marker.bindPopup(label);
    }
    const observer = new ResizeObserver(() => map.invalidateSize());
    observer.observe(container.current);
    return () => { observer.disconnect(); map.remove(); };
  }, [route, segments, showTiles]);

  return <div ref={container} className="route-map" role="region" aria-label="Route geometry, stops and colored segments" />;
}
