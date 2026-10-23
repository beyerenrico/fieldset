<script setup>
// The version notices above every page (plan U3, R7, R20), from lib/theme-versions.mjs and rendered as text only. The
// pre-release notice with this copy's install command (and a working-tree build's development notice) depend only on
// the build and render server-side; the newer-version notice appears once versions.json is read. The bar is fixed at
// the top and publishes its measured height as --vp-layout-top-height, which the default theme adds above the fixed
// nav, the sidebar and the content; the non-scoped rule below covers the first paint before the measurement.
import { useData } from 'vitepress';
import { computed, inject, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { notices, VERSIONS_KEY } from '../../../lib/theme-versions.mjs';

const HEIGHT_VAR = '--vp-layout-top-height';
const { theme } = useData();
const loadVersions = inject(VERSIONS_KEY);
const read = shallowRef(null);
const bar = ref(null);
const items = computed(() => notices(theme.value.fieldset.copy, read.value));
let observer = null;

function publishHeight() {
  document.documentElement.style.setProperty(HEIGHT_VAR, `${bar.value?.offsetHeight ?? 0}px`);
}
onMounted(async () => {
  observer = new ResizeObserver(publishHeight);
  observer.observe(bar.value);
  publishHeight();
  read.value = await loadVersions();
});
onBeforeUnmount(() => {
  observer?.disconnect();
  document.documentElement.style.removeProperty(HEIGHT_VAR);
});
</script>

<template>
  <aside ref="bar" class="FieldsetVersionNotice" aria-label="Version notice">
    <!-- one line per sentence: the template compiler drops whitespace that spans a line break between elements -->
    <p v-for="notice in items" :key="notice.kind" class="notice" :class="notice.kind">
      <template v-if="notice.kind === 'prerelease'">
        <strong>Pre-release:</strong> Fieldset {{ notice.version }} is a release candidate. Install it with:
        <code class="command">{{ notice.command }}</code>
      </template>
      <template v-else-if="notice.kind === 'newer'">
        <strong>Fieldset {{ notice.version }} is available.</strong> <a :href="notice.url" target="_self">Read the {{ notice.minor }} documentation</a>
      </template>
      <template v-else>
        <strong>Development build</strong> of commit {{ notice.ref }}, not a published version. <a :href="notice.url" target="_self">Read the published documentation</a>
      </template>
    </p>
  </aside>
</template>

<style>
:root:has(.FieldsetVersionNotice > .notice) {
  --vp-layout-top-height: 4.5rem;
}

@media (max-width: 47.99rem) {
  :root:has(.FieldsetVersionNotice > .notice) {
    --vp-layout-top-height: 5.5rem;
  }
}
</style>

<style scoped>
.FieldsetVersionNotice {
  position: fixed;
  top: 0;
  right: 0;
  left: 0;
  z-index: var(--vp-z-index-layout-top);
}

.notice {
  margin: 0;
  padding: 0.5rem 1.5rem;
  border-bottom: 1px solid var(--vp-c-divider);
  font-size: 0.875rem;
  line-height: 1.5rem;
  color: var(--vp-c-text-1);
  /* the soft colours are translucent: layer them over the page background so content never shows through */
  background: linear-gradient(var(--notice-tint), var(--notice-tint)), var(--vp-c-bg);
}

.prerelease,
.development {
  --notice-tint: var(--vp-c-warning-soft);
}

.newer {
  --notice-tint: var(--vp-c-tip-soft);
}

.notice a {
  font-weight: 500;
  color: var(--vp-c-brand-1);
  text-decoration: underline;
  text-underline-offset: 2px;
}

.command {
  display: block;
  overflow-x: auto;
  font-family: var(--vp-font-family-mono);
  font-size: 0.8125rem;
  white-space: nowrap;
  user-select: all;
}
</style>
