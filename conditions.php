<?php
// Conditions gagnantes : quand aller pêcher, d'après tes meilleures sorties et celles de la communauté.
require_once './includes/auth_bdd.php';
require_once './includes/geo.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];

// Spots avec position (les coordonnées restent côté serveur : seul l'identifiant part dans la page)
$stmt = $pdo->prepare("SELECT ID_SPOT, NOM_SPOT, LOCALISATION, ID_TYPE_SPOT FROM spot WHERE ID_UTILISATEUR = ? ORDER BY NOM_SPOT");
$stmt->execute([$id_user]);
$spots = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn($s) => lire_localisation($s['LOCALISATION']) !== null));

// Espèces déjà prises par l'utilisateur (mode perso) et espèces de tout le site (mode communauté)
$stmt = $pdo->prepare("
    SELECT e.ID_ESPECE, e.NOM_COM, SUM(p.QUANTITE) AS NB FROM prise p
    JOIN session_p s ON s.ID_SESSION = p.ID_SESSION JOIN espece e ON e.ID_ESPECE = p.ID_ESPECE
    WHERE s.ID_UTILISATEUR = ? GROUP BY e.ID_ESPECE, e.NOM_COM ORDER BY NB DESC
");
$stmt->execute([$id_user]);
$mes_especes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$especes_site = $pdo->query("
    SELECT e.ID_ESPECE, e.NOM_COM FROM espece e WHERE EXISTS (SELECT 1 FROM prise p WHERE p.ID_ESPECE = e.ID_ESPECE) ORDER BY e.NOM_COM
")->fetchAll(PDO::FETCH_ASSOC);
$vue = ($_GET['vue'] ?? '') === 'communaute' ? 'communaute' : 'perso';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conditions gagnantes - Mon P'tit Carnet</title>
    <?php include './includes/head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
</head>
<body class="avec-selecteur">
    <header class="custom-header text-white text-center py-3 mb-3 position-relative">
        <a href="accueil.php" class="text-white position-absolute start-0 top-50 translate-middle-y ms-2 text-decoration-none" aria-label="Retour à l'accueil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-bold">Conditions gagnantes</h1>
        <a href="meteo.php" class="text-white position-absolute end-0 top-50 translate-middle-y me-2 text-decoration-none" aria-label="Météo, marées et rivière">
            <span class="material-symbols-rounded">partly_cloudy_day</span>
        </a>
    </header>

    <main class="container" style="max-width: 640px;">

        <!-- ============================== POUR MOI ============================== -->
        <section id="vue-perso" <?= $vue === 'perso' ? '' : 'hidden' ?> aria-labelledby="titre-perso">
            <h2 id="titre-perso" class="visually-hidden">Prévision personnelle</h2>
            <p class="text-muted mb-3">Les prochaines heures comparées à tes meilleures sorties : pression et sa tendance, vent, marée, débit, heure de la journée.</p>
            <div class="row g-2 mb-3">
                <div class="col-7">
                    <label class="form-label" for="lieu">Où ?</label>
                    <select id="lieu" class="form-select">
                        <?php foreach ($spots as $s): ?>
                            <option value="<?= (int) $s['ID_SPOT'] ?>"><?= e($s['NOM_SPOT']) ?></option>
                        <?php endforeach; ?>
                        <option value="gps">Ma position actuelle</option>
                    </select>
                </div>
                <div class="col-5">
                    <label class="form-label" for="espece-perso">Espèce</label>
                    <select id="espece-perso" class="form-select">
                        <option value="">Toutes</option>
                        <?php foreach ($mes_especes as $esp): ?>
                            <option value="<?= (int) $esp['ID_ESPECE'] ?>"><?= e($esp['NOM_COM']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="resultat-perso" aria-live="polite"></div>
        </section>

        <!-- ============================== COMMUNAUTÉ ============================== -->
        <section id="vue-communaute" <?= $vue === 'communaute' ? '' : 'hidden' ?> aria-labelledby="titre-communaute">
            <h2 id="titre-communaute" class="visually-hidden">Tendances de la communauté</h2>
            <p class="text-muted mb-3">Ce qui marche dans un secteur, d'après les prises de tous les membres. Aucun lieu précis n'est utilisé : seulement la façade, le département ou le cours d'eau.</p>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label" for="espece-communaute">Espèce</label>
                    <select id="espece-communaute" class="form-select">
                        <option value="">Toutes</option>
                        <?php foreach ($especes_site as $esp): ?>
                            <option value="<?= (int) $esp['ID_ESPECE'] ?>"><?= e($esp['NOM_COM']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" for="zone">Secteur</label>
                    <select id="zone" class="form-select"><option value="tout">Tout le site</option></select>
                </div>
            </div>
            <div id="resultat-communaute" aria-live="polite"></div>
            <p class="small text-muted mt-3">Statistiques anonymes : un résultat n'apparaît qu'avec au moins 10 prises de 3 pêcheurs différents. Tu peux retirer tes prises de ces calculs dans les paramètres.</p>
        </section>
    </main>

    <!-- Sélecteur de vue dans la zone du pouce -->
    <div class="selecteur-vue" role="radiogroup" aria-label="Choisir la vue">
        <input type="radio" name="vue" id="radio-perso" value="perso" <?= $vue === 'perso' ? 'checked' : '' ?>>
        <label for="radio-perso"><span class="material-symbols-rounded" aria-hidden="true">person</span>Pour moi</label>
        <input type="radio" name="vue" id="radio-communaute" value="communaute" <?= $vue === 'communaute' ? 'checked' : '' ?>>
        <label for="radio-communaute"><span class="material-symbols-rounded" aria-hidden="true">groups</span>Communauté</label>
    </div>

    <?php include './includes/navbar.php'; ?>

    <script>
    (function () {
        const echapper = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const jours = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
        const libelleHeure = t => { const d = new Date(t); return `${jours[d.getDay()]} ${d.getHours()} h`; };
        const chargement = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><p class="text-muted mt-2">Analyse en cours…</p></div>';
        let graphique = null;

        // ---------------------------------------------- Vue
        document.querySelectorAll('input[name="vue"]').forEach(r => r.addEventListener('change', () => {
            const v = document.querySelector('input[name="vue"]:checked').value;
            document.getElementById('vue-perso').hidden = v !== 'perso';
            document.getElementById('vue-communaute').hidden = v !== 'communaute';
            history.replaceState(null, '', v === 'communaute' ? '?vue=communaute' : 'conditions.php');
            if (v === 'communaute') chargerZones(); else chargerPerso();
        }));

        // ---------------------------------------------- Pour moi
        const lieu = document.getElementById('lieu');
        const especePerso = document.getElementById('espece-perso');
        const zonePerso = document.getElementById('resultat-perso');

        function afficherPerso(d) {
            if (d.erreur) {
                zonePerso.innerHTML = `<div class="card border-0 p-4 text-center"><span class="material-symbols-rounded mb-2" style="font-size:44px" aria-hidden="true">query_stats</span><p class="mb-0">${echapper(d.erreur)}</p></div>`;
                return;
            }
            const c = d.creneau;
            const fin = new Date(c.fin);
            zonePerso.innerHTML = `
                <div class="card border-0 p-3 mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="jauge-score" style="--score:${d.maintenant.score}"><div><strong>${d.maintenant.score}</strong><span>sur 100</span></div></div>
                        <div>
                            <p class="fw-bold mb-1">Maintenant à ${echapper(d.lieu)}</p>
                            <p class="mb-0 text-muted">${d.maintenant.proches ? `Conditions proches de ${d.maintenant.proches} de tes ${d.nb_reference} meilleures sorties.` : `Peu de ressemblance avec tes ${d.nb_reference} meilleures sorties.`}</p>
                        </div>
                    </div>
                    <ul class="liste-raisons mt-3">${d.maintenant.explications.map(t => `<li><span class="material-symbols-rounded text-primary" aria-hidden="true">check</span>${echapper(t)}</li>`).join('')}</ul>
                </div>
                <div class="card border-0 p-3 mb-3 creneau">
                    <p class="mb-1 text-muted">Meilleur créneau des 3 prochains jours</p>
                    <p class="h4 fw-bold mb-1">${libelleHeure(c.debut)} – ${fin.getHours()} h <span class="text-primary">· ${c.score}/100</span></p>
                    <p class="mb-2">${c.proches ? `Proche de ${c.proches} de tes ${d.nb_reference} meilleures sorties` : 'Le plus proche de tes habitudes gagnantes'} (${echapper(d.portee)}).</p>
                    <ul class="liste-raisons">${c.explications.map(t => `<li><span class="material-symbols-rounded text-primary" aria-hidden="true">check</span>${echapper(t)}</li>`).join('')}</ul>
                </div>
                <div class="card border-0 p-3 mb-3">
                    <p class="fw-bold mb-2">Score heure par heure</p>
                    <div style="height:170px"><canvas id="graphique-heures" aria-label="Score de chaque heure des trois prochains jours" role="img"></canvas></div>
                </div>
                <div class="card border-0 p-3 mb-3">
                    <p class="fw-bold mb-2">Tes sorties de référence</p>
                    ${d.meilleures.map(s => `<div class="py-2 border-top"><strong>${echapper(s.date)}</strong>${s.spot ? ' · ' + echapper(s.spot) : ''}<br>
                        <small class="text-muted">${s.nb} poisson${s.nb > 1 ? 's' : ''}${s.taille_max ? ', record ' + s.taille_max + ' cm' : ''} · ${echapper(s.resume)}</small></div>`).join('')}
                </div>`;
            const dessiner = () => {
                if (!window.Chart) return setTimeout(dessiner, 150);
                if (graphique) graphique.destroy();
                graphique = new Chart(document.getElementById('graphique-heures'), {
                    type: 'line',
                    data: { labels: d.heures.map(h => libelleHeure(h.t)), datasets: [{ data: d.heures.map(h => h.score), borderColor: '#c4430c', backgroundColor: 'rgba(196,67,12,.12)', fill: true, tension: .3, pointRadius: 0, borderWidth: 3 }] },
                    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100, ticks: { stepSize: 25 } }, x: { ticks: { maxTicksLimit: 6 } } } },
                });
            };
            dessiner();
        }

        function chargerPerso() {
            zonePerso.innerHTML = chargement;
            const espece = especePerso.value ? '&espece=' + encodeURIComponent(especePerso.value) : '';
            const appel = params => fetch('api_conditions.php?mode=perso' + params + espece, { credentials: 'same-origin' })
                .then(r => r.json()).then(afficherPerso)
                .catch(() => afficherPerso({ erreur: 'Connexion nécessaire pour calculer les prévisions.' }));
            if (lieu.value === 'gps') {
                if (!navigator.geolocation) return afficherPerso({ erreur: "La localisation n'est pas disponible sur cet appareil." });
                navigator.geolocation.getCurrentPosition(
                    p => appel(`&lat=${p.coords.latitude.toFixed(4)}&lng=${p.coords.longitude.toFixed(4)}`),
                    () => afficherPerso({ erreur: 'Autorise la localisation ou choisis un de tes spots.' }),
                    { timeout: 10000, maximumAge: 300000 }
                );
            } else {
                appel('&spot=' + encodeURIComponent(lieu.value));
            }
        }
        lieu.addEventListener('change', chargerPerso);
        especePerso.addEventListener('change', chargerPerso);

        // ---------------------------------------------- Communauté
        const especeCom = document.getElementById('espece-communaute');
        const zone = document.getElementById('zone');
        const zoneCom = document.getElementById('resultat-communaute');

        function chargerZones() {
            const choix = zone.value;
            fetch('api_conditions.php?mode=zones&espece=' + encodeURIComponent(especeCom.value), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    zone.innerHTML = (d.zones || []).map(z => `<option value="${echapper(z.valeur)}">${echapper(z.libelle)}</option>`).join('');
                    if ([...zone.options].some(o => o.value === choix)) zone.value = choix;
                    chargerCommunaute();
                })
                .catch(() => { zoneCom.innerHTML = '<p class="text-muted">Connexion nécessaire.</p>'; });
        }
        function chargerCommunaute() {
            zoneCom.innerHTML = chargement;
            fetch(`api_conditions.php?mode=communaute&zone=${encodeURIComponent(zone.value)}&espece=${encodeURIComponent(especeCom.value)}`, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.erreur) { zoneCom.innerHTML = `<p>${echapper(d.erreur)}</p>`; return; }
                    const nomEspece = especeCom.value ? especeCom.options[especeCom.selectedIndex].text : 'Toutes espèces';
                    let html = `<div class="card border-0 p-3 mb-3"><p class="fw-bold mb-0">${echapper(nomEspece)} · ${echapper(d.zone)}</p>
                        <small class="text-muted">${d.nb_prises} prise${d.nb_prises > 1 ? 's' : ''} de ${d.nb_pecheurs} pêcheur${d.nb_pecheurs > 1 ? 's' : ''}</small></div>`;
                    if (d.message) html += `<div class="card border-0 p-4 text-center mb-3"><span class="material-symbols-rounded mb-2" style="font-size:40px" aria-hidden="true">lock</span><p class="mb-0">${echapper(d.message)}</p></div>`;
                    d.dimensions.forEach(dim => {
                        html += `<div class="card border-0 p-3 mb-3"><p class="text-muted mb-1">${echapper(dim.titre)}</p><p class="fw-bold mb-2">${echapper(dim.phrase)}</p>`
                            + dim.repartition.map((r, i) => `<div class="d-flex align-items-center gap-2 mb-1"><span style="width:44%" class="small">${echapper(r.classe)}</span>
                                <div class="barre-stat flex-fill ${i === 0 ? 'principale' : ''}"><span style="width:${r.pct}%"></span></div><span class="small fw-bold" style="width:3.2em;text-align:right">${r.pct} %</span></div>`).join('')
                            + `<small class="text-muted">sur ${dim.total} prises avec cette donnée</small></div>`;
                    });
                    zoneCom.innerHTML = html;
                })
                .catch(() => { zoneCom.innerHTML = '<p class="text-muted">Connexion nécessaire.</p>'; });
        }
        especeCom.addEventListener('change', chargerZones);
        zone.addEventListener('change', chargerCommunaute);

        if (document.querySelector('input[name="vue"]:checked').value === 'communaute') chargerZones(); else chargerPerso();
    })();
    </script>
</body>
</html>
