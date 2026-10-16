export const MINUTE = 60_000;
/** Polling fallback without Reverb (§8.9): every minute while the tab is visible, plus on focus. */
export const POLL_INTERVAL_MS = MINUTE;
export const polling = {
    refetchInterval: POLL_INTERVAL_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: true,
} as const;
