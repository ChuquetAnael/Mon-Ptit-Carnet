/**
 * Script commun à toutes les pages (chargé par includes/head.php) :
 *  - installation du service worker (application hors-ligne) ;
 *  - indicateur « hors ligne » ;
 *  - thème « plein soleil » ;
 *  - surveillance d'une station hydrométrique (alerte crue : bandeau, vibreur, notification).
 */
(function () {
    'use strict';
    const stockage = {
        lire(cle) { try { return JSON.parse(localStorage.getItem(cle)); } catch (e) { return null; } },
        ecrire(cle, v) { try { localStorage.setItem(cle, JSON.stringify(v)); } catch (e) {} },
        oublier(cle) { try { localStorage.removeItem(cle); } catch (e) {} },
    };
    const echapper = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const connecte = () => !!document.querySelector('.barre-pouce'); // la barre du bas n'existe que pour un membre connecté

    // ------------------------------------------------------------ Service worker
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname))) {
        navigator.serviceWorker.register('sw.js').catch(() => {});
    }

    // ------------------------------------------------------------ Déconnexion : on efface la carte et les réglages du téléphone
    if (new URLSearchParams(location.search).has('deconnecte')) {
        ['mpc-station', 'mpc-alerte', 'mpc-carte-meta'].forEach(stockage.oublier);
        if (window.caches) caches.delete('mpc-carte').catch(() => {});
        if (navigator.serviceWorker && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage('vider-carte');
    }

    // ------------------------------------------------------------ Hors ligne
    function majHorsLigne() {
        let pastille = document.getElementById('hors-ligne');
        if (navigator.onLine) { if (pastille) pastille.remove(); return; }
        if (!pastille) {
            pastille = document.createElement('div');
            pastille.id = 'hors-ligne';
            pastille.className = 'hors-ligne-bande';
            pastille.setAttribute('role', 'status');
            pastille.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">cloud_off</span>Hors ligne · ta carte de pêche reste disponible';
            document.body.prepend(pastille);
        }
    }
    window.addEventListener('online', majHorsLigne);
    window.addEventListener('offline', majHorsLigne);

    // ------------------------------------------------------------ Thème plein soleil
    function basculerTheme() {
        const soleil = document.documentElement.dataset.theme !== 'soleil';
        if (soleil) document.documentElement.dataset.theme = 'soleil'; else delete document.documentElement.dataset.theme;
        try { localStorage.setItem('mpc-theme', soleil ? 'soleil' : 'normal'); } catch (e) {}
        return soleil;
    }

    // ------------------------------------------------------------ Surveillance crue
    const INTERVALLE = 10 * 60 * 1000;
    let minuterie = null;

    function bandeau(etat) {
        let b = document.getElementById('bandeau-crue');
        if (!etat || !etat.niveau) { if (b) b.remove(); return; }
        const masque = stockage.lire('mpc-alerte-masquee');
        if (masque && masque.niveau >= etat.niveau && Date.now() - masque.t < 3 * 3600 * 1000) return;
        if (!b) {
            b = document.createElement('div');
            b.id = 'bandeau-crue';
            b.setAttribute('role', 'alert');
            document.body.prepend(b);
        }
        const station = stockage.lire('mpc-station') || {};
            b.className = 'bandeau-crue niveau-' + etat.niveau;
        b.innerHTML = `<span class="material-symbols-rounded" aria-hidden="true">flood</span>
            <a href="meteo.php?vue=riviere" class="bandeau-texte"><strong>${echapper(etat.titre)}</strong>
            <small>${echapper(station.nom || '')}${station.nom && etat.message ? ' · ' : ''}${echapper(etat.message)}</small></a>
            <button type="button">Compris</button>`;
        b.querySelector('button').addEventListener('click', () => {
            stockage.ecrire('mpc-alerte-masquee', { niveau: etat.niveau, t: Date.now() });
            b.remove();
        });
    }

    async function notifier(etat) {
        const derniere = stockage.lire('mpc-alerte') || {};
        // Une seule notification par niveau d'alerte (et à nouveau si la situation s'aggrave)
        if (etat.niveau < 2 || (derniere.niveau >= etat.niveau && Date.now() - derniere.t < 6 * 3600 * 1000)) return;
        stockage.ecrire('mpc-alerte', { niveau: etat.niveau, t: Date.now() });
        if (navigator.vibrate) navigator.vibrate([400, 150, 400, 150, 800]);
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        const titre = etat.niveau >= 3 ? 'Danger : montée brutale des eaux' : 'Montée rapide des eaux';
        const options = { body: etat.message || 'Vérifie le niveau de la rivière.', icon: 'asset/icones/icone-192.png', badge: 'asset/icones/icone-192.png', tag: 'mpc-crue', renotify: true, vibrate: [400, 150, 400], data: { url: 'meteo.php?vue=riviere' } };
        try {
            const reg = await navigator.serviceWorker?.getRegistration();
            if (reg) await reg.showNotification(titre, options); else new Notification(titre, options);
        } catch (e) {}
    }

    async function verifierStation(forcer) {
        const station = stockage.lire('mpc-station');
        if (!station || !station.code || !navigator.onLine) return null;
        const precedent = stockage.lire('mpc-station-etat');
        if (!forcer && precedent && Date.now() - precedent.t < INTERVALLE - 5000) { bandeau(precedent.etat); return precedent.etat; }
        try {
            const r = await fetch('fetch_riviere.php?action=surveillance&station=' + encodeURIComponent(station.code), { credentials: 'same-origin' });
            if (!r.ok) return null;
            const etat = await r.json();
            if (etat.error) return null;
            stockage.ecrire('mpc-station-etat', { t: Date.now(), etat });
            bandeau(etat);
            notifier(etat);
            document.dispatchEvent(new CustomEvent('mpc:surveillance', { detail: etat }));
            return etat;
        } catch (e) { return null; }
    }

    function demarrerSurveillance() {
        if (minuterie) clearInterval(minuterie);
        if (!stockage.lire('mpc-station')) return;
        verifierStation(false);
        minuterie = setInterval(() => { if (document.visibilityState === 'visible') verifierStation(true); }, INTERVALLE);
    }
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && connecte()) verifierStation(false); });

    window.MPC = {
        basculerTheme,
        stationSurveillee: () => stockage.lire('mpc-station'),
        surveillerStation(code, nom) {
            stockage.ecrire('mpc-station', { code, nom });
            stockage.oublier('mpc-station-etat');
            stockage.oublier('mpc-alerte');
            demarrerSurveillance();
            return verifierStation(true);
        },
        arreterSurveillance() {
            ['mpc-station', 'mpc-station-etat', 'mpc-alerte'].forEach(stockage.oublier);
            if (minuterie) clearInterval(minuterie);
            bandeau(null);
        },
        async demanderNotifications() {
            if (!('Notification' in window)) return 'indisponible';
            if (Notification.permission === 'default') return Notification.requestPermission();
            return Notification.permission;
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        majHorsLigne();
        if (connecte()) demarrerSurveillance();
        // Boutons de bascule du thème (paramètres, carte de pêche)
        document.querySelectorAll('[data-action="theme"]').forEach(btn => {
            btn.setAttribute('aria-pressed', document.documentElement.dataset.theme === 'soleil' ? 'true' : 'false');
            btn.addEventListener('click', () => btn.setAttribute('aria-pressed', basculerTheme() ? 'true' : 'false'));
        });
    });
})();
