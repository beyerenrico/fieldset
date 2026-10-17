// Field App budget and responsiveness (M8-T05, BUILD_PROMPT §11: "first load under 3 s on a mid-range
// Android phone over 4G; repeat loads instant from cache; a 300-element form or a 500-row repeater stays
// responsive").
//
//   node scripts/perf/field-app.mjs [--runs=5] [--only=load|fill]
//
// Needs `php scripts/perf/fixtures.php` (the "Perf 300" form granted to field workers) and a built
// Field App in the running image. Lighthouse-like, without Lighthouse: Chromium on a Pixel 7 viewport
// with Lighthouse's mobile throttling applied through CDP (562.5 ms RTT, 1.47 Mbit/s down, 675 kbit/s up,
// 4× CPU slowdown), cold cache per run. Reports FCP and LCP (Paint/LCP observers), the time until the
// screen is usable (login form, or the home greeting when signed in), long tasks (TBT-like) and bytes.
// Responsiveness: a server draft with a 500-row repeater (service report) and the 300-element form are
// filled with real key presses; the Event Timing API reports each interaction (keydown → next paint).
import { brotliCompressSync, gzipSync } from 'node:zlib';
import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { randomUUID } from 'node:crypto';
import { BASE, fieldAppContext, fixtures, launch, stats, waitForQuiet, writeResult } from './lib.mjs';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')).map(([k, v]) => [k, v ?? 'true']),
);
const RUNS = Number(args.runs ?? 5);
const ONLY = args.only ?? 'all'; // all | load | fill
const f = await fixtures();
const PHONE = {
    viewport: { width: 412, height: 915 },
    deviceScaleFactor: 2.625,
    isMobile: true,
    hasTouch: true,
    userAgent:
        'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
};
// Lighthouse mobile, "devtools" (applied) throttling: its simulated 150 ms / 1.6 Mbit/s profile with the
// RTT × 3.75 and throughput × 0.9 adjustments Lighthouse applies when throttling for real.
const LIGHTHOUSE_MOBILE = { name: 'Lighthouse mobile (devtools throttling)', latency: 562.5, downloadThroughput: (1474.56 * 1024) / 8, uploadThroughput: (675 * 1024) / 8, cpu: 4 };
// The same network values without the adjustment: Lighthouse's simulated "slow 4G" (150 ms, 1.6 Mbit/s).
const SLOW_4G = { name: 'slow 4G (150 ms RTT, 1.6 Mbit/s, 4x CPU)', latency: 150, downloadThroughput: (1638.4 * 1024) / 8, uploadThroughput: (750 * 1024) / 8, cpu: 4 };

const OBSERVERS = () => {
    window.__perf = { lcp: 0, fcp: 0, longTasks: [], events: [] };
    new PerformanceObserver((l) => l.getEntries().forEach((e) => (window.__perf.lcp = e.startTime))).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => l.getEntries().forEach((e) => e.name === 'first-contentful-paint' && (window.__perf.fcp = e.startTime))).observe({ type: 'paint', buffered: true });
    new PerformanceObserver((l) => l.getEntries().forEach((e) => window.__perf.longTasks.push({ start: e.startTime, duration: e.duration }))).observe({ type: 'longtask', buffered: true });
    new PerformanceObserver((l) =>
        l.getEntries().forEach((e) => window.__perf.events.push({ name: e.name, duration: e.duration, id: e.interactionId, start: e.startTime })),
    ).observe({ type: 'event', durationThreshold: 16, buffered: true });
};

async function throttle(page, profile) {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions', {
        offline: false,
        latency: profile.latency,
        downloadThroughput: profile.downloadThroughput,
        uploadThroughput: profile.uploadThroughput,
    });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: profile.cpu });
    return cdp;
}

async function loadMetrics(page, usable) {
    const started = Date.now();
    await page.goto('/app/', { waitUntil: 'commit' });
    await usable.waitFor({ timeout: 60_000 });
    const usableAt = await page.evaluate(() => performance.now());
    await page.waitForTimeout(1500);
    return page.evaluate(
        ({ usableAt, wall }) => {
            const nav = performance.getEntriesByType('navigation')[0];
            const resources = performance.getEntriesByType('resource');
            const tbt = window.__perf.longTasks.filter((t) => t.start < usableAt).reduce((sum, t) => sum + Math.max(0, t.duration - 50), 0);
            return {
                ttfb: nav.responseStart,
                fcp: window.__perf.fcp,
                lcp: window.__perf.lcp,
                dcl: nav.domContentLoadedEventEnd,
                load: nav.loadEventEnd,
                usable: usableAt,
                wall,
                tbt,
                transferKb: Math.round((nav.transferSize + resources.reduce((s, r) => s + (r.transferSize || 0), 0)) / 1024),
                requests: resources.length + 1,
            };
        },
        { usableAt, wall: Date.now() - started },
    );
}

function bundles() {
    const dir = resolve('public/app');
    const html = readFileSync(resolve(dir, 'index.html'), 'utf8');
    const initial = [...html.matchAll(/(?:src|href)="\/app\/(assets\/[^"]+\.(?:js|css))"/g)].map((m) => m[1]);
    const size = (file) => {
        const bytes = readFileSync(resolve(dir, file));
        return { file, raw: bytes.length, gzip: gzipSync(bytes, { level: 9 }).length, brotli: brotliCompressSync(bytes).length };
    };
    const all = readdirSync(resolve(dir, 'assets')).filter((f) => /\.(js|css)$/.test(f)).map((f) => size(`assets/${f}`));
    const sum = (list, key) => list.reduce((s, x) => s + x[key], 0);
    const first = all.filter((x) => initial.includes(x.file));
    const kb = (n) => Math.round(n / 102.4) / 10;
    return {
        initialFiles: first.map((x) => ({ file: x.file, rawKb: kb(x.raw), gzipKb: kb(x.gzip) })),
        initialGzipKb: kb(sum(first, 'gzip')),
        initialBrotliKb: kb(sum(first, 'brotli')),
        allGzipKb: kb(sum(all, 'gzip')),
        lazyFiles: all.filter((x) => !initial.includes(x.file)).map((x) => ({ file: x.file, rawKb: kb(x.raw), gzipKb: kb(x.gzip) })),
    };
}

/** Creates a server draft for the worker (PUT /api/v1/submissions/{uuid}) from inside the app page. */
async function serverDraft(page, versionId, data) {
    const uuid = randomUUID();
    const status = await page.evaluate(
        async ({ uuid, versionId, data, org }) => {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
            const response = await fetch(`/api/v1/submissions/${uuid}`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Organization-Id': String(org), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ form_version_id: versionId, data }),
            });
            return response.status;
        },
        { uuid, versionId, data, org: f.organization.id },
    );
    if (status >= 300) throw new Error(`draft PUT answered ${status}`);
    return uuid;
}

function interactions(events) {
    // One interaction = the longest entry per interactionId (keydown/keyup/input of one key press).
    const byId = new Map();
    for (const e of events.filter((e) => e.id > 0)) byId.set(e.id, Math.max(byId.get(e.id) ?? 0, e.duration));
    return [...byId.values()];
}

async function typeAndMeasure(page, input, keys) {
    await page.evaluate(() => (window.__perf.events = []));
    await input.click();
    for (const key of keys) {
        await page.keyboard.press(key);
        await page.waitForTimeout(120);
    }
    await page.waitForTimeout(500);
    const events = await page.evaluate(() => window.__perf.events);
    const durations = interactions(events);
    return { pressed: keys.length, reportedOver16ms: durations.length, ...stats(durations.length ? durations : [0]), max: Math.max(0, ...durations) };
}

const browser = await launch();
const result = { bundles: bundles(), profiles: { throttled: LIGHTHOUSE_MOBILE, slow4g: SLOW_4G } };
console.log(`bundles: initial ${result.bundles.initialGzipKb} kB gzip (${result.bundles.initialBrotliKb} kB brotli), all ${result.bundles.allGzipKb} kB gzip`);

// --- first load, signed out (login screen), Lighthouse mobile throttling, cold cache ------------------
result.load = {};
for (const [name, signedIn] of ONLY === 'fill' ? [] :  [['login screen (signed out)', false], ['home (signed in)', true]]) {
    result.load[name] = { throttled: [], slow4g: [], unthrottled: [] };
    const { context: signedCtx } = signedIn ? await fieldAppContext(browser, 'worker@demo.test', PHONE) : { context: null };
    const state = signedCtx ? await signedCtx.storageState() : undefined;
    await signedCtx?.close();
    for (const mode of ['throttled', 'slow4g', 'unthrottled']) {
        result.load[name].loadAvg = await waitForQuiet();
        for (let i = 0; i < RUNS; i++) {
            const context = await browser.newContext({ ...PHONE, baseURL: BASE, ...(state ? { storageState: state } : {}), serviceWorkers: 'allow' });
            await context.addInitScript(OBSERVERS);
            const page = await context.newPage();
            if (mode !== 'unthrottled') await throttle(page, mode === 'throttled' ? LIGHTHOUSE_MOBILE : SLOW_4G);
            const usable = signedIn ? page.getByText(/^(hello|hallo), /i) : page.getByLabel(/^(email|e-mail)$/i);
            const m = await loadMetrics(page, usable);
            result.load[name][mode].push(m);
            if (i === 0 && mode === 'throttled') {
                // Repeat load in the same context: HTTP cache and service worker are warm.
                const repeat = await loadMetrics(page, usable);
                result.load[name].repeatThrottled = repeat;
            }
            await context.close();
        }
        const s = (key) => stats(result.load[name][mode].map((m) => m[key]));
        console.log(`${name} ${mode}: usable p50 ${s('usable').p50} ms, LCP p50 ${s('lcp').p50}, FCP p50 ${s('fcp').p50}, TBT p50 ${s('tbt').p50}, ${result.load[name][mode][0].transferKb} kB`);
    }
}

// --- 500-row repeater and 300-element form, filled with real key presses ------------------------------
async function fillSession(cpu) {
    const { context, page } = await fieldAppContext(browser, 'worker@demo.test', PHONE);
    await context.addInitScript(OBSERVERS);
    if (cpu > 1) {
        const cdp = await context.newCDPSession(page);
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: cpu });
    }
    return { context, page };
}

result.fill = {};
for (const cpu of ONLY === 'load' ? [] : [1, 4]) {
    const label = cpu === 1 ? 'unthrottled' : '4x CPU';
    await waitForQuiet();
    const { context, page } = await fillSession(cpu);
    const items = Array.from({ length: 500 }, (_, i) => ({ description: `Position ${i + 1}`, qty: String((i % 8) + 1), unit: 'h', unit_price: '12.50' }));
    const uuid = await serverDraft(page, f.serviceReport.versionId, { customer_name: 'Perf GmbH', service_type: 'repair', service_date: '2026-10-17', work_done: 'Brenner eingestellt', defect: 'Druckverlust', items });
    const t0 = Date.now();
    await page.goto(`/app/fill/${uuid}?formId=${f.serviceReport.id}&versionId=${f.serviceReport.versionId}`);
    await page.getByLabel(/^kunde/i).first().waitFor({ timeout: 60_000 });
    const openMs = Date.now() - t0;
    const t1 = Date.now();
    await page.getByRole('button', { name: /^(weiter|next)$/i }).click();
    const firstQty = page.locator('tr[data-index="0"] input').nth(1);
    await firstQty.waitFor({ timeout: 60_000 });
    const stepMs = Date.now() - t1;
    const typingTop = await typeAndMeasure(page, firstQty, ['End', 'Backspace', '7', '5', 'Backspace', '9', '3', 'Backspace', '2', '4']);
    // Jump to the end of the list (virtualized) and type into one of the last rows.
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(800);
    const lastRow = page.locator('tr[data-index]').last();
    const lastQty = lastRow.locator('input').nth(1);
    const typingBottom = await typeAndMeasure(page, lastQty, ['End', 'Backspace', '6', '1', 'Backspace', '8', '2', 'Backspace', '5', '3']);
    const addStart = Date.now();
    const rowsBefore = await page.evaluate(() => Math.max(...[...document.querySelectorAll('tr[data-index]')].map((r) => Number(r.dataset.index))));
    await page.getByRole('button', { name: /zeile hinzufügen|add line/i }).click();
    await page.waitForFunction((n) => [...document.querySelectorAll('tr[data-index]')].some((r) => Number(r.dataset.index) > n), rowsBefore, { timeout: 20_000 });
    const addRowMs = Date.now() - addStart;

    // 300-element form: a fresh draft, first page, then each next step.
    const uuid300 = await serverDraft(page, f.perf300.versionId, {});
    const t2 = Date.now();
    await page.goto(`/app/fill/${uuid300}?formId=${f.perf300.id}&versionId=${f.perf300.versionId}`);
    const firstField = page.getByLabel(/Feld 1\.1\.1 \(text\)/).first();
    await firstField.waitFor({ timeout: 60_000 });
    const open300Ms = Date.now() - t2;
    const typing300 = await typeAndMeasure(page, firstField, ['K', 'u', 'n', 'd', 'e', ' ', 'A', 'B', 'C', 'D']);
    const steps = [];
    for (let p = 2; p <= 5; p++) {
        const ts = Date.now();
        await page.getByRole('button', { name: /^(weiter|next)$/i }).click();
        await page.getByLabel(new RegExp(`Feld ${p}\\.1\\.1 \\(text\\)`)).first().waitFor({ timeout: 30_000 });
        steps.push(Date.now() - ts);
    }
    result.fill[label] = {
        repeater500: { openMs, stepToRowsMs: stepMs, typingTop, typingBottom, addRowMs },
        form300: { openMs: open300Ms, typing: typing300, nextStepMs: stats(steps) },
    };
    console.log(`${label}: ${JSON.stringify(result.fill[label])}`);
    await context.close();
}

await browser.close();
for (const name of Object.keys(result.load)) {
    for (const mode of ['throttled', 'slow4g', 'unthrottled']) {
        const runs = result.load[name][mode];
        result.load[name][`${mode}Stats`] = Object.fromEntries(['ttfb', 'fcp', 'lcp', 'dcl', 'load', 'usable', 'tbt'].map((k) => [k, stats(runs.map((m) => m[k]))]));
    }
}
console.log(`written ${writeResult('field-app', result)}`);
