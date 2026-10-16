import 'fake-indexeddb/auto';
import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// Lazy route chunks are transformed on first use; give async queries headroom under load.
configure({ asyncUtilTimeout: 3000 });

// jsdom does not implement scrolling; the router's scroll restoration calls it.
window.scrollTo = vi.fn();
Element.prototype.scrollIntoView = vi.fn();

afterEach(() => {
    cleanup();
});
