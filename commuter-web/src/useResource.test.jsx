import { act, renderHook, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import useResource from './useResource.js';

it('discards late responses after changing the stop and aborts the old request', async () => {
  let first;
  const fetch = vi.fn().mockImplementationOnce(() => new Promise(resolve => { first = resolve; }))
    .mockResolvedValue({ ok: true, json: async () => ({ data: [{ stop_id: 2 }] }) });
  vi.stubGlobal('fetch', fetch);
  const { result, rerender } = renderHook(({ id }) => useResource(`/api/stops/${id}/arrivals`), { initialProps: { id: 1 } });
  rerender({ id: 2 });
  await waitFor(() => expect(result.current.data?.data[0].stop_id).toBe(2));
  expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
  await act(async () => first({ ok: true, json: async () => ({ data: [{ stop_id: 1 }] }) }));
  expect(result.current.data.data[0].stop_id).toBe(2);
});

it('does not cache live requests and preserves data on a failed retry', async () => {
  localStorage.clear();
  const fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ data: [{ id: 9 }] }) }).mockRejectedValue(new Error('offline'));
  vi.stubGlobal('fetch', fetch);
  const { result } = renderHook(() => useResource('/api/vehicles'));
  await waitFor(() => expect(result.current.data?.data[0].id).toBe(9));
  act(() => result.current.retry());
  await waitFor(() => expect(result.current.error).toBe('unavailable'));
  expect(result.current.data.data[0].id).toBe(9);
  expect(localStorage.length).toBe(0);
});

it('restores only named static cached data while offline', async () => {
  localStorage.clear();
  localStorage.setItem('commutelive:static:routes', JSON.stringify({ savedAt: Date.now(), data: { data: [{ id: 3 }] } }));
  const fetch = vi.fn(); vi.stubGlobal('fetch', fetch);
  const { result } = renderHook(() => useResource('/api/routes', { online: false, cacheKey: 'routes' }));
  await waitFor(() => expect(result.current.data?.data[0].id).toBe(3));
  expect(result.current.cached).toBe(true);
  expect(fetch).not.toHaveBeenCalled();
  localStorage.clear();
});

it('a null resource finishes loading without a request', async () => {
  const fetch = vi.fn(); vi.stubGlobal('fetch', fetch);
  const { result } = renderHook(() => useResource(null));
  await waitFor(() => expect(result.current.loading).toBe(false));
  expect(fetch).not.toHaveBeenCalled();
});
