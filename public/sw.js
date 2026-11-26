const CACHE_NAME = 'propertytrail-shell-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.add(new Request('/', { cache: 'reload' })))
            .catch(() => undefined)
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

async function shellResponse(request) {
    const cache = await caches.open(CACHE_NAME);
    const shellKey = new Request('/', { cache: 'reload' });

    try {
        const response = await fetch(request);

        if (response.ok) {
            await cache.put(shellKey, response.clone());
        }

        return response;
    } catch {
        const cached = (await cache.match(shellKey)) ?? (await cache.match(request));

        if (cached !== undefined) {
            return cached;
        }

        return new Response('You are offline. Open PropertyTrail once while connected to use it offline.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
        });
    }
}

async function assetResponse(request) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);

    if (cached !== undefined) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        await cache.put(request, response.clone());
    }

    return response;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (
        url.pathname.startsWith('/api/')
        || url.pathname.startsWith('/sanctum/')
        || url.pathname.startsWith('/auth/')
        || url.pathname.startsWith('/r/')
        || url.pathname === '/version'
        || url.pathname === '/up'
    ) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(shellResponse(request));
        return;
    }

    if (
        url.pathname.startsWith('/spa/')
        || url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/icons/')
        || url.pathname === '/manifest.webmanifest'
        || url.pathname === '/favicon.ico'
    ) {
        event.respondWith(assetResponse(request));
    }
});
