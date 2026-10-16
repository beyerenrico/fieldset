import '@form-schema-app/form-schema-react/styles.css';
import './preview.css';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PreviewApp } from './PreviewApp.tsx';

const container = document.getElementById('preview-root');
if (container !== null) {
    createRoot(container).render(
        <StrictMode>
            <PreviewApp />
        </StrictMode>,
    );
}
