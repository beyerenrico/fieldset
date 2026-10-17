/** Sync state on screen: `data-builder-sync-state`, the toolbar label and the conflict/invalid banner. */
import type { SyncState } from './contract.ts';
import { h, setText } from './dom.ts';
import type { Translator } from './i18n.ts';

export interface StatusHandlers {
    readonly reload: () => void;
    readonly overwrite: () => void;
}

export class SyncStatus {
    readonly #root: HTMLElement;
    readonly #label: HTMLElement | null;
    readonly #banner: HTMLElement | null;
    readonly #tr: Translator;
    readonly #handlers: StatusHandlers;

    constructor(root: HTMLElement, tr: Translator, handlers: StatusHandlers) {
        this.#root = root;
        this.#tr = tr;
        this.#handlers = handlers;
        this.#label = root.querySelector<HTMLElement>('[data-builder-sync-label]');
        this.#banner = root.querySelector<HTMLElement>('[data-builder-banner]');
        this.set('idle');
    }

    set(state: SyncState): void {
        this.#root.dataset.builderSyncState = state;
        if (this.#label !== null) {
            setText(this.#label, this.#tr.t(`sync.${state}`));
        }
        this.#renderBanner(state);
    }

    #renderBanner(state: SyncState): void {
        const banner = this.#banner;
        if (banner === null) {
            return;
        }
        banner.dataset.state = state;
        if (state !== 'conflict' && state !== 'invalid') {
            banner.hidden = true;
            banner.replaceChildren();
            return;
        }
        const tr = this.#tr;
        const message = h('p', {}, [
            tr.t(state === 'conflict' ? 'conflict.message' : 'invalid.message'),
        ]);
        const actions =
            state === 'conflict'
                ? [
                      this.#button('reload', tr.t('conflict.reload'), this.#handlers.reload),
                      this.#button(
                          'overwrite',
                          tr.t('conflict.overwrite'),
                          this.#handlers.overwrite,
                      ),
                  ]
                : [];
        banner.replaceChildren(message, ...actions);
        banner.hidden = false;
    }

    #button(name: string, label: string, run: () => void): HTMLButtonElement {
        const button = h(
            'button',
            { type: 'button', class: 'bv2-btn', 'data-builder-conflict': name },
            [label],
        );
        button.addEventListener('click', run);
        return button;
    }
}
