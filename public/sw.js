/*
 * Service worker de Waumini.
 *
 * - Met en cache l'enveloppe de l'application (styles, scripts, polices, icônes)
 *   pour un démarrage rapide, même sur une connexion lente.
 * - Les pages restent toujours chargées depuis le serveur (données à jour) ;
 *   sans réseau, on affiche la page « hors ligne ».
 * La saisie hors connexion du dimanche s'ajoutera ici avec le module Finances.
 */
const VERSION = 'waumini-v1';
const SHELL = ['/hors-ligne', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/favicon.svg'];

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
        event.respondWith(fetch(request).catch(() => caches.match('/hors-ligne')));
        return;
    }

    // Fichiers compilés (noms versionnés), polices et icônes : cache d'abord.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
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
