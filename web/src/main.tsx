import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import CapturePage from './CapturePage';
import SignPage from './SignPage';
import './styles.css';

const root = document.getElementById('root');

if (root === null) {
    throw new Error('Root element not found');
}

const signingMatch = /^\/sign\/([^/]+)\/?$/.exec(window.location.pathname);

const apiBase = window.__PROPERTYTRAIL_BOOTSTRAP__?.apiBase ?? '/api/v1';

function page() {
    if (/^\/capture\/?$/.test(window.location.pathname)) {
        // The token stays in the fragment, so a bookmark or reload resumes the capture.
        return <CapturePage apiBase={apiBase} token={window.location.hash.slice(1)} />;
    }

    return signingMatch === null ? <App /> : <SignPage apiBase={apiBase} token={decodeURIComponent(signingMatch[1])} />;
}

createRoot(root).render(<StrictMode>{page()}</StrictMode>);

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        void navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined);
    });
}
