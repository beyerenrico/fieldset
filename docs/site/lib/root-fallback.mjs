// The root 404.html's static fallback (plan U4, risk "Redirects depend on JavaScript"). VitePress writes that page with
// an empty #app container, so a visitor without JavaScript, or curl, would see nothing: this adds plain links to
// /fieldset/latest/ and /fieldset/ outside #app, hidden by CSS once the app has rendered its own not-found page.
import { LATEST_URL, SITE_ROOT } from './versions-reader.mjs';

export const FALLBACK_CLASS = 'fieldset-static-fallback';
const APP = '<div id="app">';
const STYLE = `<style>.${FALLBACK_CLASS}{max-width:40rem;margin:4rem auto;padding:0 1.5rem;`
  + `font-family:system-ui,sans-serif;line-height:1.6}body:has(#app>*) .${FALLBACK_CLASS}{display:none}</style>`;
export const FALLBACK_HTML = `<div class="${FALLBACK_CLASS}">`
  + '<h1>Page not found</h1>'
  + '<p>This page of the Fieldset documentation does not exist.</p>'
  + `<p><a href="${LATEST_URL}">Read the latest documentation</a> or go to the `
  + `<a href="${SITE_ROOT}">Fieldset home page</a>.</p>`
  + '</div>';

// html: the page VitePress rendered. Throws when the page lacks the markers, so a VitePress change cannot drop the
// fallback silently.
export function withStaticFallback(html) {
  if (html.includes(FALLBACK_HTML)) return html;
  if (!html.includes(APP) || !html.includes('</head>')) throw new Error('404.html has no #app container or </head>');
  return html.replace('</head>', `${STYLE}\n  </head>`).replace(APP, `${FALLBACK_HTML}\n    ${APP}`);
}
