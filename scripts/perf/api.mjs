// API timings (M8-T05, BUILD_PROMPT §11 "API p95 under 300 ms").
//
//   node scripts/perf/api.mjs [--runs=30] [--warmup=3]
//
// Needs `php artisan app:seed-load demo 100000` and `php scripts/perf/fixtures.php` first.
// Field App sessions (worker, reviewer, owner) call /api/v1 from inside the signed-in Field App page with
// the headers the app sends (fetch + performance.now(), the body read included); the integration token
// of fixtures.json calls it from Node with a Bearer header. Sequential requests, one client.
import { fieldAppContext, fixtures, launch, printTable, stats, waitForQuiet, writeResult, BASE } from './lib.mjs';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')).map(([k, v]) => [k, v ?? 'true']),
);
const RUNS = Number(args.runs ?? 30);
const WARMUP = Number(args.warmup ?? 3);
const f = await fixtures();
const org = f.organization.id;
const sr = f.serviceReport;

const SESSION = {
    'worker@demo.test': [
        ['me', '/api/v1/me'],
        ['sync manifest', '/api/v1/sync/manifest'],
        ['forms', '/api/v1/forms'],
        ['form version (service report)', `/api/v1/forms/${sr.id}/versions/${sr.versionId}`],
        ['form version (300 elements)', `/api/v1/forms/${f.perf300.id}/versions/${f.perf300.versionId}`],
        ['submissions (own)', '/api/v1/submissions'],
        ['submissions (own, page 20)', '/api/v1/submissions?page=20'],
        ['notifications', '/api/v1/notifications'],
    ],
    'reviewer@demo.test': [
        ['submissions (reviewer)', '/api/v1/submissions'],
        ['submissions (reviewer, in_review)', '/api/v1/submissions?status=in_review'],
        ['submission show (reviewer)', `/api/v1/submissions/${f.sample.inReview}`],
        ['tasks (reviewer)', '/api/v1/tasks'],
        ['sync manifest (reviewer)', '/api/v1/sync/manifest'],
    ],
    'owner@demo.test': [
        ['submissions (all)', '/api/v1/submissions'],
        ['submissions (all, status)', '/api/v1/submissions?status=completed'],
        ['submissions (all, form)', `/api/v1/submissions?form_id=${sr.id}`],
        ['submissions (all, page 100)', '/api/v1/submissions?page=100'],
        ['submission show', `/api/v1/submissions/${f.sample.completed}`],
    ],
};

const TOKEN = [
    ['token: forms', '/api/v1/forms'],
    ['token: submissions', '/api/v1/submissions'],
    ['token: submissions (form)', `/api/v1/submissions?form_id=${sr.id}`],
    ['token: submission show', `/api/v1/submissions/${f.sample.completed}`],
    ['token: tasks', '/api/v1/tasks'],
];

async function inPage(page, path) {
    return page.evaluate(
        async ({ path, org }) => {
            const started = performance.now();
            const response = await fetch(path, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Organization-Id': String(org) },
            });
            const body = await response.text();
            return { ms: performance.now() - started, status: response.status, bytes: body.length, retryAfter: Number(response.headers.get('Retry-After') ?? 0) };
        },
        { path, org },
    );
}

async function withToken(path) {
    const started = performance.now();
    const response = await fetch(BASE + path, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${f.token}`, 'X-Organization-Id': String(org) },
    });
    const body = await response.text();
    return { ms: performance.now() - started, status: response.status, bytes: body.length, retryAfter: Number(response.headers.get('Retry-After') ?? 0) };
}

async function series(name, call) {
    const load = await waitForQuiet();
    const samples = [];
    let last;
    for (let i = 0; i < WARMUP + RUNS; i++) {
        last = await call();
        if (last.status === 429) {
            // The API allows 240 requests per minute and user (by design): wait it out, do not count the request.
            await new Promise((resolve) => setTimeout(resolve, (last.retryAfter + 1) * 1000));
            i--;
            continue;
        }
        if (last.status >= 400) throw new Error(`${name}: HTTP ${last.status}`);
        if (i >= WARMUP) samples.push(last.ms);
    }
    const s = stats(samples);
    console.log(`${name.padEnd(36)} p50 ${s.p50} p95 ${s.p95} max ${s.max} (${Math.round(last.bytes / 1024)} kB) load ${load}`);
    return { endpoint: name, ...s, kB: Math.round(last.bytes / 1024), load };
}

const browser = await launch();
const rows = [];
for (const [user, endpoints] of Object.entries(SESSION)) {
    const { context, page } = await fieldAppContext(browser, user);
    for (const [name, path] of endpoints) {
        try {
            rows.push({ client: `session ${user.split('@')[0]}`, path, ...(await series(name, () => inPage(page, path))) });
        } catch (error) {
            console.error(error.message);
            rows.push({ endpoint: name, error: error.message });
        }
    }
    await context.close();
}
for (const [name, path] of TOKEN) {
    try {
        rows.push({ client: 'integration token (owner)', path, ...(await series(name, () => withToken(path))) });
    } catch (error) {
        console.error(error.message);
        rows.push({ endpoint: name, error: error.message });
    }
}
await browser.close();

const file = writeResult('api', { runs: RUNS, warmup: WARMUP, endpoints: rows });
console.log('\n');
printTable(
    rows.filter((r) => !r.error).map((r) => ({ endpoint: r.endpoint, p50: r.p50, p95: r.p95, max: r.max, kB: r.kB, pass: r.p95 < 300 ? 'yes' : 'NO' })),
    ['endpoint', 'p50', 'p95', 'max', 'kB', 'pass'],
);
console.log(`\nwritten ${file}`);
