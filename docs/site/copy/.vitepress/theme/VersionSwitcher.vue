<script setup>
// The version switcher (plan U3, R6): the version being read, then every copy versions.json lists once it is read,
// each linking its copy's index, and the home page. Those are other builds, so every link carries target="_self" and
// the client router leaves the click alone. The model comes from lib/theme-versions.mjs and renders as text only. The
// list fills after mount, so the server render and hydration show the current version alone.
import { useData } from 'vitepress';
import { computed, inject, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { switcherModel, VERSIONS_KEY } from '../../../lib/theme-versions.mjs';

const { theme } = useData();
const loadVersions = inject(VERSIONS_KEY);
const read = shallowRef(null);
const menu = ref(null);
const model = computed(() => switcherModel(theme.value.fieldset.copy, read.value));

function closeOnOutsideClick(event) {
  if (menu.value?.open && !menu.value.contains(event.target)) menu.value.open = false;
}
onMounted(async () => {
  document.addEventListener('click', closeOnOutsideClick);
  read.value = await loadVersions();
});
onBeforeUnmount(() => document.removeEventListener('click', closeOnOutsideClick));
</script>

<template>
  <details ref="menu" class="FieldsetVersionSwitcher">
    <summary :aria-label="`Documentation version ${model.label}`">
      <span>{{ model.label }}</span>
      <span v-if="model.prerelease" class="mark">pre-release</span>
    </summary>
    <ul class="items">
      <li v-for="entry in model.entries" :key="entry.minor">
        <a v-if="entry.url && !entry.current" :href="entry.url" target="_self">{{ entry.version }}</a>
        <span v-else class="current" aria-current="true">{{ entry.version }}</span>
        <span v-if="entry.prerelease" class="mark">pre-release</span>
        <span v-if="entry.latest" class="mark">latest</span>
      </li>
      <li class="home"><a :href="model.home" target="_self">Fieldset home</a></li>
    </ul>
  </details>
</template>

<style scoped>
.FieldsetVersionSwitcher {
  position: relative;
  margin-left: 0.75rem;
  font-size: 0.875rem;
}

summary {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  height: var(--vp-nav-height);
  padding: 0 0.25rem;
  font-weight: 500;
  color: var(--vp-c-text-1);
  white-space: nowrap;
  list-style: none;
  cursor: pointer;
}

summary::-webkit-details-marker {
  display: none;
}

summary::after {
  content: '';
  margin: -0.25rem 0 0 0.125rem;
  padding: 0.15rem;
  border: solid currentColor;
  border-width: 0 1.5px 1.5px 0;
  transform: rotate(45deg);
}

.items {
  position: absolute;
  top: calc(var(--vp-nav-height) - 0.5rem);
  right: 0;
  z-index: 1;
  min-width: 13rem;
  margin: 0;
  padding: 0.5rem;
  list-style: none;
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  background-color: var(--vp-c-bg-elv);
  box-shadow: var(--vp-shadow-3);
}

.items li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0 0.75rem;
  line-height: 2rem;
  white-space: nowrap;
}

.items a {
  color: var(--vp-c-text-1);
}

.items a:hover {
  color: var(--vp-c-brand-1);
}

.current {
  font-weight: 600;
  color: var(--vp-c-brand-1);
}

.mark {
  padding: 0 0.375rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 8px;
  font-size: 0.75rem;
  line-height: 1.25rem;
  color: var(--vp-c-text-2);
}

.items .home {
  margin-top: 0.25rem;
  border-top: 1px solid var(--vp-c-divider);
  padding-top: 0.25rem;
}
</style>
