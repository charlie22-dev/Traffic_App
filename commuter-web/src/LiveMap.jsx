import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { occupancyLabels, trafficColors } from './live.js';

export function moveMarker(marker, target, duration, reduced) {
  const start = marker.getLatLng();
  let frame;
  const began = performance.now();
  const draw = time => {
    const fraction = reduced || !duration ? 1 : Math.min(1, (time - began) / duration);
    marker.setLatLng([start.lat + (target[0] - start.lat) * fraction, start.lng + (target[1] - start.lng) * fraction]);
    if (fraction < 1) frame = requestAnimationFrame(draw);
  };
  frame = requestAnimationFrame(draw);
  return () => cancelAnimationFrame(frame);
}

export default function LiveMap({ routes, vehicles, traffic, selectedStop, selectedVehicleId, position, showTiles,
  animationMs, onStop, onVehicle }) {
  const container = useRef(null);
  const map = useRef(null);
  const vectors = useRef(null);
  const markers = useRef(new Map());
  const stopsLayer = useRef(null);
  const locationLayer = useRef(null);
  const tile = useRef(null);
  const callbacks = useRef({ onStop, onVehicle });
  callbacks.current = { onStop, onVehicle };
  const fitted = useRef('');
  const [tileError, setTileError] = useState(false);
  useEffect(() => {
    const instance = L.map(container.current, { zoomControl: false, scrollWheelZoom: false }).setView([14.66, 120.96], 13);
    L.control.zoom({ position: 'bottomright' }).addTo(instance);
    instance.attributionControl.addAttribution('<a href="/routes">Route sources</a>');
    vectors.current = L.layerGroup().addTo(instance);
    stopsLayer.current = L.layerGroup().addTo(instance);
    locationLayer.current = L.layerGroup().addTo(instance);
    map.current = instance;
    const observer = new ResizeObserver(() => instance.invalidateSize());
    observer.observe(container.current);
    return () => {
      observer.disconnect();
      for (const record of markers.current.values()) record.cancel?.();
      markers.current.clear();
      tile.current = null; fitted.current = ''; map.current = null;
      instance.remove();
    };
  }, []);
  useEffect(() => {
    if (!map.current) return;
    setTileError(false);
    if (showTiles) {
      tile.current = L.tileLayer(import.meta.env.VITE_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors', maxZoom: 19,
      }).on('tileerror', () => setTileError(true)).addTo(map.current);
    }
    return () => { if (tile.current) { tile.current.remove(); tile.current = null; } };
  }, [showTiles]);
  useEffect(() => {
    if (!map.current) return;
    vectors.current.clearLayers();
    const status = new Map(traffic.map(row => [row.segment_id, row.displayStatus]));
    const bounds = L.latLngBounds([]);
    for (const route of routes) {
      const base = L.geoJSON(route.geometry_geojson, { style: { color: '#fff', weight: 10, opacity: 0.95 }, interactive: false }).addTo(vectors.current);
      bounds.extend(base.getBounds());
      for (const segment of route.segments) {
        const state = status.get(segment.id) ?? 'unknown';
        L.geoJSON(segment.polyline, { style: { color: trafficColors[state], weight: 6, opacity: 1,
          dashArray: state === 'unknown' ? '7 7' : undefined }, interactive: false }).addTo(vectors.current);
      }
    }
    const key = routes.map(route => route.id).join(',');
    if (key && key !== fitted.current && bounds.isValid()) {
      map.current.fitBounds(bounds, { padding: [40, 48], maxZoom: 16, animate: false });
      fitted.current = key;
    }
  }, [routes, traffic]);
  useEffect(() => {
    if (!map.current) return;
    stopsLayer.current.clearLayers();
    const unique = new Map();
    for (const route of routes) for (const stop of route.stops) unique.set(stop.id, stop);
    for (const stop of unique.values()) {
      const node = document.createElement('span');
      node.className = `stop-marker ${stop.id === selectedStop?.id ? 'selected' : ''}`;
      node.textContent = 'S';
      const marker = L.marker([stop.lat, stop.lng], { icon: L.divIcon({ className: 'map-marker', html: node, iconSize: [44, 44], iconAnchor: [22, 22] }),
        title: `Stop: ${stop.name}`, alt: `Stop: ${stop.name}`, keyboard: true, zIndexOffset: 20 }).addTo(stopsLayer.current);
      marker.on('click', () => callbacks.current.onStop(stop.id));
      marker.getElement()?.setAttribute('aria-label', `Stop: ${stop.name}`);
    }
  }, [routes, selectedStop?.id]);
  useEffect(() => {
    if (!map.current) return;
    const visible = new Set(vehicles.map(v => v.id));
    for (const [id, record] of markers.current) if (!visible.has(id)) {
      record.cancel?.(); record.marker.remove(); markers.current.delete(id);
    }
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    for (const vehicle of vehicles) {
      let record = markers.current.get(vehicle.id);
      const node = document.createElement('span');
      node.className = `vehicle-marker ${vehicle.id === selectedVehicleId ? 'selected' : ''} load-${vehicle.occupancy}`;
      node.innerHTML = '<span class="vehicle-drawing" aria-hidden="true"><i></i><i></i></span><b></b>';
      node.querySelector('b').textContent = vehicle.occupancy === 'full' ? 'F' : vehicle.occupancy === 'seats' ? 'S' : vehicle.occupancy === 'empty' ? 'E' : '?';
      const icon = L.divIcon({ className: 'map-marker vehicle-map-marker', html: node, iconSize: [44, 44], iconAnchor: [22, 22] });
      if (!record) {
        const marker = L.marker([vehicle.lat, vehicle.lng], { icon, keyboard: true, zIndexOffset: 200, title: `Vehicle: ${vehicle.label}` }).addTo(map.current);
        marker.on('click', () => callbacks.current.onVehicle(vehicle.id));
        record = { marker, recorded: null }; markers.current.set(vehicle.id, record);
      }
      const styleKey = `${vehicle.occupancy}|${vehicle.id === selectedVehicleId}`;
      if (record.styleKey !== styleKey) { record.marker.setIcon(icon); record.styleKey = styleKey; }
      record.marker.getElement()?.setAttribute('aria-label', `Vehicle: ${vehicle.label}, ${occupancyLabels[vehicle.occupancy] ?? occupancyLabels.unknown}`);
      record.marker.getElement()?.setAttribute('data-vehicle-id', String(vehicle.id));
      if (record.recorded !== vehicle.recorded_at) {
        record.cancel?.();
        record.cancel = moveMarker(record.marker, [vehicle.lat, vehicle.lng], animationMs, reduced);
        record.recorded = vehicle.recorded_at;
      }
    }
  }, [vehicles, selectedVehicleId, animationMs]);
  useEffect(() => {
    locationLayer.current?.clearLayers();
    if (!position || !map.current) return;
    L.circleMarker([position.lat, position.lng], { radius: 7, color: '#fff', weight: 3, fillColor: '#1768c4', fillOpacity: 1 }).addTo(locationLayer.current);
    const target = selectedStop ? [selectedStop.lat, selectedStop.lng] : [position.lat, position.lng];
    map.current.fitBounds(L.latLngBounds([[position.lat, position.lng], target]), { maxZoom: 16, padding: [45, 45], animate: false });
  }, [position, selectedStop?.id]);
  return <div className="live-map-wrap"><div ref={container} className="live-map" role="region" aria-label="Live vehicles, stops and traffic map" />
    {tileError && <p className="tile-notice" role="status">Some basemap tiles could not load. Route lines and stops still work.</p>}
  </div>;
}
