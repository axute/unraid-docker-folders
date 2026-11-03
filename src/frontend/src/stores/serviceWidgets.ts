/**
 * Service Widgets Store - the gethomepage-style summaries on container cards.
 *
 * A card registers its container while it is mounted, running, and has a
 * `homepage.widget.type` label. The store asks for every registered id in
 * one request, batched over a render cycle, and again every POLL_INTERVAL_MS
 * while the tab is visible. The server caches each answer for 30 seconds, so
 * several open tabs do not multiply the calls to the services.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useBackend } from '@/backends';
import type { ServiceWidget } from '@/types/serviceWidget';

const POLL_INTERVAL_MS = 60000;
const KICK_DELAY_MS = 50;

export const useServiceWidgetsStore = defineStore('serviceWidgets', () => {
  const registered = ref(new Set<string>());
  const widgets = ref<Record<string, ServiceWidget>>({});
  let pollTimer: ReturnType<typeof setInterval> | null = null;
  let kickTimer: ReturnType<typeof setTimeout> | null = null;
  let visibilityBound = false;

  async function fetchWidgets() {
    const ids = Array.from(registered.value);
    if (ids.length === 0) return;
    try {
      const { ok, error: failure, data } = await useBackend().serviceWidgets.get(ids);
      if (!ok) throw new Error(failure);
      const incoming = data.widgets || {};
      const next = { ...widgets.value };
      for (const id of ids) {
        if (incoming[id]) next[id] = incoming[id];
        else delete next[id];
      }
      widgets.value = next;
    } catch (e) {
      // Keep the last values: one failed poll should not blank every card.
      console.error('Error fetching service widgets:', e);
    }
  }

  function scheduleKick() {
    if (kickTimer) clearTimeout(kickTimer);
    kickTimer = setTimeout(() => {
      kickTimer = null;
      fetchWidgets();
    }, KICK_DELAY_MS);
  }

  function startPolling() {
    if (pollTimer || document.hidden) return;
    pollTimer = setInterval(fetchWidgets, POLL_INTERVAL_MS);
  }

  function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
  }

  function handleVisibilityChange() {
    if (document.hidden) {
      stopPolling();
    } else if (registered.value.size > 0) {
      scheduleKick();
      startPolling();
    }
  }

  function register(id: string) {
    if (registered.value.has(id)) return;
    if (!visibilityBound) {
      document.addEventListener('visibilitychange', handleVisibilityChange);
      visibilityBound = true;
    }
    registered.value = new Set(registered.value).add(id);
    scheduleKick();
    startPolling();
  }

  function unregister(id: string) {
    if (!registered.value.has(id)) return;
    const next = new Set(registered.value);
    next.delete(id);
    registered.value = next;
    if (next.size === 0) stopPolling();
  }

  function getWidget(id: string): ServiceWidget | null {
    return widgets.value[id] ?? null;
  }

  function cleanup() {
    stopPolling();
    if (kickTimer) clearTimeout(kickTimer);
    kickTimer = null;
    if (visibilityBound) {
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      visibilityBound = false;
    }
    registered.value = new Set();
    widgets.value = {};
  }

  return { widgets, register, unregister, getWidget, fetchWidgets, cleanup };
});
