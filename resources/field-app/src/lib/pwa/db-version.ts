/** Reload banner when another tab changes the offline store's schema (ADR 0035). */
import { db } from '../db/db.ts';
import { showDatabaseNotice } from './sw-update.ts';

type VersionEvents = Pick<typeof db, 'on'>;

/**
 * `versionchange`: another tab opens a newer schema (or deletes the store); Dexie closes this
 * connection, so this tab must reload. `blocked`: this tab's upgrade waits for an older tab.
 */
export function watchDatabaseVersion(database: VersionEvents = db): () => void {
    const outdated = (): void => {
        showDatabaseNotice('outdated');
    };
    const blocked = (): void => {
        showDatabaseNotice('blocked');
    };
    database.on('versionchange', outdated);
    database.on('blocked', blocked);
    return () => {
        database.on('versionchange').unsubscribe(outdated);
        database.on('blocked').unsubscribe(blocked);
    };
}
