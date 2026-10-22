// The root's theme (plan U4): the default theme plus the home page's start block (actions and install command) under
// the hero, its screenshots and documentation links under the features, and the redirect component, which renders
// /fieldset/latest/ and 404.md (front matter `layout: FieldsetRedirect`) and every not-found route. One versions.json
// loader per page load, with the versions.json the root was built with as its fallback (lib/theme-versions.mjs).
import DefaultTheme from 'vitepress/theme';
import { h } from 'vue';
import { createLoader, VERSIONS_KEY } from '../../../lib/theme-versions.mjs';
import FieldsetRedirect from './FieldsetRedirect.vue';
import HomeMore from './HomeMore.vue';
import HomeStart from './HomeStart.vue';

export default {
  extends: DefaultTheme,
  Layout: () => h(DefaultTheme.Layout, null, {
    'home-hero-actions-after': () => h(HomeStart),
    'home-features-after': () => h(HomeMore),
    'not-found': () => h(FieldsetRedirect),
  }),
  enhanceApp({ app, siteData }) {
    app.component('FieldsetRedirect', FieldsetRedirect);
    app.provide(VERSIONS_KEY, createLoader(siteData.value.themeConfig.fieldset.versions));
  },
};
