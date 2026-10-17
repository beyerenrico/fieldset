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
