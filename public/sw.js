/*
 * Service worker de Waumini.
 *
 * - Met en cache l'enveloppe de l'application (styles, scripts, polices, icônes)
 *   pour un démarrage rapide, même sur une connexion lente.
 * - Les pages restent toujours chargées depuis le serveur (données à jour) ;
 *   sans réseau, on affiche la page « hors ligne ».
 * - Affiche les nouveautés reçues (Web Push) et ouvre la bonne page au toucher.
 */
const VERSION = 'waumini-v3';
// Adresse de Waumini (la racine du site, ou un sous-dossier comme /waumini/).
const BASE = new URL('./', self.location).pathname;
const at = (path) => BASE + path;
const SHELL = [at('hors-ligne'), at('manifest.webmanifest'), at('icons/icon-192.png'), at('icons/favicon.svg')];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Pages : réseau d'abord, page hors ligne en secours.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(at('hors-ligne'))));
        return;
    }

    // Fichiers compilés (noms versionnés), polices et icônes : cache d'abord.
    if (url.pathname.startsWith(at('build/')) || url.pathname.startsWith(at('icons/'))) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(request, copy));
                }
                return response;
            })),
        );
    }
});

// Une nouveauté arrive : on l'affiche, et le nombre de nouveautés passe sur l'icône.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: event.data?.text() };
    }
    const shown = self.registration.showNotification(data.title || 'Waumini', {
        body: data.body || '',
        icon: at('icons/icon-192.png'),
        badge: at('icons/icon-192.png'),
        tag: data.tag,
        renotify: Boolean(data.tag),
        data: { url: data.url || at('nouveautes') },
    });
    const badge = 'setAppBadge' in self.navigator && data.count ? self.navigator.setAppBadge(data.count) : Promise.resolve();
    event.waitUntil(Promise.all([shown, badge.catch(() => {})]));
});

// Au toucher : on revient dans l'application déjà ouverte, sinon on l'ouvre, à la page concernée.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || at('nouveautes'), self.location.origin).href;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
            if (open) return open.focus().then((client) => client.navigate(url)).catch(() => self.clients.openWindow(url));
            return self.clients.openWindow(url);
        }),
    );
});
