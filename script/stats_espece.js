// Statistiques détaillées d'une espèce (page Statistiques > Bilan par espèce).
// Données préparées par BDD/BDD_stat.php : STATS_ESPECES[id] et ESPECES_BILAN[id].
(function () {
    'use strict';
    const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    const echapper = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nb = (v, d = 1) => Number(v).toLocaleString('fr-FR', { maximumFractionDigits: d });
    let graphique = null, modal = null;

    function tuile(titre, valeur, detail) {
        return `<div class="tuile-stat"><span class="tuile-titre">${echapper(titre)}</span><strong>${valeur}</strong>${detail ? `<small>${echapper(detail)}</small>` : ''}</div>`;
    }

    function ouvrir(id) {
        const d = STATS_ESPECES[id], e = ESPECES_BILAN[id];
        if (!d || !e) return;
        const titre = document.getElementById('modal-espece-titre');
        titre.innerHTML = (e.icone ? `<img src="${echapper(e.icone)}" alt="" width="36" height="36" style="object-fit:contain">` : '') + echapper(e.nom);

        const pb = d.pb;
        const photosPb = pb && pb.photos.length ? JSON.stringify(pb.photos.map(src => ({ src, titre: `${e.nom} · ${pb.taille} cm` }))) : null;
        const meilleursMois = d.mois.map((n, i) => [n, i]).filter(([n]) => n > 0).sort((a, b) => b[0] - a[0]).slice(0, 2).map(([, i]) => MOIS[i]);
        const meilleureHeure = d.heures.reduce((best, n, h) => (n > (d.heures[best] || 0) ? h : best), 0);

        let html = '<div class="grille-tuiles">';
        html += tuile('Poissons', nb(d.poissons, 0), d.lignes > 1 ? `${d.lignes} prises enregistrées` : '');
        html += tuile('No-kill', `${d.nokill} %`, '');
        html += tuile('Taille moyenne', d.taille_moy !== null ? `${nb(d.taille_moy)} cm` : '–', d.nb_mesures ? `sur ${d.nb_mesures} poisson(s) mesuré(s)` : 'aucune mesure');
        html += tuile('Poids moyen', d.poids_moy !== null ? `${nb(d.poids_moy, 2)} kg` : '–', d.nb_pesees ? `sur ${d.nb_pesees} poisson(s) pesé(s)` : 'aucune pesée');
        html += '</div>';

        if (pb) {
            html += `<div class="record-espece">
                <span class="material-symbols-rounded" aria-hidden="true">emoji_events</span>
                <div class="flex-grow-1"><span class="tuile-titre">Ton record</span><strong>${pb.taille} cm${pb.poids ? ' · ' + nb(pb.poids, 2) + ' kg' : ''}</strong><small>le ${echapper(pb.date)}</small></div>
                ${photosPb ? `<button type="button" class="btn btn-sm btn-light rounded-pill" data-visionneuse data-photos="${echapper(photosPb)}"><span class="material-symbols-rounded align-middle fs-6" aria-hidden="true">photo_library</span> Photo</button>` : ''}
            </div>`;
        } else if (d.pb_kg) {
            html += `<div class="record-espece"><span class="material-symbols-rounded" aria-hidden="true">emoji_events</span><div><span class="tuile-titre">Ton record</span><strong>${nb(d.pb_kg, 2)} kg</strong></div></div>`;
        }

        const materiel = [
            ['Meilleur leurre', d.leurre], ['Meilleur appât', d.appat], ['Meilleure technique', d.technique],
        ].filter(([, v]) => v);
        if (materiel.length) {
            html += '<ul class="liste-meilleurs">' + materiel.map(([t, v]) =>
                `<li><span>${echapper(t)}</span><strong>${echapper(v.nom)}</strong><small>${v.nb} poisson(s)</small></li>`).join('') + '</ul>';
        } else {
            html += '<p class="small text-muted">Indique le leurre, l\'appât ou la technique à la saisie pour savoir ce qui marche le mieux.</p>';
        }

        html += `<h3 class="h6 fw-bold mt-3 mb-1">Saisonnalité</h3>
            <p class="small text-muted mb-2">${meilleursMois.length ? 'Ton meilleur mois : ' + echapper(meilleursMois.join(' puis ')) + '.' : ''}
            ${d.heures.some(n => n > 0) ? ' Ta meilleure heure : ' + meilleureHeure + ' h.' : ''}
            ${d.premiere ? ' Première prise le ' + echapper(d.premiere) + (d.derniere !== d.premiere ? ', dernière le ' + echapper(d.derniere) : '') + '.' : ''}</p>
            <div style="height:160px"><canvas id="graphique-saison-espece" aria-label="Poissons par mois"></canvas></div>`;

        document.getElementById('modal-espece-corps').innerHTML = html;
        if (graphique) graphique.destroy();
        if (window.Chart) {
            graphique = new Chart(document.getElementById('graphique-saison-espece'), {
                type: 'bar',
                data: { labels: MOIS, datasets: [{ data: d.mois, backgroundColor: '#1f5a66', borderRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                    scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } } } },
            });
        }
        if (!modal) modal = new bootstrap.Modal(document.getElementById('modal-espece'));
        modal.show();
    }

    document.addEventListener('click', ev => {
        const b = ev.target.closest('.ligne-espece');
        if (b) ouvrir(b.dataset.espece);
    });
})();
