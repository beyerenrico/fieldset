// The version copy's theme (plan U3): the default theme plus the version switcher at the end of the nav bar and the
// version notices in the layout-top slot. Both read the descriptor baked into themeConfig.fieldset and share one
// versions.json loader per page load; their logic lives in lib/theme-versions.mjs.
import DefaultTheme from 'vitepress/theme';
import { h } from 'vue';
import { createVersionsLoader, VERSIONS_KEY } from '../../../lib/theme-versions.mjs';
import VersionNotice from './VersionNotice.vue';
import VersionSwitcher from './VersionSwitcher.vue';

export default {
  extends: DefaultTheme,
  Layout: () => h(DefaultTheme.Layout, null, {
    'layout-top': () => h(VersionNotice),
    'nav-bar-content-after': () => h(VersionSwitcher),
  }),
  enhanceApp({ app, siteData }) {
    app.provide(VERSIONS_KEY, createVersionsLoader(siteData.value.themeConfig.fieldset.copy));
  },
};
