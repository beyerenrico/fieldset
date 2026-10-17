// Admin page timings (M8-T05, BUILD_PROMPT §11 "admin pages p95 under 500 ms at 100k submissions").
//
//   node scripts/perf/admin-pages.mjs [--runs=30] [--warmup=3] [--only='name|name']
//
// Needs `php artisan app:seed-load demo 100000` and `php scripts/perf/fixtures.php` first. Each page is
// opened in Chromium as a signed-in user; after warm-up, every run records
// - doc: the page request from request start to the last HTML byte (Navigation Timing; server time
//   plus a localhost transfer),
// - lw: the slowest Livewire request the page fired while loading (lazy widgets, deferred parts),
// - complete: navigation start until the last of those Livewire responses (or the load event).
// Interactions (a search typed into a table) record the Livewire round trip they trigger.
import { adminContext, fixtures, launch, printTable, stats, waitForQuiet, writeResult } from './lib.mjs';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')).map(([k, v]) => [k, v ?? 'true']),
);
const RUNS = Number(args.runs ?? 30);
const WARMUP = Number(args.warmup ?? 3);
const ONLY = args.only ? new Set(args.only.split('|')) : null; // names separated by |

const f = await fixtures();
const t = `/admin/${f.organization.slug}`;
const monthAgo = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10);
const today = new Date().toISOString().slice(0, 10);
const owner = 'owner@demo.test';
const reviewer = 'reviewer@demo.test';

const PAGES = [
    { name: 'dashboard', user: owner, path: t },
    { name: 'forms list', user: owner, path: `${t}/forms` },
    { name: 'inbox (default)', user: owner, path: `${t}/submissions` },
    { name: 'inbox filter status', user: owner, path: `${t}/submissions?filters[status][values][0]=in_review` },
    {
        name: 'inbox filter form+date',
        user: owner,
        path: `${t}/submissions?filters[form_id][value]=${f.serviceReport.id}&filters[submitted_at][from]=${monthAgo}&filters[submitted_at][until]=${today}`,
    },
    { name: 'inbox search number', user: owner, path: `${t}/submissions?search=${encodeURIComponent(f.sample.number.slice(0, -2))}` },
    { name: 'inbox search no hit', user: owner, path: `${t}/submissions?search=zzqx` },
    { name: 'inbox sort updated', user: owner, path: `${t}/submissions?sort=updated_at:desc` },
    { name: 'submission detail', user: owner, path: `${t}/submissions/${f.sample.completed}` },
    { name: 'explorer', user: owner, path: `${t}/explorer?form=${f.serviceReport.id}` },
    { name: 'saved reports list', user: owner, path: `${t}/saved-reports` },
    { name: 'saved report (all, by type)', user: owner, path: `${t}/saved-reports/${f.savedReports[0]}` },
    { name: 'saved report (last year, by month)', user: owner, path: `${t}/saved-reports/${f.savedReports[1]}` },
    { name: 'audit log', user: owner, path: `${t}/audit-log` },
    { name: 'audit log filter event', user: owner, path: `${t}/audit-log?filters[event][values][0]=submission.approved` },
    { name: 'audit log filter actor', user: owner, path: `${t}/audit-log?filters[actor][values][0]=user:1` },
    { name: 'audit log filter date', user: owner, path: `${t}/audit-log?filters[created_at][from]=${monthAgo}` },
    { name: 'workflows list', user: owner, path: `${t}/workflows` },
    { name: 'members', user: owner, path: `${t}/members` },
    { name: 'mail log', user: owner, path: `${t}/mail-log` },
    { name: 'canvas builder (service report)', user: owner, path: `${t}/forms/${f.serviceReport.id}/canvas` },
    { name: 'canvas builder (300 elements)', user: owner, path: `${t}/forms/${f.perf300.id}/canvas` },
    { name: 'classic builder (service report)', user: owner, path: `${t}/forms/${f.serviceReport.id}/edit` },
    { name: 'reviewer: dashboard', user: reviewer, path: t },
    { name: 'reviewer: inbox', user: reviewer, path: `${t}/submissions` },
    { name: 'reviewer: my tasks', user: reviewer, path: `${t}/my-tasks` },
    { name: 'reviewer: submission detail', user: reviewer, path: `${t}/submissions/${f.sample.inReview}` },
];

// Search terms by selectivity in the generated data (10 names × 20 words, "Name Word" texts): a unique
// e-mail address, one name+word pair (≈ 2 % of the submissions) and a single name (≈ 35 %).
const INTERACTIONS = [
    { name: 'explorer search: unique term', user: owner, path: `${t}/explorer?form=${f.serviceReport.id}`, terms: ['kunde4711@', 'kunde1234@', 'kunde98765@'] },
    { name: 'explorer search: 2 % of rows', user: owner, path: `${t}/explorer?form=${f.serviceReport.id}`, terms: ['Schmidt Pumpe', 'Weber Ventil', 'Becker Kessel'] },
    { name: 'explorer search: 35 % of rows', user: owner, path: `${t}/explorer?form=${f.serviceReport.id}`, terms: ['Müller', 'Schulz', 'Wagner'] },
    { name: 'inbox search: number', user: owner, path: `${t}/submissions`, terms: [f.sample.number.slice(-6), f.sample.number.slice(0, -3), 'LT-'] },
];

const isLivewire = (request) => request.method() === 'POST' && /\/livewire(-[^/]+)?\/update/.test(request.url());

async function measureNavigation(page, path) {
    const finished = [];
    let inflight = 0;
    let lastChange = Date.now();
    const onRequest = (r) => {
        if (isLivewire(r)) {
            inflight++;
            lastChange = Date.now();
        }
    };
    const onDone = (r) => {
        if (isLivewire(r)) {
            inflight--;
            lastChange = Date.now();
            finished.push(r);
        }
    };
    page.on('request', onRequest);
    page.on('requestfinished', onDone);
    page.on('requestfailed', onDone);
    const response = await page.goto(path, { waitUntil: 'load' });
    if (response === null || response.status() >= 400 || page.url().includes('/login')) {
        throw new Error(`${path} answered ${response?.status()} at ${page.url()}`);
    }
    const deadline = Date.now() + 15_000;
    // Lazy widgets start right after Livewire boots; wait until no Livewire request ran for 400 ms.
    while (Date.now() < deadline && (inflight > 0 || Date.now() - lastChange < 400)) {
        await page.waitForTimeout(50);
    }
    page.off('request', onRequest);
    page.off('requestfinished', onDone);
    page.off('requestfailed', onDone);
    const nav = await page.evaluate(() => {
        const n = performance.getEntriesByType('navigation')[0];
        return {
            timeOrigin: performance.timeOrigin,
            doc: n.responseEnd - n.requestStart,
            ttfb: n.responseStart - n.requestStart,
            load: n.loadEventEnd - n.startTime,
            html: n.decodedBodySize,
        };
    });
    const lw = finished.map((r) => r.timing()).filter((tm) => tm.responseEnd > 0);
    const lwMax = lw.length ? Math.max(...lw.map((tm) => tm.responseEnd)) : 0;
    const lastEnd = lw.length ? Math.max(...lw.map((tm) => tm.startTime + tm.responseEnd)) - nav.timeOrigin : nav.load;
    return { doc: nav.doc, ttfb: nav.ttfb, lw: lwMax, lwCount: lw.length, complete: Math.max(nav.load, lastEnd), html: nav.html };
}

async function measureInteraction(page, spec) {
    await page.goto(spec.path, { waitUntil: 'networkidle' });
    const search = page.locator('.fi-ta-search-field input').first();
    await search.waitFor();
    const durations = [];
    const roundTrip = async (term) => {
        const finished = page.waitForEvent('requestfinished', { predicate: isLivewire, timeout: 30_000 });
        await search.fill(term);
        const request = await finished.catch((error) => {
            throw new Error(`${spec.name}: no Livewire round trip for "${term}" (${error.message.split('\n')[0]})`);
        });
        await page.waitForLoadState('networkidle', { timeout: 60_000 }).catch(() => undefined);
        return request.timing().responseEnd;
    };
    for (let i = 0; i < WARMUP + RUNS; i++) {
        if ((await search.inputValue()) !== '') await roundTrip('');
        const ms = await roundTrip(spec.terms[i % spec.terms.length]);
        if (i >= WARMUP) durations.push(ms);
    }
    return durations;
}

const browser = await launch();
const contexts = new Map();
const contextFor = async (user) => {
    if (!contexts.has(user)) contexts.set(user, await adminContext(browser, user));
    return contexts.get(user);
};

const rows = [];
for (const spec of PAGES) {
    if (ONLY && !ONLY.has(spec.name)) continue;
    const page = await (await contextFor(spec.user)).newPage();
    const load = await waitForQuiet();
    const samples = [];
    try {
        for (let i = 0; i < WARMUP + RUNS; i++) {
            const sample = await measureNavigation(page, spec.path);
            if (i >= WARMUP) samples.push(sample);
        }
    } catch (error) {
        console.error(`${spec.name}: ${error.message}`);
        rows.push({ page: spec.name, error: error.message });
        await page.close();
        continue;
    }
    await page.close();
    const doc = stats(samples.map((s) => s.doc));
    const lw = stats(samples.map((s) => s.lw));
    const complete = stats(samples.map((s) => s.complete));
    const row = {
        page: spec.name,
        user: spec.user.split('@')[0],
        path: spec.path,
        doc,
        lw,
        complete,
        lwRequests: samples[0].lwCount,
        load,
        htmlKb: Math.round(samples[0].html / 1024),
    };
    rows.push(row);
    console.log(
        `${spec.name.padEnd(36)} doc p50 ${doc.p50} p95 ${doc.p95} max ${doc.max} | lw(${row.lwRequests}) p95 ${lw.p95} | complete p95 ${complete.p95} | ${row.htmlKb} kB | load ${load}`,
    );
}

const interactions = [];
for (const spec of INTERACTIONS) {
    if (ONLY && !ONLY.has(spec.name)) continue;
    const page = await (await contextFor(spec.user)).newPage();
    const load = await waitForQuiet();
    const durations = await measureInteraction(page, spec);
    await page.close();
    const s = stats(durations);
    interactions.push({ interaction: spec.name, ...s, load });
    console.log(`${spec.name.padEnd(36)} livewire p50 ${s.p50} p95 ${s.p95} max ${s.max}`);
}

await browser.close();
const file = writeResult(ONLY ? 'admin-pages-partial' : 'admin-pages', { runs: RUNS, warmup: WARMUP, pages: rows, interactions });
console.log('\n');
printTable(
    rows.filter((r) => !r.error).map((r) => ({
        page: r.page,
        'doc p50': r.doc.p50,
        'doc p95': r.doc.p95,
        'doc max': r.doc.max,
        'lw p95': r.lw.p95,
        'complete p95': r.complete.p95,
        kB: r.htmlKb,
        pass: r.doc.p95 < 500 && r.lw.p95 < 500 ? 'yes' : 'NO',
    })),
    ['page', 'doc p50', 'doc p95', 'doc max', 'lw p95', 'complete p95', 'kB', 'pass'],
);
console.log(`\nwritten ${file}`);
