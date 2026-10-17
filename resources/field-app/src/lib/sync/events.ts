/**
 * Outcomes of single outbox ops inside this tab (ADR 0035). Screens that start an op interactively
 * (submit, send) listen here for the server's answer; everything else reads the store.
 */
import type { OutboxKind } from '../db/schema.ts';

export type OutboxEvent =
    | {
          readonly type: 'done';
          readonly id: string;
          readonly kind: OutboxKind;
          readonly submissionUuid: string;
          readonly result: unknown;
      }
    | {
          readonly type: 'failed';
          readonly id: string;
          readonly kind: OutboxKind;
          readonly submissionUuid: string;
          readonly error: unknown;
          /** True when the op stays queued and is retried automatically (network, 5xx, 429). */
          readonly retrying: boolean;
      };

type Listener = (event: OutboxEvent) => void;

const listeners = new Set<Listener>();

export function onOutboxEvent(listener: Listener): () => void {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

export function emitOutboxEvent(event: OutboxEvent): void {
    for (const listener of listeners) {
        listener(event);
    }
}

/** Ops a screen in this tab is waiting for (interactive submit or send), with a count per id. */
const awaited = new Map<string, number>();

/**
 * Marks the op as awaited by a screen until the returned function runs. An awaited refusal is
 * answered on the screen; a refusal nobody waits for blocks the draft so lists show it.
 */
export function awaitOp(id: string): () => void {
    awaited.set(id, (awaited.get(id) ?? 0) + 1);
    let released = false;
    return () => {
        if (released) {
            return;
        }
        released = true;
        const left = (awaited.get(id) ?? 1) - 1;
        if (left > 0) {
            awaited.set(id, left);
        } else {
            awaited.delete(id);
        }
    };
}

export function isAwaited(id: string): boolean {
    return awaited.has(id);
}
