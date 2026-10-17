import '@form-schema-app/form-schema-react/styles.css';
import './styles/app.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { registerSW } from 'virtual:pwa-register';
import { App } from './App.tsx';
import { startPwa } from './lib/pwa/start.ts';

// Service worker (prompted updates), install prompt capture and offline-store housekeeping.
startPwa(registerSW);

const root = document.getElementById('root');
if (root !== null) {
    createRoot(root).render(
        <StrictMode>
            <App />
        </StrictMode>,
    );
}
