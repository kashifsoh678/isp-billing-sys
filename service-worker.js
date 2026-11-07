/**
 * Developed by Kashif Sohail (Kashif  Scripts)
 * WhatsApp: https://wa.me/923071344399 (For Custom Projects)
 * YouTube: https://www.youtube.com/@kashifsoh678 (Subscribe for more!)
 *
 * Service Worker for ISP Billing System PWA
 * - cache-first for static assets (CSS/JS/icons/CDN)
 * - network-first for PHP pages and AJAX (always fresh data; offline fallback)
 *
 * Hardened against:
 *  - chrome-extension:// / data: / blob: schemes (cache.put rejects these)
 *  - cross-origin opaque responses (skip caching; return as-is)
 *  - undefined return from .catch() (always return a real Response)
 */

const CACHE = 'isp-billing-v2';
const STATIC_ASSETS = [
    'styles.css?v=7.5',
    'searchable-dropdown.js',
    'manifest.json',
    'icon-192.svg',
    'icon-512.svg'
];

const CDN_PATTERNS = [
    'cdnjs.cloudflare.com',
    'cdn.jsdelivr.net',
    'cdn.datatables.net',
    'code.jquery.com'
];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(STATIC_ASSETS).catch(() => { }))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// safe cache write — silently swallows scheme/CORS errors
function safePut(req, resp) {
    if (!resp || !resp.ok) return;
    if (resp.type === 'opaqueredirect' || resp.type === 'opaque') return;
    try {
        const copy = resp.clone();
        caches.open(CACHE).then(c => {
            try { c.put(req, copy); } catch (_) { /* unsupported scheme */ }
        }).catch(() => { });
    } catch (_) { /* clone may throw on some streams */ }
}

// always-a-Response fallback so the browser never hits "Failed to convert to Response"
function offlineFallback() {
    return new Response('', { status: 504, statusText: 'Offline' });
}

self.addEventListener('fetch', (e) => {
    const req = e.request;

    // ignore non-GET (POST/PUT/DELETE shouldn't be cached)
    if (req.method !== 'GET') return;

    let url;
    try { url = new URL(req.url); } catch (_) { return; }

    // skip extension/data/blob/file schemes — cache API rejects them
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

    const isCDN = CDN_PATTERNS.some(p => url.hostname.includes(p));
    const isStatic = /\.(css|js|svg|png|jpg|jpeg|gif|webp|woff|woff2|ttf|ico)$/i.test(url.pathname);

    // cache-first for static + CDN
    if (isCDN || isStatic) {
        e.respondWith(
            caches.match(req).then(cached => {
                if (cached) return cached;
                return fetch(req).then(resp => {
                    safePut(req, resp);
                    return resp;
                }).catch(() => cached || offlineFallback());
            }).catch(() => offlineFallback())
        );
        return;
    }

    // network-first for PHP pages and everything else
    e.respondWith(
        fetch(req).then(resp => {
            if (req.url.endsWith('.php')) safePut(req, resp);
            return resp;
        }).catch(() =>
            caches.match(req).then(cached => cached || offlineFallback())
        )
    );
});

self.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'SKIP_WAITING') self.skipWaiting();
});
