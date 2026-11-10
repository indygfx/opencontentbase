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

const VERSION = 'v5'; // prompt 28 (PWA stage 2: per-story offline precache) changes sw.js/app.js/offline.html
const STATIC_CACHE = 'ocb-static-' + VERSION;
const PAGE_CACHE = 'ocb-pages-' + VERSION;
const OFFLINE_URL = '/offline.html';
// PWA stage 2: dedicated cache for per-story offline copies. It survives
// VERSION bumps on activate (kept explicitly below) so single stories can be
// removed without touching the static/page caches.
const STORY_CACHE = 'offline-stories-v1';
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
                    if (key !== STATIC_CACHE && key !== PAGE_CACHE && key !== STORY_CACHE) {
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

// PWA stage 2 messages from the page (navigator.serviceWorker.controller):
// PRECACHE_STORY { urls } fetches every URL (GET, credentials, cache:'reload')
// into the story cache and replies PRECACHE_DONE { ok, failed: [...] } via
// event.source; failures are non-fatal and reported. REMOVE_STORY deletes the
// URLs again and replies REMOVE_DONE.
self.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.type === 'PRECACHE_STORY') {
        event.waitUntil(
            caches.open(STORY_CACHE).then(function (cache) {
                var failed = [];
                return Promise.all((data.urls || []).map(function (u) {
                    return fetch(u, { method: 'GET', credentials: 'include', cache: 'reload' })
                        .then(function (resp) {
                            if (resp.ok) {
                                return cache.put(u, resp);
                            }
                            failed.push(u);
                        })
                        .catch(function () { failed.push(u); });
                })).then(function () {
                    event.source.postMessage({ type: 'PRECACHE_DONE', ok: failed.length === 0, failed: failed });
                });
            })
        );
        return;
    }
    if (data.type === 'REMOVE_STORY') {
        event.waitUntil(
            caches.open(STORY_CACHE).then(function (cache) {
                return Promise.all((data.urls || []).map(function (u) { return cache.delete(u); }));
            }).then(function () {
                event.source.postMessage({ type: 'REMOVE_DONE' });
            })
        );
    }
});

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

    // Writing module + avatars: pages precached per story (stage 2) behave like
    // other cached read pages - stale-while-revalidate (serve the cached copy,
    // refresh it in the background on the next online visit). Everything else
    // under /writing stays network-only, never cached; on failure the offline
    // page is served. /writing itself stays network-only - the offline entry
    // point is the offline library page, not the index.
    if (path.indexOf(WRITING_PREFIX) === 0 || path.indexOf('/avatars/') === 0) {
        event.respondWith(
            caches.open(STORY_CACHE).then(function (cache) {
                return cache.match(req).then(function (hit) {
                    if (hit) {
                        fetch(req).then(function (resp) {
                            if (resp.ok) {
                                cache.put(req, resp.clone());
                            }
                        }).catch(function () { /* offline: keep serving the cached copy */ });
                        return hit;
                    }
                    return fetch(req).catch(function () {
                        return caches.match(OFFLINE_URL);
                    });
                });
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
