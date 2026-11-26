import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import App from './App.jsx';

const healthy = () => Promise.resolve({ ok: true, json: async () => ({ status: 'ok', service: 'CommuteLive API' }) });

describe('API connection', () => {
  it('shows loading until the actual request succeeds', async () => {
    let resolve;
    const fetch = vi.fn(() => new Promise(done => { resolve = done; }));
    vi.stubGlobal('fetch', fetch);
    render(<App />);
    expect(screen.getByRole('status')).toHaveTextContent('Checking API');
    expect(screen.getByRole('button')).toBeDisabled();
    resolve(await healthy());
    expect(await screen.findByText('API OK')).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledWith('/api/health', expect.objectContaining({ headers: { Accept: 'application/json' } }));
  });

  it.each([
    ['network failure', () => Promise.reject(new Error('offline'))],
    ['HTTP error', () => Promise.resolve({ ok: false })],
    ['invalid JSON', () => Promise.resolve({ ok: true, json: async () => { throw new Error('invalid'); } })],
    ['wrong contract', () => Promise.resolve({ ok: true, json: async () => ({ status: 'ok' }) })],
  ])('shows a recoverable error for %s', async (_, failure) => {
    const fetch = vi.fn().mockImplementationOnce(failure).mockImplementation(healthy);
    vi.stubGlobal('fetch', fetch);
    render(<App />);
    expect(await screen.findByText('API unavailable')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Check again' }));
    expect(await screen.findByText('API OK')).toBeInTheDocument();
  });

  it('aborts its pending request when unmounted', () => {
    const fetch = vi.fn(() => new Promise(() => {}));
    vi.stubGlobal('fetch', fetch);
    const { unmount } = render(<App />);
    unmount();
    expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
  });

  it('reports a request timeout', async () => {
    vi.useFakeTimers();
    vi.stubGlobal('fetch', vi.fn((_, { signal }) => new Promise((_, reject) => {
      signal.addEventListener('abort', () => reject(new Error('timeout')));
    })));
    render(<App />);
    await vi.advanceTimersByTimeAsync(8000);
    vi.useRealTimers();
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('API unavailable'));
  });
});
