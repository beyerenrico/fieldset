/**
 * The contract between the Builder v2 client and the CanvasBuilder Livewire page (ADR 0037).
 * Orchestrator-owned: a change needs both sides and the fixtures in tests/Fixtures/builder-v2/contract.
 * JSON travels as strings over Livewire (it would turn `{}` into `[]`, ADR 0022).
 */
import type { JsonObject } from '@form-schema-app/form-schema-js';

/** Where a node lives: the page list, a page, a section/repeater's elements, or one column. */
export type Container =
    | { readonly kind: 'pages' }
    | { readonly kind: 'page'; readonly pageId: string }
    | { readonly kind: 'children'; readonly parentId: string }
    | { readonly kind: 'column'; readonly parentId: string; readonly column: number };

/** Client-side edits; the server only ever receives whole definitions. */
export type Operation =
    | {
          readonly op: 'insert';
          readonly target: Container;
          readonly index: number;
          readonly node: JsonObject;
      }
    /** `index` is the final position after the node was removed from its old place. */
    | {
          readonly op: 'move';
          readonly id: string;
          readonly target: Container;
          readonly index: number;
      }
    | { readonly op: 'remove'; readonly id: string }
    /** Own properties only; children (elements/columns) are kept. */
    | {
          readonly op: 'update';
          readonly kind: InspectorKind;
          readonly id: string | null;
          readonly props: JsonObject;
      }
    | { readonly op: 'addColumn'; readonly parentId: string; readonly index: number }
    /** Elements of the removed column move to the neighbouring column. */
    | { readonly op: 'removeColumn'; readonly parentId: string; readonly column: number };

export type InspectorKind = 'settings' | 'page' | 'element';

export interface PaletteItem {
    readonly type: string;
    readonly label: string;
    readonly icon: string;
    /** Default node without id (the client assigns UUIDv7 ids on insert). */
    readonly node: JsonObject;
}

export interface PaletteGroup {
    readonly group: string;
    readonly label: string;
    readonly items: readonly PaletteItem[];
}

/** Embedded once at mount as `<script type="application/json" data-builder-config>`. */
export interface BuilderConfig {
    readonly formId: number;
    readonly definition: JsonObject;
    /** `Json::checksum` of the stored draft: plain lowercase hex (no prefix). */
    readonly checksum: string;
    /** Ids that appear in a published version: their keys are locked. */
    readonly lockedIds: readonly string[];
    /** A list at this depth accepts no containers (v1 parity). */
    readonly maxContainerDepth: number;
    readonly palette: readonly PaletteGroup[];
    readonly urls: { readonly preview: string; readonly classic: string };
}

/** Sent with `inspect()` / `commitInspector()` as a JSON string. */
export interface InspectorScope {
    readonly keyTypes: Readonly<Record<string, string>>;
    readonly rowKeys: Readonly<Record<string, readonly string[]>>;
    /** Every key in the node's scope, layout keys included (section, columns, heading…): the linter's
     *  duplicate_key counts them, so autoKey suggestions must avoid them too. */
    readonly scopeKeys: readonly string[];
    /** The key still follows the label (new palette node, key not edited yet). */
    readonly autoKey: boolean;
}

export interface LintFinding {
    readonly severity: 'error' | 'warning';
    readonly code: string;
    /** JSON Pointer into the definition. */
    readonly path: string;
}

export interface LintReport {
    readonly schema_errors: readonly { readonly path: string; readonly message: string }[];
    readonly findings: readonly LintFinding[];
}

/** Result of `sync()` / `save()`. */
export interface SyncResult {
    readonly status: 'saved' | 'unchanged' | 'invalid' | 'conflict';
    readonly checksum: string;
    readonly lint: LintReport;
    readonly savedAt: string | null;
}

/**
 * Browser event `builder-v2:node-changed` (detail). `node` is a JSON string with ALL own properties of
 * the node and no children: replace the own properties (a missing one was removed), keep the children.
 */
export interface NodeChangedDetail {
    readonly kind: InspectorKind;
    readonly id: string | null;
    readonly node: string;
}

/** Browser event `builder-v2:published` (detail). */
export interface PublishedDetail {
    readonly version: number;
    readonly lockedIds: readonly string[];
}

export type SyncState = 'idle' | 'pending' | 'saving' | 'saved' | 'invalid' | 'conflict' | 'error';

/** The Livewire methods of CanvasBuilder the client calls (through an adapter, so tests can fake it). */
export interface BuilderWire {
    sync(definition: string, baseChecksum: string, force?: boolean): Promise<SyncResult>;
    save(definition: string, baseChecksum: string, force?: boolean): Promise<SyncResult>;
    inspect(kind: InspectorKind, id: string | null, node: string, scope: string): Promise<void>;
    commitInspector(scope: string): Promise<void>;
    cloneNode(node: string, scopeKeys: string): Promise<string>;
    mountAction(
        name: 'publish' | 'previewPdf' | 'exportDraft',
        args: { checksum: string },
    ): Promise<void>;
}
