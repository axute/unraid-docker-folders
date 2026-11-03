<template>
  <!-- The service's own summary, from its gethomepage labels
       (homepage.widget.*). The server reads the labels and calls the
       service; see stores/serviceWidgets.ts. -->
  <div
    v-if="widget?.fields?.length"
    class="min-w-0 text-[11px] truncate"
    :title="fieldsTitle"
    data-testid="service-widget"
  >
    <template v-for="(field, i) in widget.fields" :key="field.key">
      <span v-if="i > 0" class="text-text-secondary mx-1" aria-hidden="true">·</span>
      <span class="text-text-secondary">{{ field.label }}</span>
      <span class="text-text font-mono ml-1">{{ formatWidgetValue(field.value, field.format) }}</span>
    </template>
  </div>
  <div
    v-else-if="widget?.error"
    class="min-w-0 text-[11px] text-warning truncate"
    :title="errorTitle"
    data-testid="service-widget-error"
  >
    {{ typeLabel }}: {{ widget.message }}
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, watch } from 'vue';
import type { Container } from '@/stores/docker';
import { useServiceWidgetsStore } from '@/stores/serviceWidgets';
import { useSettingsStore } from '@/stores/settings';
import { SERVICE_WIDGET_TYPE_LABEL } from '@/types/serviceWidget';
import { formatWidgetValue } from '@/utils/format';

const props = defineProps<{ container: Container }>();

const store = useServiceWidgetsStore();
const settingsStore = useSettingsStore();

/** Asked for only while it can answer: labelled, running, and switched on. */
const wanted = computed(
  () =>
    settingsStore.showServiceWidgets &&
    props.container.state === 'running' &&
    !!props.container.labels?.[SERVICE_WIDGET_TYPE_LABEL],
);

const widget = computed(() => (wanted.value ? store.getWidget(props.container.id) : null));

// The id changes when a container is recreated, so the old one is let go.
watch(
  [wanted, () => props.container.id],
  ([isWanted, id], previous) => {
    const prevId = previous?.[1];
    if (prevId && prevId !== id) store.unregister(prevId);
    if (isWanted) store.register(id);
    else store.unregister(id);
  },
  { immediate: true },
);

onUnmounted(() => store.unregister(props.container.id));

const typeLabel = computed(() => props.container.labels?.[SERVICE_WIDGET_TYPE_LABEL] ?? 'Widget');

const fieldsTitle = computed(() =>
  (widget.value?.fields ?? []).map((f) => `${f.label}: ${formatWidgetValue(f.value, f.format)}`).join('\n'),
);

const errorTitle = computed(() => {
  const w = widget.value;
  if (!w) return '';
  return w.target ? `${w.message}\nAddress: ${w.target}` : (w.message ?? '');
});
</script>
