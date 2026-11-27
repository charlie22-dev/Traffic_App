import { useEffect, useState } from 'react';

const prefix = 'commutelive:static:';
export function readSaved(key, days = 7) {
  try {
    const row = JSON.parse(localStorage.getItem(prefix + key));
    if (row && Date.now() - row.savedAt <= days * 86400000) return row;
  } catch { /* Storage may be disabled or full. Live use still works. */ }
  return null;
}
function save(key, data) {
  try { localStorage.setItem(prefix + key, JSON.stringify({ data, savedAt: Date.now() })); } catch {}
}

export async function fetchJson(url, signal) {
  const response = await fetch(url, { signal, headers: { Accept: 'application/json' }, cache: 'no-store' });
  if (!response.ok) throw new Error(`Request failed (${response.status})`);
  return response.json();
}

export function useConnection() {
  const [online, setOnline] = useState(navigator.onLine !== false);
  useEffect(() => {
    const on = () => setOnline(true);
    const off = () => setOnline(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off); };
  }, []);
  return online;
}

// Serialized polls: abort and discard work on route/stop changes; pause in hidden tabs.
// Only explicitly named public static resources may be stored; live feeds never are.
export default function useResource(url, { interval = null, timeout = 8000, online = true, cacheKey, cacheDays = 7, loader } = {}) {
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState({ url, data: null, loading: !!url, error: '', updatedAt: null, cached: false });
  useEffect(() => {
    let active = true;
    let timer, controller, guard;
    let running = false;
    const cached = cacheKey ? readSaved(cacheKey, cacheDays) : null;
    setState(previous => previous.url === url ? { ...previous, loading: !!url && !previous.data }
      : { url, data: null, loading: !!url, error: '', updatedAt: null, cached: false });
    async function load() {
      if (!url || !active || running || document.hidden) return;
      clearTimeout(timer);
      if (!online) {
        setState(previous => ({ ...previous, url, data: previous.url === url ? (previous.data ?? cached?.data ?? null) : cached?.data ?? null,
          loading: false, error: 'offline', cached: !!cached || previous.cached, updatedAt: previous.updatedAt ?? cached?.savedAt ?? null }));
        return;
      }
      running = true;
      controller = new AbortController();
      guard = setTimeout(() => controller.abort(), timeout);
      try {
        const data = await (loader ? loader(controller.signal) : fetchJson(url, controller.signal));
        if (active) {
          if (cacheKey) save(cacheKey, data);
          setState({ url, data, loading: false, error: '', updatedAt: Date.now(), cached: false });
        }
      } catch {
        if (active) setState(previous => ({ ...previous, url, data: previous.data ?? cached?.data ?? null, loading: false,
          error: 'unavailable', cached: previous.cached || (!previous.data && !!cached), updatedAt: previous.updatedAt ?? cached?.savedAt ?? null }));
      } finally {
        clearTimeout(guard);
        running = false;
        if (active && interval && !document.hidden) timer = setTimeout(load, interval);
      }
    }
    const resume = () => { if (!document.hidden) load(); else { clearTimeout(timer); } };
    document.addEventListener('visibilitychange', resume);
    if (url) load();
    return () => {
      active = false;
      clearTimeout(timer); clearTimeout(guard); controller?.abort();
      document.removeEventListener('visibilitychange', resume);
    };
  }, [url, interval, timeout, online, cacheKey, cacheDays, loader, attempt]);
  const current = state.url === url ? state : { data: null, loading: !!url, error: '', updatedAt: null, cached: false };
  return { ...current, retry: () => setAttempt(value => value + 1) };
}

export function useClock() {
  const [now, setNow] = useState(Date.now());
  useEffect(() => { const timer = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(timer); }, []);
  return now;
}
