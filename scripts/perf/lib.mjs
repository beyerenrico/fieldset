// Shared helpers for the performance scripts (M8-T05). Run them with Node 24 against the Docker stack
// (http://localhost:8080 by default, PERF_BASE_URL to override); Playwright drives a real Chromium.
// Results land in storage/agent-logs/perf (git-ignored) unless PERF_OUT_DIR says otherwise.
//
// An `fsa` instance (J10, plan U15) needs four more settings, all optional and unset for the dev stack:
// - PERF_PASSWORDS_FILE: an `fsa --secrets-file` document; `demo_password:<email>` entries override PERF_PASSWORD;
// - PERF_CHROMIUM_ARGS: a JSON array of extra Chromium flags (host resolver rules, trusted SPKI hashes);
// - PERF_RESOLVE: `host:port:address` (curl's --resolve) for requests made from Node;
// - PERF_CA_FILE: a PEM file Node trusts for those requests (the test CA of the instance's certificate).
import { mkdirSync, writeFileSync, existsSync, readFileSync } from 'node:fs';
import { request as httpsRequest, Agent } from 'node:https';
import { loadavg } from 'node:os';
import { resolve } from 'node:path';

process.env.PLAYWRIGHT_BROWSERS_PATH ??= resolve('storage/playwright');
const { chromium } = await import('@playwright/test');

export const BASE = process.env.PERF_BASE_URL ?? 'http://localhost:8080';
export const OUT_DIR = resolve(process.env.PERF_OUT_DIR ?? 'storage/agent-logs/perf');
export const STATE_DIR = resolve(OUT_DIR, 'auth');
export const PASSWORD = process.env.PERF_PASSWORD ?? 'password';
mkdirSync(STATE_DIR, { recursive: true });

const PASSWORDS = (() => {
    const file = process.env.PERF_PASSWORDS_FILE ?? '';
    if (file === '') return {};
    const secrets = JSON.parse(readFileSync(file, 'utf8')).secrets ?? [];
    return Object.fromEntries(
        secrets.filter((s) => String(s.name).startsWith('demo_password:')).map((s) => [s.name.slice('demo_password:'.length), s.value]),
    );
})();

/** The password of a user: the instance's printed demo password when PERF_PASSWORDS_FILE has one, else PASSWORD. */
export function passwordFor(email) {
    return PASSWORDS[email] ?? PASSWORD;
}

const CHROMIUM_ARGS = JSON.parse(process.env.PERF_CHROMIUM_ARGS ?? '[]');
const [RESOLVE_HOST, RESOLVE_PORT, RESOLVE_ADDRESS] = (process.env.PERF_RESOLVE ?? '').split(':');
const NODE_AGENT = new Agent({
    keepAlive: true,
    ...(process.env.PERF_CA_FILE ? { ca: readFileSync(process.env.PERF_CA_FILE) } : {}),
    ...(RESOLVE_ADDRESS
        ? { lookup: (host, options, callback) => (options.all ? callback(null, [{ address: RESOLVE_ADDRESS, family: 4 }]) : callback(null, RESOLVE_ADDRESS, 4)) }
        : {}),
});

/**
 * GET from Node: global fetch for the dev stack, node:https with PERF_RESOLVE / PERF_CA_FILE (keep-alive, like fetch)
 * when the base URL is a test domain that only the instance's edge answers. Resolves to { status, body, headers }.
 */
export async function nodeGet(url, headers) {
    const target = new URL(url);
    if (target.protocol !== 'https:' || target.hostname !== RESOLVE_HOST) {
        const response = await fetch(url, { headers });
        return { status: response.status, body: await response.text(), headers: Object.fromEntries(response.headers) };
    }
    return new Promise((done, fail) => {
        const request = httpsRequest({ host: target.hostname, port: Number(target.port || RESOLVE_PORT || 443), path: target.pathname + target.search, headers, agent: NODE_AGENT }, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('end', () => done({ status: response.statusCode ?? 0, body: Buffer.concat(chunks).toString('utf8'), headers: response.headers }));
            response.on('error', fail);
        });
        request.on('error', fail);
        request.end();
    });
}

/** Nearest-rank percentile of an ascending list. */
export function percentile(sorted, p) {
    if (sorted.length === 0) return NaN;
    return sorted[Math.min(sorted.length, Math.max(1, Math.ceil((p / 100) * sorted.length))) - 1];
}

const round = (n) => Math.round(n * 10) / 10;

/** n, p50, p95, max and mean of a list of milliseconds. */
export function stats(values) {
    const sorted = [...values].filter((v) => Number.isFinite(v)).sort((a, b) => a - b);
    const mean = sorted.reduce((sum, v) => sum + v, 0) / (sorted.length || 1);
    return {
        n: sorted.length,
        p50: round(percentile(sorted, 50)),
        p95: round(percentile(sorted, 95)),
        max: round(sorted.at(-1) ?? NaN),
        mean: round(mean),
    };
}

export function writeResult(name, data) {
    mkdirSync(OUT_DIR, { recursive: true });
    const file = resolve(OUT_DIR, `${name}.json`);
    writeFileSync(file, JSON.stringify({ measuredAt: new Date().toISOString(), base: BASE, ...data }, null, 2));
    return file;
}

export function printTable(rows, columns) {
    const widths = columns.map((c) => Math.max(c.length, ...rows.map((r) => String(r[c] ?? '').length)));
    const line = (cells) => cells.map((cell, i) => String(cell ?? '').padEnd(widths[i])).join('  ');
    console.log(line(columns));
    for (const row of rows) console.log(line(columns.map((c) => row[c])));
}

export async function launch(options = {}) {
    return chromium.launch({ headless: true, ...options, args: [...CHROMIUM_ARGS, ...(options.args ?? [])] });
}

/**
 * A browser context signed in to /admin (email-first login, ADR 0036). The session is cached per user
 * in STATE_DIR, because Filament throttles admin logins (5 per minute per IP).
 */
export async function adminContext(browser, email, contextOptions = {}) {
    const state = resolve(STATE_DIR, `admin-${email}.json`);
    if (existsSync(state)) {
        const context = await browser.newContext({ baseURL: BASE, storageState: state, ...contextOptions });
        const page = await context.newPage();
        const response = await page.goto('/admin');
        const ok = response !== null && !page.url().includes('/login');
        await page.close();
        if (ok) return context;
        await context.close();
    }
    const context = await browser.newContext({ baseURL: BASE, ...contextOptions });
    const page = await context.newPage();
    await page.goto('/admin/login');
    await page.waitForFunction(() => 'Livewire' in window);
    await page.waitForLoadState('networkidle');
    await page.locator('input[type="email"]').fill(email);
    const next = page.getByRole('button', { name: /^\s*(continue|weiter)\s*$/i });
    const password = page.locator('input[autocomplete="current-password"]');
    if ((await next.count()) > 0) {
        await next.click();
        await password.waitFor();
    }
    await password.fill(passwordFor(email));
    await page.getByRole('button', { name: /^\s*(sign in|anmelden)\s*$/i }).click();
    await page.waitForURL((url) => /^\/admin\/[a-z0-9-]+/.test(url.pathname) && !url.pathname.startsWith('/admin/login'), {
        timeout: 30_000,
    });
    await context.storageState({ path: state });
    await page.close();
    return context;
}

/** A browser context signed in to the Field App (/app/), cached per user like adminContext(). */
export async function fieldAppContext(browser, email, contextOptions = {}) {
    const state = resolve(STATE_DIR, `app-${email}.json`);
    const context = await browser.newContext({
        baseURL: BASE,
        ...(existsSync(state) ? { storageState: state } : {}),
        ...contextOptions,
    });
    const page = await context.newPage();
    await page.goto('/app/');
    const greeting = page.getByText(/^(hello|hallo), /i);
    const email_ = page.getByLabel(/^(email|e-mail)$/i);
    await Promise.race([greeting.waitFor({ timeout: 20_000 }), email_.waitFor({ timeout: 20_000 })]);
    if (!(await greeting.isVisible())) {
        await email_.fill(email);
        const passwordField = page.getByLabel(/^(password|passwort)$/i);
        if (!(await passwordField.isVisible())) {
            await page.getByRole('button', { name: /^(continue|weiter)$/i }).click();
            await passwordField.waitFor({ timeout: 20_000 });
        }
        await passwordField.fill(passwordFor(email));
        await page.getByRole('button', { name: /^(sign in|anmelden)$/i }).click();
        await greeting.waitFor({ timeout: 30_000 });
        await context.storageState({ path: state, indexedDB: false });
    }
    return { context, page };
}

/**
 * Waits until the host is quiet (1-minute load average below PERF_QUIET_LOAD, default 5) so other work on
 * the machine does not leak into the numbers; gives up after PERF_QUIET_WAIT seconds (default 1200) and
 * returns the load it started with, which the scripts record next to each series.
 */
export async function waitForQuiet() {
    const limit = Number(process.env.PERF_QUIET_LOAD ?? 5);
    const deadline = Date.now() + Number(process.env.PERF_QUIET_WAIT ?? 1200) * 1000;
    while (loadavg()[0] >= limit && Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, 5000));
    }
    return Math.round(loadavg()[0] * 10) / 10;
}

/** Reads a JSON file written by fixtures.php (ids, tokens). */
export async function fixtures() {
    const { readFileSync } = await import('node:fs');
    return JSON.parse(readFileSync(resolve(OUT_DIR, 'fixtures.json'), 'utf8'));
}
