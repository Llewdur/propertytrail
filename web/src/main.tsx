import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import SignPage from './SignPage';
import './styles.css';

const root = document.getElementById('root');

if (root === null) {
    throw new Error('Root element not found');
}

const signingMatch = /^\/sign\/([^/]+)\/?$/.exec(window.location.pathname);

createRoot(root).render(
    <StrictMode>
        {signingMatch === null ? (
            <App />
        ) : (
            <SignPage apiBase={window.__PROPERTYTRAIL_BOOTSTRAP__?.apiBase ?? '/api/v1'} token={decodeURIComponent(signingMatch[1])} />
        )}
    </StrictMode>,
);
