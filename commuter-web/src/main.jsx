import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles.css';
import RouteReview from './RouteReview.jsx';

createRoot(document.getElementById('root')).render(
  <React.StrictMode>{window.location.pathname === '/routes' ? <RouteReview /> : <App />}</React.StrictMode>,
);
