/**
 * Visionneuse plein écran des photos d'une prise (profil, détail de session, fil d'actualité).
 * Déclencheur : tout élément portant data-visionneuse et data-photos='[{"src": "...", "titre": "..."}]'
 * (data-index optionnel pour la photo de départ).
 * Glisser à gauche / à droite, flèches du clavier, Échap ou bouton « retour » du téléphone pour fermer.
 */
(function () {
    'use strict';
    let boite = null, photos = [], index = 0, declencheur = null, etatHistorique = false, departX = null;

    function creer() {
        boite = document.createElement('div');
        boite.className = 'visionneuse';
        boite.setAttribute('role', 'dialog');
        boite.setAttribute('aria-modal', 'true');
        boite.setAttribute('aria-label', 'Photos de la prise');
        boite.hidden = true;
        boite.innerHTML = `
            <button type="button" class="visionneuse-fermer" aria-label="Fermer"><span class="material-symbols-rounded" aria-hidden="true">close</span></button>
            <button type="button" class="visionneuse-nav precedente" aria-label="Photo précédente"><span class="material-symbols-rounded" aria-hidden="true">chevron_left</span></button>
            <figure class="visionneuse-cadre">
                <img alt="">
                <figcaption class="visionneuse-legende"><span class="titre"></span><span class="compteur"></span></figcaption>
            </figure>
            <button type="button" class="visionneuse-nav suivante" aria-label="Photo suivante"><span class="material-symbols-rounded" aria-hidden="true">chevron_right</span></button>`;
        document.body.appendChild(boite);
        boite.querySelector('.visionneuse-fermer').addEventListener('click', fermer);
        boite.querySelector('.precedente').addEventListener('click', () => aller(index - 1));
        boite.querySelector('.suivante').addEventListener('click', () => aller(index + 1));
        boite.addEventListener('click', e => { if (e.target === boite || e.target.classList.contains('visionneuse-cadre')) fermer(); });
        boite.addEventListener('pointerdown', e => { departX = e.clientX; });
        boite.addEventListener('pointerup', e => {
            if (departX === null) return;
            const dx = e.clientX - departX;
            departX = null;
            if (Math.abs(dx) > 50) aller(index + (dx < 0 ? 1 : -1));
        });
    }

    function afficher() {
        const p = photos[index];
        const img = boite.querySelector('img');
        img.src = p.src;
        img.alt = p.titre || 'Photo de la prise';
        boite.querySelector('.titre').textContent = p.titre || '';
        boite.querySelector('.compteur').textContent = photos.length > 1 ? `${index + 1} / ${photos.length}` : '';
        boite.querySelectorAll('.visionneuse-nav').forEach(b => { b.hidden = photos.length < 2; });
        if (photos[index + 1]) { const pre = new Image(); pre.src = photos[index + 1].src; }
    }

    function aller(i) {
        if (photos.length < 2) return;
        index = (i + photos.length) % photos.length;
        afficher();
    }

    function ouvrir(liste, depart, source) {
        if (!liste.length) return;
        if (!boite) creer();
        photos = liste;
        index = Math.max(0, Math.min(liste.length - 1, depart || 0));
        declencheur = source;
        afficher();
        boite.hidden = false;
        document.documentElement.classList.add('visionneuse-ouverte');
        boite.querySelector('.visionneuse-fermer').focus();
        try { history.pushState({ visionneuse: true }, ''); etatHistorique = true; } catch (e) { etatHistorique = false; }
    }

    function fermer(depuisHistorique) {
        if (!boite || boite.hidden) return;
        boite.hidden = true;
        boite.querySelector('img').removeAttribute('src');
        document.documentElement.classList.remove('visionneuse-ouverte');
        if (declencheur && declencheur.focus) declencheur.focus();
        if (etatHistorique && depuisHistorique !== true) { etatHistorique = false; history.back(); }
        etatHistorique = false;
    }

    document.addEventListener('click', e => {
        const el = e.target.closest('[data-visionneuse]');
        if (!el) return;
        let liste = [];
        try { liste = JSON.parse(el.dataset.photos || '[]'); } catch (err) { liste = []; }
        if (!liste.length) return;
        e.preventDefault();
        ouvrir(liste, parseInt(el.dataset.index || '0', 10), el);
    });
    document.addEventListener('keydown', e => {
        if (!boite || boite.hidden) return;
        if (e.key === 'Escape') fermer();
        else if (e.key === 'ArrowLeft') aller(index - 1);
        else if (e.key === 'ArrowRight') aller(index + 1);
        else if (e.key === 'Tab') { // la tabulation reste dans la visionneuse
            const f = [...boite.querySelectorAll('button:not([hidden])')];
            const i = f.indexOf(document.activeElement);
            e.preventDefault();
            f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length].focus();
        }
    });
    window.addEventListener('popstate', () => { if (boite && !boite.hidden) fermer(true); });

    window.Visionneuse = { ouvrir, fermer };
})();
