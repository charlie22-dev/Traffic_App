import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles.css';
import './commuter.css';
import RouteReview from './RouteReview.jsx';
import HealthCheck from './HealthCheck.jsx';

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  import('virtual:pwa-register').then(({ registerSW }) => {
    const update = registerSW({ onNeedRefresh() {
      window.dispatchEvent(new CustomEvent('commutelive:update', { detail: { update: () => update(true) } }));
    } });
  });
}

createRoot(document.getElementById('root')).render(
  <React.StrictMode>{window.location.pathname === '/routes' ? <RouteReview /> : window.location.pathname === '/status' ? <HealthCheck /> : <App />}</React.StrictMode>,
);
