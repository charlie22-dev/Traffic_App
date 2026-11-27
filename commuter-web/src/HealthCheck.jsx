import { useEffect, useState } from 'react';

export default function App() {
  const [attempt, setAttempt] = useState(0);
  const [status, setStatus] = useState('loading');

  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    const timeout = setTimeout(() => controller.abort(), 8000);
    setStatus('loading');

    async function check() {
      try {
        const response = await fetch('/api/health', {
          signal: controller.signal,
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Health request failed');
        const data = await response.json();
        if (data.status !== 'ok' || data.service !== 'CommuteLive API') {
          throw new Error('Unexpected health response');
        }
        if (active) setStatus('ok');
      } catch {
        if (active) setStatus('error');
      } finally {
        clearTimeout(timeout);
      }
    }

    check();
    return () => { active = false; clearTimeout(timeout); controller.abort(); };
  }, [attempt]);

  return (
    <main>
      <header><span className="brand-mark" aria-hidden="true">C</span> CommuteLive</header>
      <section className="intro" aria-labelledby="title">
        <p className="eyebrow">A better everyday commute</p>
        <h1 id="title">Your next ride.<br />A little less guesswork.</h1>
        <p className="description">A free commute assistant for jeepney and bus riders in the Philippines. We’re starting small, with a local pilot.</p>
        <div className="connection">
          <p className="eyebrow">Project connection check</p>
          <h2 role="status" aria-live="polite">
            {status === 'loading' ? 'Checking API…' : status === 'ok' ? 'API OK' : 'API unavailable'}
          </h2>
          <p>{status === 'ok'
            ? 'The commuter app is connected to the Laravel backend.'
            : status === 'loading'
              ? 'Connecting to the local CommuteLive backend.'
              : 'Check that the backend is running, then try again.'}</p>
          <button disabled={status === 'loading'} onClick={() => setAttempt(value => value + 1)}>
            {status === 'loading' ? 'Checking…' : 'Check again'}
          </button>
        </div>
        <p><a href="/routes">Review pilot routes and segments</a></p>
        <p className="scope">The <a href="/">commuter map</a> shows recent vehicles, traffic and stop arrivals. This diagnostics page collects no location.</p>
      </section>
      <footer>Made for the daily sakay.</footer>
    </main>
  );
}
