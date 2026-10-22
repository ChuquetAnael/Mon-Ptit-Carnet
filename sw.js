/**
 * Service worker de Mon P'tit Carnet.
 * - Fichiers de l'interface (CSS, JS, icônes, polices, bibliothèques) : servis depuis le cache, mis à jour en arrière-plan.
 * - Pages : toujours depuis le réseau (données fraîches et privées) ; sans réseau, page « hors-ligne ».
 * - Carte de pêche : la page et le fichier sont gardés dans un cache dédié pour s'afficher en zone blanche.
 *   Ce cache est vidé à la déconnexion.
 */
const VERSION = 'v4';
const CACHE_STATIQUE = 'mpc-statique-' + VERSION;
const CACHE_CARTE = 'mpc-carte'; // non versionné : la carte reste disponible après une mise à jour de l'application

const PRECACHE = [
    'offline.html',
    'css/style.css',
    'css/app.css?v=4',
    'script/app.js?v=4',
    'script/vendor/qrcode.js',
    'manifest.webmanifest',
    'asset/icones/icone-192.png',
];
const HOTES_CDN = ['cdn.jsdelivr.net', 'unpkg.com', 'fonts.googleapis.com', 'fonts.gstatic.com'];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE_STATIQUE).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(cles => Promise.all(cles.filter(c => c.startsWith('mpc-statique-') && c !== CACHE_STATIQUE).map(c => caches.delete(c))))
            .then(() => self.clients.claim())
    );
});

/** Réseau d'abord, copie dans le cache ; sinon la copie en cache. */
async function reseauPuisCache(requete, nomCache) {
    const cache = await caches.open(nomCache);
    try {
        const reponse = await fetch(requete);
        if (reponse.ok && !reponse.redirected) cache.put(requete, reponse.clone());
        return reponse;
    } catch (e) {
        const enCache = await cache.match(requete, { ignoreSearch: false });
        if (enCache) return enCache;
        throw e;
    }
}

/** Cache d'abord (réponse immédiate), mise à jour en arrière-plan. */
async function cachePuisMaj(requete) {
    const cache = await caches.open(CACHE_STATIQUE);
    const enCache = await cache.match(requete);
    const reseau = fetch(requete).then(reponse => {
        if (reponse.ok || reponse.type === 'opaque') cache.put(requete, reponse.clone());
        return reponse;
    }).catch(() => enCache);
    return enCache || reseau;
}

self.addEventListener('fetch', event => {
    const requete = event.request;
    if (requete.method !== 'GET') return;
    const url = new URL(requete.url);
    const memeOrigine = url.origin === self.location.origin;
    const fichier = url.pathname.split('/').pop();

    // Carte de pêche (page + fichier) : disponible hors-ligne
    if (memeOrigine && (fichier === 'carte_peche.php' || fichier === 'carte_fichier.php')) {
        event.respondWith(reseauPuisCache(requete, CACHE_CARTE).catch(async () => {
            if (requete.mode === 'navigate') {
                const page = await caches.match('carte_peche.php', { cacheName: CACHE_CARTE, ignoreSearch: true });
                return page || caches.match('offline.html');
            }
            return Response.error();
        }));
        return;
    }

    // Autres pages : réseau uniquement (contenu privé et toujours à jour), page hors-ligne en secours
    if (requete.mode === 'navigate') {
        event.respondWith(fetch(requete).catch(() => caches.match('offline.html')));
        return;
    }

    // Interface et bibliothèques
    const statique = memeOrigine && /\.(css|js|png|webp|jpe?g|svg|woff2?|webmanifest)$/i.test(url.pathname);
    if (statique || HOTES_CDN.includes(url.hostname)) {
        event.respondWith(cachePuisMaj(requete));
    }
});

self.addEventListener('message', event => {
    if (event.data === 'vider-carte') event.waitUntil(caches.delete(CACHE_CARTE));
});

// Clic sur une notification d'alerte crue : ouvre la page rivière
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const cible = event.notification.data && event.notification.data.url ? event.notification.data.url : 'meteo.php?vue=riviere';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(fenetres => {
        for (const f of fenetres) {
            if ('focus' in f) { f.navigate(cible); return f.focus(); }
        }
        return self.clients.openWindow(cible);
    }));
});
