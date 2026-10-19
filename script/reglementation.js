/**
 * Garde-fou réglementaire côté navigateur (même logique que includes/reglementation.php).
 * Les règles applicables sont calculées par le serveur pour la date et le lieu de la session,
 * puis évaluées ici à chaque modification : l'alerte apparaît immédiatement, même sans réseau.
 *
 * Données attendues (déclarées par la page) :
 *   REGLES   : règles résolues (regles_resolues)
 *   CONTEXTE : { reserves: [...], libelle: '...' }
 *   GARDES   : poissons déjà gardés aujourd'hui, par espèce { idEspece: nombre }
 */
const Reglementation = (() => {
    const echapper = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function totalQuota(regles, cle, gardes) {
        const especes = new Set();
        regles.forEach(r => { if (r.cle_quota === cle && r.espece !== null) especes.add(r.espece); });
        if (cle.startsWith('espece:')) especes.add(parseInt(cle.slice(7), 10));
        let total = 0;
        especes.forEach(id => { total += gardes[id] || 0; });
        return total;
    }

    /** Alertes d'une prise : [{ niveau, texte, bloquant }] */
    function controler(regles, contexte, prise, gardes) {
        const alertes = [];
        const garde = !prise.relache;
        const quantite = Math.max(1, parseInt(prise.quantite, 10) || 1);
        const quotasVus = new Set();

        (contexte.reserves || []).forEach(res => alertes.push({
            niveau: garde ? 'danger' : 'warning',
            texte: `Zone de réserve « ${res.nom} » : ${res.regle}`,
            bloquant: garde,
        }));

        regles.forEach(r => {
            if (r.espece !== null && r.espece !== prise.espece) return;
            const si = r.condition ? `Si tu pêches ${r.condition} : ` : '';
            const certain = r.condition === null;
            const Nom = r.nom_espece || 'Toutes espèces';
            const nom = r.nom_espece ? r.nom_espece.toLowerCase() : 'cette espèce';

            if (r.fermee) {
                if (r.nature === 'no_kill') {
                    if (garde) alertes.push({ niveau: certain ? 'danger' : 'warning', texte: `${si}${Nom} : remise à l'eau obligatoire ${r.periode}.`, bloquant: certain });
                } else {
                    alertes.push({
                        niveau: garde && certain ? 'danger' : 'warning',
                        texte: si + (r.espece === null ? 'Pêche fermée ' : `${Nom} : pêche interdite `) + r.periode + '.'
                            + (r.note && r.espece === null ? ' ' + r.note : '')
                            + (garde ? '' : " Tu l'as relâché : c'est la bonne réaction."),
                        bloquant: garde && certain,
                    });
                }
            }

            if (garde && r.taille_min !== null) {
                const taille = prise.taille;
                if (taille === null || taille === '' || isNaN(taille)) {
                    alertes.push({ niveau: 'info', texte: `${si}${Nom} : taille minimale ${r.taille_min} cm. Mesure-le avant de le garder.`, bloquant: false });
                } else if (parseFloat(taille) < r.taille_min) {
                    alertes.push({ niveau: certain ? 'danger' : 'warning', texte: `${si}${Nom} sous la taille légale : ${parseFloat(taille)} cm pour ${r.taille_min} cm minimum.`, bloquant: certain });
                }
            }

            if (garde && r.quota !== null && !quotasVus.has(r.cle_quota)) {
                quotasVus.add(r.cle_quota);
                const total = totalQuota(regles, r.cle_quota, gardes) + quantite;
                if (total > r.quota) {
                    const quoi = r.groupe ? `des ${r.groupe}` : `de ${nom}`;
                    alertes.push({ niveau: certain ? 'danger' : 'warning', texte: `${si}Quota ${quoi} dépassé : ${total} gardés aujourd'hui pour ${r.quota} autorisés par jour.`, bloquant: certain });
                }
            }

            if (garde && r.marquage && certain) {
                alertes.push({ niveau: 'info', texte: `Marquage obligatoire de ${nom} : coupe la partie inférieure de la nageoire caudale.`, bloquant: false });
            }
            if (garde && r.declaration && certain) {
                alertes.push({ niveau: 'info', texte: 'Déclaration numérique de la capture obligatoire sous 24 h (application RecFishing).', bloquant: false });
            }
            if (r.note && r.taille_min === null && r.quota === null && r.periode === null && !r.marquage) {
                alertes.push({ niveau: 'info', texte: si + r.note, bloquant: false });
            }
        });

        const uniques = new Map();
        alertes.forEach(a => uniques.set(a.texte, a));
        return [...uniques.values()];
    }

    const ICONES = { danger: 'gpp_bad', warning: 'warning', info: 'info' };

    /**
     * Affiche les alertes dans un conteneur.
     * nomConfirmation : nom du champ « je confirme » (envoyé au serveur), boutonRelache : callback « Je le relâche ».
     */
    function afficher(conteneur, alertes, nomConfirmation, confirmeAvant, boutonRelache) {
        if (!conteneur) return;
        const bloquantes = alertes.filter(a => a.bloquant);
        if (!alertes.length) { conteneur.innerHTML = ''; return; }
        conteneur.innerHTML = alertes.map(a => `
            <div class="regle-alerte regle-${a.niveau}" role="${a.niveau === 'danger' ? 'alert' : 'status'}">
                <span class="material-symbols-rounded" aria-hidden="true">${ICONES[a.niveau]}</span>
                <span>${echapper(a.texte)}</span>
            </div>`).join('')
            + (bloquantes.length ? `
            <div class="regle-actions">
                <button type="button" class="btn btn-success btn-sm rounded-pill fw-semibold btn-relacher">
                    <span class="material-symbols-rounded align-middle me-1" style="font-size:18px">water</span>Je le relâche
                </button>
                <label class="regle-confirme">
                    <input type="checkbox" class="form-check-input" name="${echapper(nomConfirmation)}" value="1" ${confirmeAvant ? 'checked' : ''}>
                    Je garde ce poisson : la règle locale est différente ou ma saisie est fausse
                </label>
            </div>` : '')
            + '<p class="regle-mention">Indicatif : seuls les arrêtés officiels font foi.</p>';
        const btn = conteneur.querySelector('.btn-relacher');
        if (btn && boutonRelache) btn.addEventListener('click', boutonRelache);
    }

    return { controler, afficher };
})();
