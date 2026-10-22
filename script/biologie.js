/**
 * Garde-fou biologique (même calcul que includes/biologie.php) :
 * refuse pendant la saisie les tailles et poids impossibles pour l'espèce.
 * Données attendues : LIMITES_BIO = { idEspece: [tailleMaxCm|null, poidsMaxKg|null] }
 */
const GardeBio = (function () {
    'use strict';
    const TOLERANCE = 0.15, TAILLE_ABS = 700, POIDS_ABS = 999.99, EMBONPOINT = 2.5;
    const LOURD_BLOQUANT = 3.0, LOURD_ALERTE = 1.8, LEGER_BLOQUANT = 4.0, LEGER_ALERTE = 2.5;
    const MARGE_KG = 0.05, POIDS_MIN_CONTROLE = 0.1;

    const nb = (n, dec = 2) => Number(n).toLocaleString('fr-FR', { maximumFractionDigits: dec });
    const echapper = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const lireNombre = v => {
        const n = parseFloat(String(v ?? '').replace(',', '.'));
        return Number.isFinite(n) && n > 0 ? n : null;
    };

    function plafonds(tmax, pmax) {
        const t = tmax ? Math.min(TAILLE_ABS, Math.round(tmax * (1 + TOLERANCE))) : TAILLE_ABS;
        let p;
        if (pmax) p = Math.round(pmax * (1 + TOLERANCE) * 100) / 100;
        else if (tmax) p = Math.round(EMBONPOINT * Math.pow(t, 3) / 100000 * 100) / 100;
        else p = POIDS_ABS;
        return { taille: t, poids: Math.min(POIDS_ABS, Math.max(0.01, p)) };
    }

    /** @returns {Array<{niveau, texte, bloquant, champ}>} */
    function controler(limites, idEspece, nom, tailleBrute, poidsBrut) {
        const [tmax, pmax] = (limites && limites[idEspece]) || [null, null];
        const taille = lireNombre(tailleBrute), poids = lireNombre(poidsBrut);
        const plaf = plafonds(tmax, pmax);
        const alertes = [];
        let bloque = false;
        nom = nom || 'inconnue';

        if (taille !== null) {
            if (taille > plaf.taille) {
                bloque = true;
                alertes.push({ niveau: 'danger', bloquant: true, champ: 'taille', texte: tmax
                    ? `${nb(taille, 0)} cm, c'est impossible : l'espèce ${nom} ne dépasse pas environ ${nb(tmax, 0)} cm. La taille se note en centimètres.`
                    : `${nb(taille, 0)} cm, c'est une taille impossible. La taille se note en centimètres.` });
            } else if (tmax && taille > tmax) {
                alertes.push({ niveau: 'warning', bloquant: false, champ: 'taille', texte: `${nb(taille, 0)} cm : ce serait un record pour l'espèce (maximum connu d'environ ${nb(tmax, 0)} cm). Vérifie ta mesure.` });
            }
        }
        if (poids !== null) {
            if (poids > plaf.poids) {
                bloque = true;
                alertes.push({ niveau: 'danger', bloquant: true, champ: 'poids', texte: pmax
                    ? `${nb(poids)} kg, c'est impossible : l'espèce ${nom} ne dépasse pas environ ${nb(pmax)} kg. Le poids se note en kilos (850 g = 0,85).`
                    : `${nb(poids)} kg, c'est impossible pour l'espèce ${nom}. Le poids se note en kilos (850 g = 0,85).` });
            } else if (pmax && poids > pmax) {
                alertes.push({ niveau: 'warning', bloquant: false, champ: 'poids', texte: `${nb(poids)} kg : ce serait un record pour l'espèce (maximum connu d'environ ${nb(pmax)} kg). Vérifie ta pesée.` });
            }
        }
        if (!bloque && taille !== null && poids !== null && tmax && pmax) {
            const attendu = pmax * Math.pow(taille / tmax, 3);
            const txt = nb(attendu, attendu < 1 ? 2 : 1);
            if (poids > attendu * LOURD_BLOQUANT + MARGE_KG) {
                alertes.push({ niveau: 'danger', bloquant: true, champ: 'rapport', texte: `${nb(poids)} kg pour ${nb(taille, 0)} cm, c'est bien trop lourd pour l'espèce ${nom} (environ ${txt} kg attendus à cette taille). Vérifie la taille et le poids.` });
            } else if (poids > attendu * LOURD_ALERTE + MARGE_KG) {
                alertes.push({ niveau: 'warning', bloquant: false, champ: 'rapport', texte: `Poids élevé pour ${nb(taille, 0)} cm (environ ${txt} kg habituellement). Vérifie ta pesée.` });
            } else if (attendu >= POIDS_MIN_CONTROLE && poids < attendu / LEGER_BLOQUANT) {
                alertes.push({ niveau: 'danger', bloquant: true, champ: 'rapport', texte: `${nb(poids)} kg pour ${nb(taille, 0)} cm, c'est bien trop léger pour l'espèce ${nom} (environ ${txt} kg attendus à cette taille). Le poids se note en kilos, la taille en centimètres.` });
            } else if (attendu >= POIDS_MIN_CONTROLE && poids < attendu / LEGER_ALERTE) {
                alertes.push({ niveau: 'warning', bloquant: false, champ: 'rapport', texte: `Poids faible pour ${nb(taille, 0)} cm (environ ${txt} kg habituellement). Vérifie ta pesée.` });
            }
        }
        return alertes;
    }

    /** Affiche les alertes et marque les champs concernés. */
    function afficher(zone, alertes, champTaille, champPoids) {
        if (!zone) return;
        zone.innerHTML = alertes.map(a => `
            <div class="bio-alerte bio-${a.niveau}" role="${a.bloquant ? 'alert' : 'status'}">
                <span class="material-symbols-rounded" aria-hidden="true">${a.bloquant ? 'block' : 'straighten'}</span>
                <span>${echapper(a.texte)}</span>
            </div>`).join('');
        const bloqueTaille = alertes.some(a => a.bloquant && (a.champ === 'taille' || a.champ === 'rapport'));
        const bloquePoids = alertes.some(a => a.bloquant && (a.champ === 'poids' || a.champ === 'rapport'));
        if (champTaille) { champTaille.classList.toggle('is-invalid', bloqueTaille); champTaille.setAttribute('aria-invalid', bloqueTaille ? 'true' : 'false'); }
        if (champPoids) { champPoids.classList.toggle('is-invalid', bloquePoids); champPoids.setAttribute('aria-invalid', bloquePoids ? 'true' : 'false'); }
        return alertes.some(a => a.bloquant);
    }

    return { controler, afficher, plafonds };
})();
