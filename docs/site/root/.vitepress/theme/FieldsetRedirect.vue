<script setup>
// /fieldset/latest/ and the 404 page (plan U4, R8): sends the visitor to the page lib/root-redirect.mjs picks, or
// explains why there is none, as text with plain links. Before mount (server render, hydration) the model uses the
// page's own path and the baked versions.json, so /fieldset/latest/ works without JavaScript as a plain link; after
// mount it uses the browser's location and the live versions.json. Every link leaves this build's router (_self).
import { useData, useRoute } from 'vitepress';
import { computed, inject, onMounted, shallowRef, watch } from 'vue';
import { pagePathname, redirectModel } from '../../../lib/root-redirect.mjs';
import { VERSIONS_KEY } from '../../../lib/theme-versions.mjs';
import { readVersions } from '../../../lib/versions-reader.mjs';

const { page, theme } = useData();
const route = useRoute();
const loadVersions = inject(VERSIONS_KEY);
const read = shallowRef(readVersions(null, theme.value.fieldset.versions));
const where = shallowRef({ pathname: pagePathname(page.value.relativePath), hash: '' });
const model = computed(() => redirectModel(where.value, read.value));

async function follow() {
  where.value = { pathname: window.location.pathname, hash: window.location.hash };
  read.value = await loadVersions();
  if (model.value.kind === 'redirect') window.location.replace(model.value.url);
}
onMounted(follow);
watch(() => route.path, follow);
</script>

<template>
  <div class="FieldsetRedirect vp-doc">
    <template v-if="model.kind === 'redirect'">
      <h1>Opening the Fieldset {{ model.minor }} documentation</h1>
      <p><a :href="model.url" target="_self">Continue to the Fieldset {{ model.minor }} documentation</a></p>
    </template>
    <template v-else-if="model.kind === 'never-published'">
      <h1>No documentation for Fieldset {{ model.version }}</h1>
      <p>This site has the documentation of Fieldset 2.1 and later. Fieldset {{ model.version }} was never published here.</p>
      <ul>
        <li v-if="model.latestUrl"><a :href="model.latestUrl" target="_self">Read the latest documentation</a></li>
        <li v-if="model.archiveUrl"><a :href="model.archiveUrl" target="_self">Read the guides for 1.0 installations from a checkout</a></li>
        <li><a :href="model.homeUrl" target="_self">Go to the Fieldset home page</a></li>
      </ul>
    </template>
    <template v-else-if="model.kind === 'no-release'">
      <h1>No documentation published yet</h1>
      <p>No Fieldset release has published its documentation on this site yet.</p>
      <ul>
        <li><a :href="model.homeUrl" target="_self">Go to the Fieldset home page</a></li>
      </ul>
    </template>
    <template v-else>
      <h1>Page not found</h1>
      <p>This page of the Fieldset documentation does not exist.</p>
      <ul>
        <li><a :href="model.latestUrl" target="_self">Read the latest documentation</a></li>
        <li><a :href="model.homeUrl" target="_self">Go to the Fieldset home page</a></li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.FieldsetRedirect {
  max-width: 48rem;
  margin: 0 auto;
  padding: 4rem 1.5rem 6rem;
}
</style>
