/**
 * Moments when the page may be killed without further notice (§9.2 "a killed tab loses no data"):
 * `pagehide`, `freeze` (Page Lifecycle, Chromium) and the page becoming hidden.
 */
export function watchPage(onLeaving: () => void): () => void {
    const onVisibility = (): void => {
        if (document.visibilityState === 'hidden') {
            onLeaving();
        }
    };
    window.addEventListener('pagehide', onLeaving);
    document.addEventListener('freeze', onLeaving);
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
        window.removeEventListener('pagehide', onLeaving);
        document.removeEventListener('freeze', onLeaving);
        document.removeEventListener('visibilitychange', onVisibility);
    };
}
