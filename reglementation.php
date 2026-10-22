<?php
// Consultation de la réglementation par tous les membres : tailles minimales, périodes, quotas, zones de réserve.
// « Pour mon spot » : les règles qui s'appliquent aujourd'hui à l'un de ses spots (même calcul que le garde-fou de saisie).
require_once './includes/auth_bdd.php';
require_once './includes/reglementation.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$aujourdhui = date('Y-m-d');

const TYPES_ZONE = ['reserve_peche' => 'Réserve de pêche', 'aire_marine_protegee' => 'Aire marine protégée', 'zone_interdite' => 'Zone interdite', 'autre' => 'Zone réglementée'];

// --- Filtres
$milieu = in_array($_GET['milieu'] ?? '', ['eau_douce', 'mer'], true) ? $_GET['milieu'] : '';
$departement = (string) ($_GET['departement'] ?? '');
if ($departement !== '' && $departement !== 'national' && !array_key_exists($departement, DEPARTEMENTS)) $departement = '';
$categorie = in_array($_GET['categorie'] ?? '', ['1', '2'], true) ? (int) $_GET['categorie'] : null;
$recherche = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);

// --- Mes spots (pour « que puis-je garder ici aujourd'hui ? »)
$stmt = $pdo->prepare("SELECT ID_SPOT, NOM_SPOT, ID_TYPE_SPOT, CATEGORIE_PISCICOLE, LOCALISATION, CODE_DEPARTEMENT FROM spot WHERE ID_UTILISATEUR = ? ORDER BY NOM_SPOT");
$stmt->execute([$id_user]);
$mes_spots = $stmt->fetchAll(PDO::FETCH_ASSOC);
$spot_choisi = null;
$regles_spot = [];
$ctx_spot = null;
if ($id_spot = id_ou_null($_GET['spot'] ?? null)) {
    foreach ($mes_spots as $sp) if ((int) $sp['ID_SPOT'] === $id_spot) $spot_choisi = $sp;
    if ($spot_choisi) {
        $coords = lire_localisation($spot_choisi['LOCALISATION']);
        $ctx_spot = contexte_reglementaire($pdo, [
            'id_type_spot' => (int) $spot_choisi['ID_TYPE_SPOT'], 'categorie' => $spot_choisi['CATEGORIE_PISCICOLE'],
            'lat' => $coords[0] ?? null, 'lng' => $coords[1] ?? null, 'date' => $aujourdhui,
            'departement' => $spot_choisi['CODE_DEPARTEMENT'],
        ]);
        $regles_spot = regles_resolues($pdo, $ctx_spot);
        usort($regles_spot, fn($a, $b) => [$a['nom_espece'] === null ? 0 : 1, $a['nom_espece']] <=> [$b['nom_espece'] === null ? 0 : 1, $b['nom_espece']]);
    }
}

// --- Liste filtrée de toutes les règles actives
$where = ['r.ACTIF = 1'];
$params = [];
if ($milieu) { $where[] = 'r.MILIEU = ?'; $params[] = $milieu; }
if ($departement === 'national') { $where[] = 'r.CODE_DEPARTEMENT IS NULL'; }
elseif ($departement !== '') { $where[] = '(r.CODE_DEPARTEMENT IS NULL OR r.CODE_DEPARTEMENT = ?)'; $params[] = $departement; }
if ($categorie) { $where[] = "(r.MILIEU <> 'eau_douce' OR r.CATEGORIE_PISCICOLE IS NULL OR r.CATEGORIE_PISCICOLE = ?)"; $params[] = $categorie; }
if ($recherche !== '') { $where[] = "(e.NOM_COM LIKE ? OR e.NOM_SCIEN LIKE ? OR (r.ID_ESPECE IS NULL AND ? LIKE '%toutes%'))"; array_push($params, "%$recherche%", "%$recherche%", mb_strtolower($recherche)); }
$stmt = $pdo->prepare("
    SELECT r.*, e.NOM_COM, e.NOM_SCIEN, e.ICONE_CHEMIN
    FROM reglementation r LEFT JOIN espece e ON e.ID_ESPECE = r.ID_ESPECE
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.NOM_COM IS NOT NULL, e.NOM_COM, r.MILIEU, r.CODE_DEPARTEMENT IS NOT NULL, r.CODE_DEPARTEMENT, r.ID_REGLE");
$stmt->execute($params);
$regles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$par_espece = [];
foreach ($regles as $r) $par_espece[$r['NOM_COM'] ?? ''][] = $r;

$departements_regles = $pdo->query("SELECT DISTINCT CODE_DEPARTEMENT FROM reglementation WHERE ACTIF = 1 AND CODE_DEPARTEMENT IS NOT NULL ORDER BY CODE_DEPARTEMENT")->fetchAll(PDO::FETCH_COLUMN);
$reserves = reserves_pour_carte($pdo);
$dates_verif = array_filter(array_column($regles, 'DATE_VERIFICATION'));
$date_verification = $dates_verif ? max($dates_verif) : null;

/** Zone d'application lisible d'une règle de la table. */
function zone_regle(array $r): string
{
    $z = [MILIEUX[$r['MILIEU']] ?? $r['MILIEU']];
    if ($r['CATEGORIE_PISCICOLE']) $z[] = (int) $r['CATEGORIE_PISCICOLE'] === 1 ? '1re catégorie' : '2e catégorie';
    if ($r['FACADE']) $z[] = FACADES[$r['FACADE']] ?? $r['FACADE'];
    if ($r['LAT_MIN'] !== null) $z[] = 'au nord du ' . (int) $r['LAT_MIN'] . 'e parallèle';
    if ($r['LAT_MAX'] !== null) $z[] = 'au sud du ' . (int) $r['LAT_MAX'] . 'e parallèle';
    $z[] = $r['CODE_DEPARTEMENT'] ? nom_departement($r['CODE_DEPARTEMENT']) : 'règle nationale';
    return implode(' · ', $z);
}

/** Lignes « taille / quota / période » d'une règle. */
function details_regle(array $r, string $aujourdhui): array
{
    $l = [];
    if ($r['TAILLE_MIN_CM'] !== null) $l[] = ['straighten', 'Taille minimale', (int) $r['TAILLE_MIN_CM'] . ' cm', null];
    if ($r['QUOTA_JOUR'] !== null) {
        $l[] = ['shopping_basket', 'Quota', (int) $r['QUOTA_JOUR'] . ' par jour et par pêcheur' . ($r['GROUPE_QUOTA'] ? ' (commun au groupe « ' . str_replace('_', ' ', $r['GROUPE_QUOTA']) . ' »)' : ''), null];
    }
    if ($r['FERMETURE_DEBUT'] && $r['FERMETURE_FIN']) {
        $toute_annee = $r['FERMETURE_DEBUT'] === '01-01' && $r['FERMETURE_FIN'] === '12-31';
        $nature = $r['FERMETURE_NATURE'] === 'no_kill' ? 'No-kill obligatoire' : 'Pêche interdite';
        $f = fermeture_a_la_date($r['FERMETURE_DEBUT'], $r['FERMETURE_FIN'], $aujourdhui);
        $texte = $toute_annee ? "toute l'année" : 'du ' . libelle_borne_regle($r['FERMETURE_DEBUT']) . ' au ' . libelle_borne_regle($r['FERMETURE_FIN']);
        if ($f && !$toute_annee) $texte .= ' (' . $f['du'] . ' → ' . $f['au'] . ')';
        $etat = $f ? ($f['active'] ? ['ferme', $r['FERMETURE_NATURE'] === 'no_kill' ? "No-kill en ce moment" : "Fermé en ce moment"] : ['ouvert', 'Ouvert en ce moment']) : null;
        $l[] = ['event_busy', $nature, $texte, $etat];
    }
    if ($r['MARQUAGE']) $l[] = ['content_cut', 'Marquage', 'Poisson gardé à marquer (ablation de la partie inférieure de la nageoire caudale)', null];
    if ($r['DECLARATION']) $l[] = ['edit_note', 'Déclaration', 'Captures à déclarer (application ou carnet officiel)', null];
    return $l;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réglementation - Mon P'tit Carnet</title>
    <?php include './includes/head.php'; ?>
    <?php if ($reserves): ?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><?php endif; ?>
</head>
<body>
    <header class="custom-header text-white text-center py-4 shadow-sm mb-3 position-relative">
        <a href="accueil.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;" aria-label="Retour à l'accueil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Réglementation</h1>
        <small class="text-white-50">Tailles, périodes, quotas et réserves</small>
    </header>

    <main class="container pb-5 mb-5" style="max-width: 760px;">
        <div class="avertissement-regles mb-3" role="note">
            <span class="material-symbols-rounded" aria-hidden="true">info</span>
            <p class="mb-0">Informations indicatives<?= $date_verification ? ', vérifiées le ' . e(date('d/m/Y', strtotime($date_verification))) : '' ?>. Seuls les arrêtés officiels (préfecture, fédération de pêche, affaires maritimes) font foi : ils peuvent être plus stricts.</p>
        </div>

        <!-- Que puis-je garder ici aujourd'hui ? -->
        <section class="card border-0 shadow-sm rounded-4 p-3 mb-4" aria-labelledby="titre-mon-spot">
            <h2 class="h6 fw-bold mb-2" id="titre-mon-spot"><span class="material-symbols-rounded align-middle me-1 text-primary" aria-hidden="true">pin_drop</span> Pour mon spot, aujourd'hui</h2>
            <?php if (!$mes_spots): ?>
                <p class="small text-muted mb-0">Enregistre un spot dans <a href="mes_spots.php">Mes spots</a> pour voir les règles qui s'y appliquent.</p>
            <?php else: ?>
                <form method="GET" class="d-flex gap-2">
                    <label for="choix-spot" class="visually-hidden">Choisir un spot</label>
                    <select class="form-select" id="choix-spot" name="spot" onchange="this.form.submit()">
                        <option value="">Choisir un de mes spots…</option>
                        <?php foreach ($mes_spots as $sp): ?>
                            <option value="<?= (int) $sp['ID_SPOT'] ?>" <?= $spot_choisi && (int) $sp['ID_SPOT'] === (int) $spot_choisi['ID_SPOT'] ? 'selected' : '' ?>><?= e($sp['NOM_SPOT']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-primary">Voir</button></noscript>
                </form>
                <?php if ($ctx_spot): ?>
                    <p class="small text-muted mt-2 mb-2"><?= e($ctx_spot['libelle']) ?>, le <?= e(date('d/m/Y')) ?></p>
                    <?php foreach ($ctx_spot['reserves'] as $res): ?>
                        <div class="alerte-terrain alerte-danger mb-2"><span class="material-symbols-rounded" aria-hidden="true">block</span><div><?= e($res['nom']) ?> : <?= e($res['regle'] ?: 'zone réglementée') ?></div></div>
                    <?php endforeach; ?>
                    <?php if (!$regles_spot): ?>
                        <p class="small mb-0">Aucune règle de la base ne s'applique à ce spot. Vérifie quand même l'arrêté du département.</p>
                    <?php else: ?>
                        <ul class="regles-du-jour">
                            <?php foreach ($regles_spot as $r): ?>
                                <li class="<?= $r['fermee'] ? 'fermee' : '' ?>">
                                    <strong><?= e($r['nom_espece'] ?? 'Toutes les espèces') ?></strong>
                                    <span>
                                        <?php $parts = [];
                                        if ($r['taille_min']) $parts[] = $r['taille_min'] . ' cm minimum';
                                        if ($r['quota']) $parts[] = $r['quota'] . ' par jour' . ($r['groupe'] ? ' (' . str_replace('_', ' ', $r['groupe']) . ')' : '');
                                        if ($r['fermee']) $parts[] = ($r['nature'] === 'no_kill' ? 'no-kill obligatoire ' : 'fermé ') . $r['periode'];
                                        elseif ($r['periode']) $parts[] = 'ouvert (fermeture ' . $r['periode'] . ')';
                                        echo e(implode(' · ', $parts) ?: ($r['note'] ?? ''));
                                        if ($r['condition']) echo ' <small class="text-muted">(si ' . e($r['condition']) . ')</small>'; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- Filtres -->
        <form method="GET" class="filtres-regles card border-0 shadow-sm rounded-4 p-3 mb-3" role="search" aria-label="Filtrer les règles">
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label small fw-bold mb-1" for="q">Espèce</label>
                    <input type="search" class="form-control" id="q" name="q" value="<?= e($recherche) ?>" placeholder="Ex. : brochet, bar, truite">
                </div>
                <div class="col-6">
                    <label class="form-label small fw-bold mb-1" for="milieu">Milieu</label>
                    <select class="form-select" id="milieu" name="milieu">
                        <option value="">Tous</option>
                        <option value="eau_douce" <?= $milieu === 'eau_douce' ? 'selected' : '' ?>>Eau douce</option>
                        <option value="mer" <?= $milieu === 'mer' ? 'selected' : '' ?>>Mer</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-bold mb-1" for="categorie">Catégorie</label>
                    <select class="form-select" id="categorie" name="categorie">
                        <option value="">Les deux</option>
                        <option value="1" <?= $categorie === 1 ? 'selected' : '' ?>>1re catégorie</option>
                        <option value="2" <?= $categorie === 2 ? 'selected' : '' ?>>2e catégorie</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-bold mb-1" for="departement">Département</label>
                    <select class="form-select" id="departement" name="departement">
                        <option value="">National et tous les départements</option>
                        <option value="national" <?= $departement === 'national' ? 'selected' : '' ?>>Règles nationales seulement</option>
                        <?php foreach (DEPARTEMENTS as $code => $nom): ?>
                            <option value="<?= e($code) ?>" <?= $departement === (string) $code ? 'selected' : '' ?>><?= e($nom . ' (' . $code . ')') ?><?= in_array((string) $code, $departements_regles, true) ? ' · règles locales' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary rounded-pill flex-grow-1 fw-bold">Filtrer</button>
                    <a href="reglementation.php" class="btn btn-light rounded-pill">Tout afficher</a>
                </div>
            </div>
        </form>

        <p class="small text-muted mb-3"><?= count($regles) ?> règle<?= count($regles) > 1 ? 's' : '' ?><?= $departement && $departement !== 'national' ? ' (nationales et ' . e(nom_departement($departement)) . ')' : '' ?>.</p>

        <?php if (!$regles): ?>
            <div class="card border-0 shadow-sm rounded-4 p-4 text-center text-muted">Aucune règle ne correspond à ces filtres.</div>
        <?php endif; ?>

        <?php foreach ($par_espece as $nom => $liste): $premiere = $liste[0]; ?>
            <section class="regle-espece mb-3">
                <h2 class="regle-espece-titre">
                    <?php if ($premiere['ICONE_CHEMIN']): ?><img src="<?= e(icone_legere($premiere['ICONE_CHEMIN'])) ?>" alt="" loading="lazy"><?php else: ?><span class="material-symbols-rounded" aria-hidden="true">waves</span><?php endif; ?>
                    <span><?= e($nom !== '' ? $nom : 'Toutes les espèces') ?><?php if ($premiere['NOM_SCIEN']): ?> <small><?= e($premiere['NOM_SCIEN']) ?></small><?php endif; ?></span>
                </h2>
                <?php foreach ($liste as $r): ?>
                    <article class="regle-carte">
                        <p class="regle-zone"><?= e(zone_regle($r)) ?></p>
                        <ul class="regle-details">
                            <?php foreach (details_regle($r, $aujourdhui) as [$icone, $titre, $texte, $etat]): ?>
                                <li>
                                    <span class="material-symbols-rounded" aria-hidden="true"><?= $icone ?></span>
                                    <span><strong><?= e($titre) ?></strong> <?= e($texte) ?>
                                        <?php if ($etat): ?><span class="etat-regle <?= $etat[0] ?>"><?= e($etat[1]) ?></span><?php endif; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($r['NOTE']): ?><p class="regle-note"><?= e($r['NOTE']) ?></p><?php endif; ?>
                        <?php if ($r['SOURCE']): ?><p class="regle-source">Source : <?= e($r['SOURCE']) ?><?= $r['DATE_VERIFICATION'] ? ' · vérifiée le ' . e(date('d/m/Y', strtotime($r['DATE_VERIFICATION']))) : '' ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <!-- Zones de réserve -->
        <h2 class="titre-section mt-4" id="reserves"><span class="material-symbols-rounded" aria-hidden="true">do_not_disturb_on</span> Zones de réserve</h2>
        <?php if (!$reserves): ?>
            <p class="small text-muted">Aucune zone de réserve n'est encore enregistrée. Les réserves figurent sur l'arrêté préfectoral et sur place (panneaux).</p>
        <?php else: ?>
            <div id="carte-reserves" class="rounded-4 shadow-sm mb-3" style="height: 280px; z-index: 1;" aria-label="Carte des zones de réserve"></div>
            <ul class="liste-reserves">
                <?php foreach ($reserves as $z): ?>
                    <li><button type="button" class="lien-reserve" data-id="<?= (int) $z['id'] ?>"><strong><?= e($z['nom']) ?></strong><small><?= e(TYPES_ZONE[$z['type']] ?? $z['type']) ?><?= $z['regle'] ? ' · ' . e($z['regle']) : '' ?></small></button></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>

    <?php include './includes/navbar.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($reserves): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        (function () {
            const reserves = <?= js($reserves) ?>;
            const echapper = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
            const carte = L.map('carte-reserves');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(carte);
            const couches = {};
            const groupe = L.featureGroup().addTo(carte);
            reserves.forEach(z => {
                if (!z.geometrie) return;
                const c = L.geoJSON(z.geometrie, { style: { color: '#b42318', weight: 2, fillOpacity: .2 } })
                    .bindPopup(`<strong>${echapper(z.nom)}</strong><br>${echapper(z.regle || '')}`);
                c.addTo(groupe);
                couches[z.id] = c;
            });
            if (groupe.getLayers().length) carte.fitBounds(groupe.getBounds(), { padding: [20, 20], maxZoom: 13 }); else carte.setView([46.6, 1.9], 5);
            document.querySelectorAll('.lien-reserve').forEach(b => b.addEventListener('click', () => {
                const c = couches[b.dataset.id];
                if (!c) return;
                carte.fitBounds(c.getBounds(), { maxZoom: 15 });
                c.openPopup();
                document.getElementById('carte-reserves').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }));
        })();
    </script>
    <?php endif; ?>
</body>
</html>
