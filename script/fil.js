// Fil d'actualité : « Voir plus » charge la suite sans recharger la page (le lien fonctionne aussi sans JavaScript).
(function () {
    'use strict';
    const bouton = document.getElementById('fil-plus');
    const fil = document.getElementById('fil');
    if (!bouton || !fil) return;
    bouton.addEventListener('click', async e => {
        e.preventDefault();
        if (bouton.getAttribute('aria-busy') === 'true') return;
        bouton.setAttribute('aria-busy', 'true');
        const texte = bouton.textContent;
        bouton.textContent = 'Chargement…';
        try {
            const r = await fetch('fil.php?partiel=1&avant=' + encodeURIComponent(bouton.dataset.suivant), { credentials: 'same-origin' });
            const j = await r.json();
            fil.insertAdjacentHTML('beforeend', j.html || '');
            if (j.suivant) {
                bouton.dataset.suivant = j.suivant;
                bouton.href = 'fil.php?avant=' + encodeURIComponent(j.suivant);
                bouton.textContent = texte;
            } else {
                bouton.parentElement.remove();
            }
        } catch (err) {
            bouton.textContent = 'Réessayer';
        }
        bouton.removeAttribute('aria-busy');
    });
})();
