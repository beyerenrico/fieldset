/**
 * Builder v2 entry (ADR 0037): loaded by async-alpine (`x-load`) on the CanvasBuilder page.
 * Placeholder until the client lands (M7-T11).
 */
export default function builderV2(): { ready: boolean; init(): void } {
    return {
        ready: false,
        init() {
            this.ready = true;
        },
    };
}
