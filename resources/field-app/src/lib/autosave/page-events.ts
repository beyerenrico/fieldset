/** Page events that trigger saves: back online, and the page being hidden (tab switch, kill). */
export function watchPage(onOnline: () => void, onHidden: () => void): () => void {
    const onVisibility = (): void => {
        if (document.visibilityState === 'hidden') {
            onHidden();
        }
    };
    window.addEventListener('online', onOnline);
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
        window.removeEventListener('online', onOnline);
        document.removeEventListener('visibilitychange', onVisibility);
    };
}
