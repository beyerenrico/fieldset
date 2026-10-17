/** Boot-time wiring of the PWA shell (main.tsx only; ADR 0035). */
import { startAttachmentPruning } from '../attachments/local-store.ts';
import { watchDatabaseVersion } from './db-version.ts';
import { startInstallCapture } from './install-prompt.ts';
import { listenForServiceWorkerMessages } from './sw-messages.ts';
import { startServiceWorker, type RegisterSW } from './sw-update.ts';

export function startPwa(register: RegisterSW): void {
    startInstallCapture();
    watchDatabaseVersion();
    startAttachmentPruning();
    if ('serviceWorker' in navigator) {
        listenForServiceWorkerMessages();
        startServiceWorker(register);
    }
}
