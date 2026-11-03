import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useServiceWidgetsStore } from '../serviceWidgets';

vi.mock('@/utils/csrf', () => ({
  apiFetch: vi.fn(async () => ({ ok: true, json: async () => ({ widgets: {} }) })),
}));

import { apiFetch } from '@/utils/csrf';

const JELLYFIN = {
  type: 'jellyfin',
  fields: [{ key: 'movies', label: 'Movies', value: 812, format: 'number' }],
};

function answer(widgets: Record<string, unknown>) {
  vi.mocked(apiFetch).mockResolvedValue({ ok: true, json: async () => ({ widgets }) } as Response);
}

describe('service widgets store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers();
    vi.mocked(apiFetch).mockClear();
    answer({});
  });
  afterEach(() => {
    useServiceWidgetsStore().cleanup();
    vi.useRealTimers();
  });

  it('asks for every card registered in one render cycle in one request', async () => {
    const store = useServiceWidgetsStore();
    store.register('a');
    store.register('b');
    await vi.advanceTimersByTimeAsync(50);
    expect(apiFetch).toHaveBeenCalledTimes(1);
    expect(vi.mocked(apiFetch).mock.calls[0][0]).toBe('/plugins/unraid-docker-folders-modern/api/widgets.php?ids=a,b');
  });

  it('stores the answer by id, and polls every minute', async () => {
    answer({ a: JELLYFIN });
    const store = useServiceWidgetsStore();
    store.register('a');
    await vi.advanceTimersByTimeAsync(50);
    expect(store.getWidget('a')).toEqual(JELLYFIN);

    await vi.advanceTimersByTimeAsync(60000);
    expect(apiFetch).toHaveBeenCalledTimes(2);
  });

  it('drops a widget the server no longer reports', async () => {
    answer({ a: JELLYFIN });
    const store = useServiceWidgetsStore();
    store.register('a');
    await vi.advanceTimersByTimeAsync(50);
    answer({});
    await store.fetchWidgets();
    expect(store.getWidget('a')).toBeNull();
  });

  it('keeps the last values when a poll fails', async () => {
    answer({ a: JELLYFIN });
    const store = useServiceWidgetsStore();
    store.register('a');
    await vi.advanceTimersByTimeAsync(50);
    vi.spyOn(console, 'error').mockImplementation(() => {});
    vi.mocked(apiFetch).mockRejectedValue(new Error('network failure'));
    await store.fetchWidgets();
    expect(store.getWidget('a')).toEqual(JELLYFIN);
  });

  it('stops polling when the last card goes', async () => {
    const store = useServiceWidgetsStore();
    store.register('a');
    await vi.advanceTimersByTimeAsync(50);
    store.unregister('a');
    await vi.advanceTimersByTimeAsync(120000);
    expect(apiFetch).toHaveBeenCalledTimes(1);
  });
});
