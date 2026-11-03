import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import ServiceWidgetLine from '../ServiceWidgetLine.vue';
import { useServiceWidgetsStore } from '@/stores/serviceWidgets';
import { useSettingsStore } from '@/stores/settings';
import { makeContainer } from '@/test/fixtures';
import type { Container } from '@/stores/docker';

vi.mock('@/utils/csrf', () => ({
  apiFetch: vi.fn(async () => ({ ok: true, json: async () => ({ widgets: {} }) })),
}));

const LABELS = { 'homepage.widget.type': 'speedtest' };

function mountLine(overrides: Partial<Container> = {}) {
  const container = makeContainer({ id: 'st1', state: 'running', labels: LABELS, ...overrides });
  return mount(ServiceWidgetLine, { props: { container } });
}

describe('ServiceWidgetLine', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('shows the fields, formatted', async () => {
    const store = useServiceWidgetsStore();
    const wrapper = mountLine();
    store.widgets = {
      st1: {
        type: 'speedtest',
        fields: [
          { key: 'download', label: 'Download', value: 250000000, format: 'bitrate' },
          { key: 'ping', label: 'Ping', value: 12.4, format: 'ms' },
        ],
      },
    };
    await flushPromises();
    const spans = wrapper.find('[data-testid="service-widget"]').findAll('span').map((s) => s.text());
    expect(spans).toEqual(['Download', '250 Mbit/s', '·', 'Ping', '12 ms']);
  });

  it('shows the error message with the address in the tooltip', async () => {
    const store = useServiceWidgetsStore();
    const wrapper = mountLine();
    store.widgets = {
      st1: { type: 'speedtest', error: 'unreachable', message: 'The service did not accept a connection', target: 'http://172.17.0.5:80' },
    };
    await flushPromises();
    const line = wrapper.find('[data-testid="service-widget-error"]');
    expect(line.text()).toBe('speedtest: The service did not accept a connection');
    expect(line.attributes('title')).toContain('http://172.17.0.5:80');
  });

  it('registers only a running container with the label, while the setting is on', async () => {
    const store = useServiceWidgetsStore();
    const register = vi.spyOn(store, 'register');
    mountLine({ id: 'off', state: 'exited' });
    mountLine({ id: 'plain', labels: {} });
    expect(register).not.toHaveBeenCalled();

    useSettingsStore().showServiceWidgets = false;
    mountLine({ id: 'disabled' });
    expect(register).not.toHaveBeenCalled();

    useSettingsStore().showServiceWidgets = true;
    mountLine({ id: 'on' });
    expect(register).toHaveBeenCalledWith('on');
  });

  it('lets go of the container when it stops or unmounts', async () => {
    const store = useServiceWidgetsStore();
    const unregister = vi.spyOn(store, 'unregister');
    const wrapper = mountLine();
    await wrapper.setProps({ container: makeContainer({ id: 'st1', state: 'exited', labels: LABELS }) });
    expect(unregister).toHaveBeenCalledWith('st1');
    unregister.mockClear();
    wrapper.unmount();
    expect(unregister).toHaveBeenCalledWith('st1');
  });
});
