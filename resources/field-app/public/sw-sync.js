/**
 * Background Sync wake-up (ADR 0035), loaded into the generated service worker with importScripts.
 * Chromium fires `sync` for the tag the app registered while offline. The worker has no outbox
 * executor (QUESTIONS #13): it only tells the open tabs, and their sync engine sends the queue.
 */
self.addEventListener('sync', (event) => {
    if (event.tag !== 'fsa-outbox') {
        return;
    }
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                client.postMessage({ type: 'fsa-sync' });
            }
        }),
    );
});
