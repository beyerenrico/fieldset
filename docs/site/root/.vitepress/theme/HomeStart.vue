<script setup>
// Under the home page's hero (plan U4, R11): the actions of the page's front matter and the install command of the
// newest release, baked at build time (lib/root-home.mjs), with its pre-release label while no final exists. Links go
// to other builds or leave the router (_self); every text renders as text.
import { useData } from 'vitepress';
import { computed } from 'vue';
import { homeActions } from '../../../lib/root-home.mjs';

const { frontmatter, theme } = useData();
const home = computed(() => theme.value.fieldset.home);
const actions = computed(() => homeActions(frontmatter.value.actions, home.value.urls));
</script>

<template>
  <div class="FieldsetHomeStart">
    <div v-if="actions.length" class="actions">
      <a v-for="action in actions" :key="action.url" class="action" :class="action.theme" :href="action.url" target="_self">{{ action.text }}</a>
    </div>
    <section class="install" aria-labelledby="fieldset-install">
      <h2 id="fieldset-install" class="title">
        <template v-if="home.install">Install Fieldset {{ home.install.version }}</template>
        <template v-else>Install Fieldset</template>
        <span v-if="home.install?.prerelease" class="mark">pre-release</span>
      </h2>
      <template v-if="home.install">
        <p class="lead">On a server or your own computer with Docker:</p>
        <pre class="command"><code>{{ home.install.command }}</code></pre>
      </template>
      <p class="more"><a :href="home.urls.install" target="_self">The install guide</a> covers domains, proxies, phones and mail.</p>
    </section>
  </div>
</template>

<style scoped>
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  padding-top: 2rem;
}

.action {
  display: inline-block;
  padding: 0 1.25rem;
  border: 1px solid transparent;
  border-radius: 20px;
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 2.375rem;
  white-space: nowrap;
  transition: color 0.25s, border-color 0.25s, background-color 0.25s;
}

.action.brand {
  border-color: var(--vp-button-brand-border);
  color: var(--vp-button-brand-text);
  background-color: var(--vp-button-brand-bg);
}

.action.brand:hover {
  border-color: var(--vp-button-brand-hover-border);
  color: var(--vp-button-brand-hover-text);
  background-color: var(--vp-button-brand-hover-bg);
}

.action.alt {
  border-color: var(--vp-button-alt-border);
  color: var(--vp-button-alt-text);
  background-color: var(--vp-button-alt-bg);
}

.action.alt:hover {
  border-color: var(--vp-button-alt-hover-border);
  color: var(--vp-button-alt-hover-text);
  background-color: var(--vp-button-alt-hover-bg);
}

.install {
  max-width: 48rem;
  margin-top: 2rem;
}

.title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1rem;
  font-weight: 600;
}

.mark {
  padding: 0 0.375rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 8px;
  font-size: 0.75rem;
  font-weight: 500;
  line-height: 1.25rem;
  color: var(--vp-c-text-2);
}

.lead,
.more {
  margin: 0.5rem 0;
  font-size: 0.875rem;
  color: var(--vp-c-text-2);
}

.more a {
  font-weight: 500;
  color: var(--vp-c-brand-1);
  text-decoration: underline;
  text-underline-offset: 2px;
}

.command {
  overflow-x: auto;
  margin: 0;
  padding: 0.75rem 1rem;
  border-radius: 8px;
  font-family: var(--vp-font-family-mono);
  font-size: 0.8125rem;
  line-height: 1.5rem;
  background-color: var(--vp-code-block-bg);
  user-select: all;
}
</style>
