// Builder v2 at 300 elements (M8-T05, BUILD_PROMPT §11 "a 300-element form stays responsive",
// ADR 0037 targets: operation < 50 ms p95, preview < 300 ms, sync p95 ≤ 500 ms, inspector < 400 ms).
//
//   node scripts/perf/builder-v2.mjs [--runs=30]
//
// Needs `php scripts/perf/fixtures.php` (the published "Perf 300" form: 10 pages, 30 sections, 280 fields).
// Opens the canvas builder as the owner in desktop Chromium and measures
// - load: navigation start → data-builder-ready, LCP;
// - operations: keyboard moves while an element is grabbed (transient), pick up + drop (commit), undo
//   and redo — each from the keydown to the next frame after it (handler + render + paint);
// - event timing: the same operations with real key presses, from the Event Timing API (≥ 16 ms entries);
// - preview: a keyboard move until the sandboxed preview shows the new order (fed at most every 250 ms);
// - sync: the autosave round trips (Livewire `sync`, server lint included);
// - inspector: Enter on a card until the inspector shows that node.
// The draft ends unchanged: every move is undone or moved back before the script ends.
import { adminContext, fixtures, launch, stats, writeResult } from './lib.mjs';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')).map(([k, v]) => [k, v ?? 'true']),
);
const RUNS = Number(args.runs ?? 30);
const f = await fixtures();
const url = `/admin/${f.organization.slug}/forms/${f.perf300.id}/canvas`;
const isLivewire = (r) => r.method() === 'POST' && /\/livewire(-[^/]+)?\/update/.test(r.url());

const browser = await launch();
const context = await adminContext(browser, 'owner@demo.test', { viewport: { width: 1600, height: 1000 } });
await context.addInitScript(() => {
    window.__perf = { ready: null, lcp: 0, events: [] };
    new MutationObserver((_, observer) => {
        if (document.querySelector('[data-builder-root][data-builder-ready]')) {
            window.__perf.ready = performance.now();
            observer.disconnect();
        }
    }).observe(document, { subtree: true, attributes: true, childList: true });
    new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) window.__perf.lcp = entry.startTime;
    }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((list) => {
        for (const e of list.getEntries()) window.__perf.events.push({ name: e.name, duration: e.duration, id: e.interactionId });
    }).observe({ type: 'event', durationThreshold: 16, buffered: true });
});
const page = await context.newPage();

// --- load -------------------------------------------------------------------------------------
const loads = [];
for (let i = 0; i < 2 + 10; i++) {
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector('[data-builder-root][data-builder-ready]', { timeout: 30_000 });
    await page.waitForTimeout(300);
    const m = await page.evaluate(() => {
        const n = performance.getEntriesByType('navigation')[0];
        return { doc: n.responseEnd - n.requestStart, ready: window.__perf.ready, lcp: window.__perf.lcp, html: n.decodedBodySize };
    });
    if (i >= 2) loads.push(m);
}
const nodes = await page.locator('[data-builder-node]').count();
console.log(`load: ${nodes} element cards; ready p50 ${stats(loads.map((l) => l.ready)).p50} ms`);

// --- operations (synthetic keydown → next frame) ------------------------------------------------
const ops = await page.evaluate(async (runs) => {
    const nextFrame = () => new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)));
    const key = (target, k, extra = {}) =>
        target.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...extra }));
    const timed = async (fn) => {
        const t0 = performance.now();
        fn();
        await nextFrame();
        return performance.now() - t0;
    };
    const handles = [...document.querySelectorAll('[data-builder-page] [data-builder-node] [data-builder-handle]')];
    const result = { move: [], drop: [], undo: [], redo: [] };
    // Transient moves: grab an element in the middle and move it down and back up.
    const handle = handles[Math.floor(handles.length / 2)];
    handle.focus();
    key(handle, ' ');
    await nextFrame();
    for (let i = 0; i < runs + 3; i++) {
        const t = await timed(() => key(document.activeElement, i % 2 === 0 ? 'ArrowDown' : 'ArrowUp'));
        if (i >= 3) result.move.push(t);
    }
    key(document.activeElement, 'Escape');
    await nextFrame();
    // Committed moves: pick up, one step down, drop; undo and redo each.
    for (let i = 0; i < runs + 3; i++) {
        const h = document.querySelectorAll('[data-builder-page] [data-builder-node] [data-builder-handle]')[(i * 7 + 11) % handles.length];
        h.focus();
        key(h, ' ');
        await nextFrame();
        key(document.activeElement, 'ArrowDown');
        await nextFrame();
        const drop = await timed(() => key(document.activeElement, ' '));
        const undo = await timed(() => key(document.body, 'z', { ctrlKey: true }));
        await new Promise((r) => setTimeout(r, 20));
        const redo = await timed(() => key(document.body, 'z', { ctrlKey: true, shiftKey: true }));
        await new Promise((r) => setTimeout(r, 20));
        key(document.body, 'z', { ctrlKey: true }); // leave the draft as it was
        await new Promise((r) => setTimeout(r, 20));
        if (i >= 3) {
            result.drop.push(drop);
            result.undo.push(undo);
            result.redo.push(redo);
        }
    }
    return result;
}, RUNS);
for (const [name, values] of Object.entries(ops)) console.log(`${name.padEnd(8)} ${JSON.stringify(stats(values))}`);

// --- event timing with real key presses ----------------------------------------------------------
await page.evaluate(() => (window.__perf.events = []));
const middle = page.locator('[data-builder-page] [data-builder-node] [data-builder-handle]').nth(140);
await middle.focus();
await page.keyboard.press('Space');
for (let i = 0; i < RUNS; i++) {
    await page.keyboard.press(i % 2 === 0 ? 'ArrowDown' : 'ArrowUp');
    await page.waitForTimeout(30);
}
await page.keyboard.press('Escape');
await page.waitForTimeout(300);
const eventEntries = await page.evaluate(() => window.__perf.events.filter((e) => e.name === 'keydown'));
const eventTiming = {
    pressed: RUNS + 2,
    reportedAtLeast16ms: eventEntries.length,
    max: Math.max(0, ...eventEntries.map((e) => e.duration)),
    over50ms: eventEntries.filter((e) => e.duration > 50).length,
};
console.log(`event timing: ${JSON.stringify(eventTiming)}`);

// --- preview: a keyboard move of the first section of page 1 until the sandboxed preview shows it ---
const firstSectionText = async () =>
    (await page.frames().find((fr) => fr.url().includes('/form-preview'))?.evaluate(() => document.body.innerText)) ?? '';
const previewTimes = [];
const stablePreview = async () => {
    let previous = await firstSectionText();
    for (;;) {
        await page.waitForTimeout(400);
        const current = await firstSectionText();
        if (current === previous) return current;
        previous = current;
    }
};
for (let i = 0; i < 2 * Math.ceil((Math.min(RUNS, 20) + 2) / 2); i++) {
    const before = await stablePreview();
    const firstHandle = page.locator('[data-builder-page]').first().locator('[data-builder-node] [data-builder-handle]').first();
    await firstHandle.focus();
    await page.keyboard.press('Space');
    const t0 = Date.now();
    await page.keyboard.press('ArrowDown'); // the canvas and the preview show the move at once (transient)
    const frame = page.frames().find((fr) => fr.url().includes('/form-preview'));
    await frame.waitForFunction((prev) => document.body.innerText !== prev, before, { polling: 10, timeout: 5000 });
    const t = Date.now() - t0;
    if (i >= 2) previewTimes.push(t);
    await page.keyboard.press('Space'); // drop; the next round moves the other section back up front
}
console.log(`preview: ${JSON.stringify(stats(previewTimes))}`);

// --- sync round trips -----------------------------------------------------------------------------
const syncTimes = [];
const onFinished = (r) => {
    if (isLivewire(r) && (r.postData() ?? '').includes('"method":"sync"')) syncTimes.push(r.timing().responseEnd);
};
page.on('requestfinished', onFinished);
let syncMisses = 0;
for (let i = 0; i < RUNS + syncMisses && syncMisses < RUNS; i++) {
    const done = page.waitForEvent('requestfinished', { predicate: (r) => isLivewire(r) && (r.postData() ?? '').includes('"method":"sync"'), timeout: 8_000 });
    const h = page.locator('[data-builder-page] [data-builder-node] [data-builder-handle]').nth((i * 13 + 5) % 200);
    await h.focus();
    await page.keyboard.press('Space');
    await page.keyboard.press(i % 2 === 0 ? 'ArrowDown' : 'ArrowUp');
    await page.keyboard.press('Space');
    // A move at the edge of the canvas is refused (no change, no save): try the next card.
    await done.catch(() => syncMisses++);
}
page.off('requestfinished', onFinished);
// Undo the net effect of the sync loop (alternating moves leave pairs; undo all to be safe), then save.
for (let i = 0; i < RUNS; i++) await page.keyboard.press('Control+z');
console.log(`sync: ${JSON.stringify(stats(syncTimes))}`);

// --- inspector open -------------------------------------------------------------------------------
const inspectorTimes = [];
for (let i = 0; i < Math.min(RUNS, 20) + 2; i++) {
    const card = page.locator('[data-builder-page] [data-builder-node]').nth((i * 17 + 3) % 250);
    const id = await card.getAttribute('data-builder-node');
    await card.focus();
    const t0 = Date.now();
    await page.keyboard.press('Enter');
    await page.waitForSelector(`[data-builder-inspector][data-builder-inspected="${id}"]`, { timeout: 10_000 });
    if (i >= 2) inspectorTimes.push(Date.now() - t0);
    await page.waitForTimeout(150);
}
console.log(`inspector: ${JSON.stringify(stats(inspectorTimes))}`);

await page.waitForSelector('[data-builder-root][data-builder-sync-state="saved"], [data-builder-root][data-builder-sync-state="idle"]', { timeout: 20_000 }).catch(() => undefined);
await browser.close();

const result = {
    form: f.perf300,
    elementCards: nodes,
    load: { doc: stats(loads.map((l) => l.doc)), ready: stats(loads.map((l) => l.ready)), lcp: stats(loads.map((l) => l.lcp)), htmlKb: Math.round(loads[0].html / 1024) },
    operations: Object.fromEntries(Object.entries(ops).map(([k, v]) => [k, stats(v)])),
    eventTiming,
    preview: stats(previewTimes),
    sync: { ...stats(syncTimes), refusedMoves: syncMisses },
    inspector: stats(inspectorTimes),
};
console.log(JSON.stringify(result, null, 2));
console.log(`written ${writeResult('builder-v2', result)}`);
