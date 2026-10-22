<script setup>
// Under the home page's features (plan U4, R11, R12): the screenshots and the links into the newest release's
// documentation, both from the page's front matter, resolved and checked by lib/root-home.mjs. No screenshot section
// renders while the list is empty (U9 adds the images). Every text renders as text.
import { useData } from 'vitepress';
import { computed } from 'vue';
import { homeDocs, homeScreenshots } from '../../../lib/root-home.mjs';

const { frontmatter, theme } = useData();
const screenshots = computed(() => homeScreenshots(frontmatter.value.screenshots));
const docs = computed(() => homeDocs(frontmatter.value.docs, theme.value.fieldset.home.urls));
</script>

<template>
  <div class="FieldsetHomeMore">
    <div class="container">
      <section v-if="screenshots.length" class="section" aria-labelledby="fieldset-screenshots">
        <h2 id="fieldset-screenshots" class="heading">Screenshots</h2>
        <div class="screenshots">
          <figure v-for="shot in screenshots" :key="shot.url" class="screenshot">
            <img :src="shot.url" :alt="shot.alt" loading="lazy">
            <figcaption v-if="shot.caption">{{ shot.caption }}</figcaption>
          </figure>
        </div>
      </section>
      <section v-if="docs.length" class="section" aria-labelledby="fieldset-docs">
        <h2 id="fieldset-docs" class="heading">Documentation</h2>
        <ul class="docs">
          <li v-for="entry in docs" :key="entry.url">
            <a :href="entry.url" target="_self" class="doc">
              <span class="doc-title">{{ entry.title }}</span>
              <span class="doc-details">{{ entry.details }}</span>
            </a>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<style scoped>
/* the same frame as the default theme's features grid above */
.FieldsetHomeMore {
  padding: 0 1.5rem;
}

.container {
  max-width: 1152px;
  margin: 0 auto;
}

@media (min-width: 40rem) {
  .FieldsetHomeMore {
    padding: 0 3rem;
  }
}

@media (min-width: 60rem) {
  .FieldsetHomeMore {
    padding: 0 4rem;
  }
}

.section {
  margin-top: 3rem;
}

.heading {
  margin-bottom: 1rem;
  font-size: 1.25rem;
  font-weight: 600;
}

.screenshots {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 1.5rem;
}

.screenshot {
  margin: 0;
}

.screenshot img {
  width: 100%;
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
}

.screenshot figcaption {
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--vp-c-text-2);
}

.docs {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
  gap: 1rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.doc {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  height: 100%;
  padding: 1rem 1.25rem;
  border: 1px solid var(--vp-c-bg-soft);
  border-radius: 12px;
  background-color: var(--vp-c-bg-soft);
  transition: border-color 0.25s;
}

.doc:hover {
  border-color: var(--vp-c-brand-1);
}

.doc-title {
  font-weight: 600;
  color: var(--vp-c-text-1);
}

.doc-details {
  font-size: 0.875rem;
  color: var(--vp-c-text-2);
}
</style>
