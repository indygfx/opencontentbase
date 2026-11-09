/* OpenContentBase service worker - PWA stage 1 (install + offline reading).
   Self-contained, dependency-free. Scope: "/" (served from /sw.js).

   Route classes:
   - /assets/... and /manifest.webmanifest : cache-first (versioned static cache)
   - public reading pages (/, /pages..., /blog...) : stale-while-revalidate (GET)
   - other app pages (/login, /profile, /users, ...) : network-first (GET),
     cached copy only as offline fallback
   - /writing... (writing module) : network-only, NEVER cached; on failure the
     offline page is served
   - POST/PUT/DELETE : never cached; on failure the offline page is served
   - any navigation without a cache entry that fails -> /offline.html

   Update flow: skipWaiting() + clients.claim() on activate; caches of older
   VERSION keys are deleted. The app shows an "update available" toast
   (app.js listens for updatefound/controllerchange). */

const VERSION = 'v4'; // prompt 27b changes master.css + editor.css - bump in the same commit // prompt 27 changes master.css - bump in the same commit
const STATIC_CACHE = 'ocb-static-' + VERSION;
const PAGE_CACHE = 'ocb-pages-' + VERSION;
const OFFLINE_URL = '/offline.html';
const ASSET_PREFIX = '/assets/';
const WRITING_PREFIX = '/writing';

self.addEventListener('install', function (event) {
    // Precache the offline page so the fallback always works, then activate
    // immediately (skipWaiting happens on activate per the update flow).
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(function (cache) { return cache.add(OFFLINE_URL); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys.map(function (key) {
                    if (key !== STATIC_CACHE && key !== PAGE_CACHE) {
                        return caches.delete(key);
                    }
                }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

function isPublicReadingPage(path) {
    return path === '/' || path.indexOf('/pages') === 0 || path.indexOf('/blog') === 0;
}

self.addEventListener('fetch', function (event) {
    var req = event.request;
    var url = new URL(req.url);

    // Non-GET requests are never cached; on failure a navigation gets the
    // offline page, everything else a plain network error.
    if (req.method !== 'GET') {
        event.respondWith(
            fetch(req).catch(function () {
                if (req.mode === 'navigate') {
                    return caches.match(OFFLINE_URL);
                }
                return Response.error();
            })
        );
        return;
    }

    if (url.origin !== self.location.origin) {
        return; // let the browser handle cross-origin requests
    }
    var path = url.pathname;

    // Static assets + manifest: cache-first.
    if (path.indexOf(ASSET_PREFIX) === 0 || path === '/manifest.webmanifest') {
        event.respondWith(
            caches.match(req).then(function (cached) {
                if (cached) {
                    return cached;
                }
                return fetch(req).then(function (resp) {
                    if (resp.ok) {
                        var copy = resp.clone();
                        caches.open(STATIC_CACHE).then(function (cache) { cache.put(req, copy); });
                    }
                    return resp;
                });
            })
        );
        return;
    }

    // Writing module: network-only, never cache HTML or API responses.
    if (path.indexOf(WRITING_PREFIX) === 0) {
        event.respondWith(
            fetch(req).catch(function () {
                return caches.match(OFFLINE_URL);
            })
        );
        return;
    }

    if (req.mode === 'navigate') {
        if (isPublicReadingPage(path)) {
            // Public reading pages: stale-while-revalidate.
            event.respondWith(
                caches.match(req).then(function (cached) {
                    var network = fetch(req).then(function (resp) {
                        if (resp.ok) {
                            var copy = resp.clone();
                            caches.open(PAGE_CACHE).then(function (cache) { cache.put(req, copy); });
                        }
                        return resp;
                    }).catch(function () { return cached; });
                    return cached || network;
                }).then(function (resp) {
                    return resp || caches.match(OFFLINE_URL);
                })
            );
            return;
        }
        // Other app pages (admin, profile, login): network-first; only when the
        // network fails fall back to the cached copy, else the offline page.
        event.respondWith(
            fetch(req).then(function (resp) {
                if (resp.ok) {
                    var copy = resp.clone();
                    caches.open(PAGE_CACHE).then(function (cache) { cache.put(req, copy); });
                }
                return resp;
            }).catch(function () {
                return caches.match(req).then(function (cached) {
                    return cached || caches.match(OFFLINE_URL);
                });
            })
        );
        return;
    }

    // Remaining GETs (XHR/fetch): network with cache fallback.
    event.respondWith(
        fetch(req).catch(function () {
            return caches.match(req);
        })
    );
});
