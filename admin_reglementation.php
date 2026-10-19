<?php
// Administration du garde-fou réglementaire : règles (tailles, quotas, périodes) et zones de réserve.
require_once './includes/bootstrap.php';
require_once './includes/reglementation.php';
$id_admin = exiger_admin();
$pdo = db();
$message_succes = '';
$message_erreur = '';

/** Borne de période valide ('MM-JJ' ou '2-sat-03', 'last-sun-01+1'…) ou null. */
function borne_ou_null($v): ?string
{
    $v = trim((string) $v);
    return ($v !== '' && date_borne_regle($v, 2026) !== null) ? $v : null;
}
function texte_court($v, int $max): ?string
{
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------------------------------------------------------------- Règles
    if ($action === 'enregistrer_regle') {
        $id_regle = id_ou_null($_POST['id_regle'] ?? null);
        $id_espece = id_ou_null($_POST['id_espece'] ?? null);
        $milieu = in_array($_POST['milieu'] ?? '', ['eau_douce', 'mer'], true) ? $_POST['milieu'] : null;
        $debut = borne_ou_null($_POST['fermeture_debut'] ?? '');
        $fin = borne_ou_null($_POST['fermeture_fin'] ?? '');
        $donnees = [
            $id_espece,
            $milieu,
            in_array($_POST['categorie'] ?? '', ['1', '2'], true) ? (int) $_POST['categorie'] : null,
            array_key_exists($_POST['facade'] ?? '', FACADES) ? $_POST['facade'] : null,
            nombre_ou_null($_POST['lat_min'] ?? null, -90, 90),
            nombre_ou_null($_POST['lat_max'] ?? null, -90, 90),
            preg_match('/^(\d{2,3}|2[AB])$/', (string) ($_POST['departement'] ?? '')) ? $_POST['departement'] : null,
            id_ou_null($_POST['taille_min'] ?? null),
            id_ou_null($_POST['quota'] ?? null),
            preg_match('/^[a-z_]{1,40}$/', (string) ($_POST['groupe'] ?? '')) ? $_POST['groupe'] : null,
            $debut && $fin ? $debut : null,
            $debut && $fin ? $fin : null,
            $debut && $fin && in_array($_POST['nature'] ?? '', ['interdite', 'no_kill'], true) ? $_POST['nature'] : null,
            isset($_POST['marquage']) ? 1 : 0,
            isset($_POST['declaration']) ? 1 : 0,
            texte_court($_POST['note'] ?? '', 500),
            texte_court($_POST['source'] ?? '', 255),
        ];
        $stmtEsp = $pdo->prepare("SELECT 1 FROM espece WHERE ID_ESPECE = ?");
        $stmtEsp->execute([$id_espece]);
        $especes_ok = $id_espece === null || $stmtEsp->fetchColumn();
        if (!$milieu || !$especes_ok) {
            $message_erreur = "Choisis un milieu et une espèce valides.";
        } elseif (($debut xor $fin) || (($_POST['fermeture_debut'] ?? '') !== '' && !$debut) || (($_POST['fermeture_fin'] ?? '') !== '' && !$fin)) {
            $message_erreur = "Période invalide : utilise « MM-JJ » (ex. 02-01) ou « rang-jour-mois » (ex. 2-sat-03, last-sun-01+1).";
        } else {
            if ($id_regle) {
                $pdo->prepare("UPDATE reglementation SET ID_ESPECE = ?, MILIEU = ?, CATEGORIE_PISCICOLE = ?, FACADE = ?, LAT_MIN = ?, LAT_MAX = ?, CODE_DEPARTEMENT = ?,
                    TAILLE_MIN_CM = ?, QUOTA_JOUR = ?, GROUPE_QUOTA = ?, FERMETURE_DEBUT = ?, FERMETURE_FIN = ?, FERMETURE_NATURE = ?, MARQUAGE = ?, DECLARATION = ?,
                    NOTE = ?, SOURCE = ?, DATE_VERIFICATION = CURDATE() WHERE ID_REGLE = ?")->execute([...$donnees, $id_regle]);
                $message_succes = "Règle n°$id_regle mise à jour.";
            } else {
                $pdo->prepare("INSERT INTO reglementation (ID_ESPECE, MILIEU, CATEGORIE_PISCICOLE, FACADE, LAT_MIN, LAT_MAX, CODE_DEPARTEMENT,
                    TAILLE_MIN_CM, QUOTA_JOUR, GROUPE_QUOTA, FERMETURE_DEBUT, FERMETURE_FIN, FERMETURE_NATURE, MARQUAGE, DECLARATION, NOTE, SOURCE, DATE_VERIFICATION)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())")->execute($donnees);
                $message_succes = "Règle ajoutée.";
            }
        }
    }
    if ($action === 'basculer_regle' && ($id = id_ou_null($_POST['id_regle'] ?? null))) {
        $pdo->prepare("UPDATE reglementation SET ACTIF = 1 - ACTIF WHERE ID_REGLE = ?")->execute([$id]);
        $message_succes = "Règle n°$id activée / désactivée.";
    }
    if ($action === 'supprimer_regle' && ($id = id_ou_null($_POST['id_regle'] ?? null))) {
        $pdo->prepare("DELETE FROM reglementation WHERE ID_REGLE = ?")->execute([$id]);
        $message_succes = "Règle n°$id supprimée.";
    }

    // ---------------------------------------------------------------- Réserves
    if ($action === 'enregistrer_reserve') {
        $nom = texte_court($_POST['nom'] ?? '', 150);
        $regle = texte_court($_POST['regle'] ?? '', 500);
        $type = in_array($_POST['type_zone'] ?? '', ['reserve_peche', 'aire_marine_protegee', 'zone_interdite', 'autre'], true) ? $_POST['type_zone'] : 'reserve_peche';
        $geo = geojson_valide((string) ($_POST['geojson'] ?? ''));
        $date_fin = DateTime::createFromFormat('Y-m-d', (string) ($_POST['date_fin'] ?? ''));
        if (!$nom || !$regle || !$geo) {
            $message_erreur = "Indique un nom, la règle affichée et un polygone (dessine-le sur la carte ou colle un GeoJSON Polygon/MultiPolygon).";
        } else {
            [$latMin, $latMax, $lngMin, $lngMax] = $geo['bbox'];
            $pdo->prepare("INSERT INTO zone_reserve (NOM, TYPE_ZONE, REGLE, CODE_DEPARTEMENT, GEOJSON, LAT_MIN, LAT_MAX, LNG_MIN, LNG_MAX, DATE_FIN, SOURCE)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $nom, $type, $regle,
                preg_match('/^(\d{2,3}|2[AB])$/', (string) ($_POST['departement'] ?? '')) ? $_POST['departement'] : null,
                json_encode($geo['geometrie']), $latMin, $latMax, $lngMin, $lngMax,
                $date_fin ? $date_fin->format('Y-m-d') : null,
                texte_court($_POST['source'] ?? '', 255),
            ]);
            $message_succes = "Zone « $nom » ajoutée.";
        }
    }
    if ($action === 'basculer_reserve' && ($id = id_ou_null($_POST['id_reserve'] ?? null))) {
        $pdo->prepare("UPDATE zone_reserve SET ACTIF = 1 - ACTIF, DATE_MAJ = NOW() WHERE ID_RESERVE = ?")->execute([$id]);
        $message_succes = "Zone n°$id activée / désactivée.";
    }
    if ($action === 'supprimer_reserve' && ($id = id_ou_null($_POST['id_reserve'] ?? null))) {
        $pdo->prepare("DELETE FROM zone_reserve WHERE ID_RESERVE = ?")->execute([$id]);
        $message_succes = "Zone n°$id supprimée.";
    }
}

$especes = $pdo->query("SELECT ID_ESPECE, NOM_COM FROM espece ORDER BY NOM_COM")->fetchAll(PDO::FETCH_ASSOC);
$filtre_milieu = in_array($_GET['milieu'] ?? '', ['eau_douce', 'mer'], true) ? $_GET['milieu'] : null;
$sql = "SELECT r.*, e.NOM_COM FROM reglementation r LEFT JOIN espece e ON e.ID_ESPECE = r.ID_ESPECE" . ($filtre_milieu ? " WHERE r.MILIEU = ?" : "") . " ORDER BY r.MILIEU, e.NOM_COM IS NOT NULL, e.NOM_COM, r.ID_REGLE";
$stmt = $pdo->prepare($sql);
$stmt->execute($filtre_milieu ? [$filtre_milieu] : []);
$regles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$regle_edit = null;
if ($id_edit = id_ou_null($_GET['modifier'] ?? null)) {
    foreach ($regles as $r) if ((int) $r['ID_REGLE'] === $id_edit) $regle_edit = $r;
}
$reserves = $pdo->query("SELECT ID_RESERVE, NOM, TYPE_ZONE, REGLE, CODE_DEPARTEMENT, DATE_FIN, ACTIF, GEOJSON FROM zone_reserve ORDER BY NOM")->fetchAll(PDO::FETCH_ASSOC);
$v = fn($cle) => e($regle_edit[$cle] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Réglementation</title>
    <?php include './includes/head.php'; ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
</head>
<body class="bg-light pb-5">
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="admin.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;" aria-label="Retour">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center">
            <span class="material-symbols-rounded me-2">gavel</span> Réglementation
        </h1>
    </header>

    <main class="container" style="max-width: 960px;">
        <?php if ($message_succes): ?><div class="alert alert-success rounded-4 border-0 shadow-sm"><?= e($message_succes) ?></div><?php endif; ?>
        <?php if ($message_erreur): ?><div class="alert alert-danger rounded-4 border-0 shadow-sm"><?= e($message_erreur) ?></div><?php endif; ?>

        <div class="alert alert-info rounded-4 border-0 small">
            Ces règles alimentent les alertes de saisie. Une règle propre à un département remplace la règle nationale du même type
            (taille, quota ou période). Pense à les revoir à chaque nouvel arrêté préfectoral et en début d'année (quotas européens).
        </div>

        <!-- FORMULAIRE RÈGLE -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <h2 class="h6 fw-bold mb-3"><?= $regle_edit ? 'Modifier la règle n°' . (int) $regle_edit['ID_REGLE'] : 'Ajouter une règle' ?></h2>
            <form method="POST" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enregistrer_regle">
                <input type="hidden" name="id_regle" value="<?= $v('ID_REGLE') ?>">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Espèce</label>
                    <select name="id_espece" class="form-select">
                        <option value="">Toutes les espèces</option>
                        <?php foreach ($especes as $esp): ?>
                            <option value="<?= (int) $esp['ID_ESPECE'] ?>" <?= ($regle_edit['ID_ESPECE'] ?? null) == $esp['ID_ESPECE'] ? 'selected' : '' ?>><?= e($esp['NOM_COM']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">Milieu</label>
                    <select name="milieu" class="form-select" required>
                        <option value="eau_douce" <?= ($regle_edit['MILIEU'] ?? '') === 'eau_douce' ? 'selected' : '' ?>>Eau douce</option>
                        <option value="mer" <?= ($regle_edit['MILIEU'] ?? '') === 'mer' ? 'selected' : '' ?>>Mer</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">Catégorie</label>
                    <select name="categorie" class="form-select">
                        <option value="">Les deux</option>
                        <option value="1" <?= ($regle_edit['CATEGORIE_PISCICOLE'] ?? '') == 1 ? 'selected' : '' ?>>1re</option>
                        <option value="2" <?= ($regle_edit['CATEGORIE_PISCICOLE'] ?? '') == 2 ? 'selected' : '' ?>>2e</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">Façade</label>
                    <select name="facade" class="form-select">
                        <option value="">Toutes</option>
                        <?php foreach (FACADES as $cle => $lib): ?>
                            <option value="<?= e($cle) ?>" <?= ($regle_edit['FACADE'] ?? '') === $cle ? 'selected' : '' ?>><?= e($lib) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">Département</label>
                    <input name="departement" class="form-control" maxlength="3" placeholder="National" value="<?= $v('CODE_DEPARTEMENT') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">Latitude min</label>
                    <input name="lat_min" class="form-control" inputmode="decimal" placeholder="ex. 48" value="<?= $v('LAT_MIN') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold">Latitude max</label>
                    <input name="lat_max" class="form-control" inputmode="decimal" value="<?= $v('LAT_MAX') ?>">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label small fw-semibold">Taille min (cm)</label>
                    <input name="taille_min" type="number" min="1" class="form-control" value="<?= $v('TAILLE_MIN_CM') ?>">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label small fw-semibold">Quota / jour</label>
                    <input name="quota" type="number" min="1" class="form-control" value="<?= $v('QUOTA_JOUR') ?>">
                </div>
                <div class="col-4 col-md-2">
                    <label class="form-label small fw-semibold">Groupe quota</label>
                    <input name="groupe" class="form-control" pattern="[a-z_]{1,40}" placeholder="carnassiers" value="<?= $v('GROUPE_QUOTA') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Fermeture : début</label>
                    <input name="fermeture_debut" class="form-control" placeholder="02-01 ou last-sun-01+1" value="<?= $v('FERMETURE_DEBUT') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Fermeture : fin</label>
                    <input name="fermeture_fin" class="form-control" placeholder="03-31 ou last-sat-04-1" value="<?= $v('FERMETURE_FIN') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Pendant la fermeture</label>
                    <select name="nature" class="form-select">
                        <option value="interdite" <?= ($regle_edit['FERMETURE_NATURE'] ?? '') === 'interdite' ? 'selected' : '' ?>>Pêche interdite</option>
                        <option value="no_kill" <?= ($regle_edit['FERMETURE_NATURE'] ?? '') === 'no_kill' ? 'selected' : '' ?>>No-kill obligatoire</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-3">
                    <label class="form-check"><input type="checkbox" class="form-check-input" name="marquage" <?= !empty($regle_edit['MARQUAGE']) ? 'checked' : '' ?>> Marquage</label>
                    <label class="form-check"><input type="checkbox" class="form-check-input" name="declaration" <?= !empty($regle_edit['DECLARATION']) ? 'checked' : '' ?>> Déclaration</label>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Note affichée</label>
                    <textarea name="note" class="form-control" rows="2" maxlength="500"><?= $v('NOTE') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Source (texte officiel)</label>
                    <textarea name="source" class="form-control" rows="2" maxlength="255" placeholder="Arrêté préfectoral n°… du …"><?= $v('SOURCE') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary rounded-pill px-4 fw-semibold"><?= $regle_edit ? 'Enregistrer' : 'Ajouter la règle' ?></button>
                    <?php if ($regle_edit): ?><a href="admin_reglementation.php" class="btn btn-light rounded-pill px-4">Annuler</a><?php endif; ?>
                </div>
            </form>
        </div>

        <!-- LISTE DES RÈGLES -->
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="h6 fw-bold text-secondary text-uppercase mb-0">Règles (<?= count($regles) ?>)</h2>
            <div class="btn-group btn-group-sm" role="group" aria-label="Filtrer par milieu">
                <a href="admin_reglementation.php" class="btn <?= !$filtre_milieu ? 'btn-primary' : 'btn-light' ?>">Toutes</a>
                <a href="?milieu=eau_douce" class="btn <?= $filtre_milieu === 'eau_douce' ? 'btn-primary' : 'btn-light' ?>">Eau douce</a>
                <a href="?milieu=mer" class="btn <?= $filtre_milieu === 'mer' ? 'btn-primary' : 'btn-light' ?>">Mer</a>
            </div>
        </div>
        <div class="table-responsive bg-white rounded-4 shadow-sm mb-5">
            <table class="table table-sm align-middle mb-0 small">
                <thead><tr><th>Espèce</th><th>Zone</th><th>Taille</th><th>Quota</th><th>Fermeture</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($regles as $r): ?>
                    <tr class="<?= $r['ACTIF'] ? '' : 'text-muted opacity-50' ?>">
                        <td class="fw-semibold"><?= e($r['NOM_COM'] ?? 'Toutes') ?></td>
                        <td><?= e(MILIEUX[$r['MILIEU']]) ?>
                            <?= $r['CATEGORIE_PISCICOLE'] ? ' · ' . (int) $r['CATEGORIE_PISCICOLE'] . 'e cat.' : '' ?>
                            <?= $r['FACADE'] ? ' · ' . e(FACADES[$r['FACADE']]) : '' ?>
                            <?= $r['LAT_MIN'] !== null ? ' · ≥ ' . e((float) $r['LAT_MIN']) . '°N' : '' ?>
                            <?= $r['LAT_MAX'] !== null ? ' · < ' . e((float) $r['LAT_MAX']) . '°N' : '' ?>
                            <?= $r['CODE_DEPARTEMENT'] ? ' · dép. ' . e($r['CODE_DEPARTEMENT']) : '' ?></td>
                        <td><?= $r['TAILLE_MIN_CM'] ? (int) $r['TAILLE_MIN_CM'] . ' cm' : '–' ?></td>
                        <td><?= $r['QUOTA_JOUR'] ? (int) $r['QUOTA_JOUR'] . ($r['GROUPE_QUOTA'] ? ' (' . e($r['GROUPE_QUOTA']) . ')' : '') : '–' ?></td>
                        <td><?= $r['FERMETURE_DEBUT'] ? e(libelle_borne_regle($r['FERMETURE_DEBUT'])) . ' → ' . e(libelle_borne_regle($r['FERMETURE_FIN'])) . ($r['FERMETURE_NATURE'] === 'no_kill' ? ' (no-kill)' : '') : '–' ?></td>
                        <td class="text-end text-nowrap">
                            <a href="?modifier=<?= (int) $r['ID_REGLE'] ?><?= $filtre_milieu ? '&milieu=' . e($filtre_milieu) : '' ?>" class="btn btn-light btn-sm" aria-label="Modifier"><span class="material-symbols-rounded" style="font-size:18px">edit</span></a>
                            <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="basculer_regle"><input type="hidden" name="id_regle" value="<?= (int) $r['ID_REGLE'] ?>">
                                <button class="btn btn-light btn-sm" aria-label="Activer ou désactiver"><span class="material-symbols-rounded" style="font-size:18px"><?= $r['ACTIF'] ? 'toggle_on' : 'toggle_off' ?></span></button></form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette règle ?');"><?= csrf_field() ?><input type="hidden" name="action" value="supprimer_regle"><input type="hidden" name="id_regle" value="<?= (int) $r['ID_REGLE'] ?>">
                                <button class="btn btn-light btn-sm text-danger" aria-label="Supprimer"><span class="material-symbols-rounded" style="font-size:18px">delete</span></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ZONES DE RÉSERVE -->
        <h2 class="h6 fw-bold text-secondary text-uppercase mb-2" id="reserves">Zones de réserve (<?= count($reserves) ?>)</h2>
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <p class="small text-muted">Clique sur la carte pour placer les sommets du polygone (au moins 3), puis enregistre. Tu peux aussi coller un GeoJSON (Polygon ou MultiPolygon) issu de data.gouv.fr ou d'un arrêté.</p>
            <div id="carte-reserve" class="rounded-4 mb-2" style="height: 320px;"></div>
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-light btn-sm rounded-pill" id="btn-annuler-point"><span class="material-symbols-rounded align-middle" style="font-size:18px">undo</span> Dernier point</button>
                <button type="button" class="btn btn-light btn-sm rounded-pill" id="btn-effacer"><span class="material-symbols-rounded align-middle" style="font-size:18px">delete_sweep</span> Effacer</button>
            </div>
            <form method="POST" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enregistrer_reserve">
                <div class="col-md-6"><label class="form-label small fw-semibold">Nom</label><input name="nom" class="form-control" maxlength="150" required></div>
                <div class="col-6 col-md-3"><label class="form-label small fw-semibold">Type</label>
                    <select name="type_zone" class="form-select">
                        <option value="reserve_peche">Réserve de pêche</option>
                        <option value="aire_marine_protegee">Aire marine protégée</option>
                        <option value="zone_interdite">Zone interdite</option>
                        <option value="autre">Autre</option>
                    </select></div>
                <div class="col-6 col-md-3"><label class="form-label small fw-semibold">Département</label><input name="departement" class="form-control" maxlength="3"></div>
                <div class="col-md-8"><label class="form-label small fw-semibold">Règle affichée</label><input name="regle" class="form-control" maxlength="500" placeholder="Pêche interdite toute l'année (arrêté du …)" required></div>
                <div class="col-md-4"><label class="form-label small fw-semibold">Fin de validité</label><input type="date" name="date_fin" class="form-control"></div>
                <div class="col-12"><label class="form-label small fw-semibold">Source</label><input name="source" class="form-control" maxlength="255"></div>
                <div class="col-12"><label class="form-label small fw-semibold">Géométrie GeoJSON</label><textarea name="geojson" id="geojson" class="form-control font-monospace small" rows="3" required></textarea></div>
                <div class="col-12"><button class="btn btn-primary rounded-pill px-4 fw-semibold">Ajouter la zone</button></div>
            </form>
        </div>

        <?php if ($reserves): ?>
        <div class="d-flex flex-column gap-2 mb-5">
            <?php foreach ($reserves as $z): ?>
                <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between <?= $z['ACTIF'] ? '' : 'opacity-50' ?>">
                    <div>
                        <strong><?= e($z['NOM']) ?></strong> <span class="badge bg-light text-dark"><?= e(str_replace('_', ' ', $z['TYPE_ZONE'])) ?></span>
                        <small class="d-block text-muted"><?= e($z['REGLE']) ?><?= $z['DATE_FIN'] ? ' · jusqu\'au ' . e(date('d/m/Y', strtotime($z['DATE_FIN']))) : '' ?></small>
                    </div>
                    <div class="text-nowrap">
                        <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="basculer_reserve"><input type="hidden" name="id_reserve" value="<?= (int) $z['ID_RESERVE'] ?>">
                            <button class="btn btn-light btn-sm" aria-label="Activer ou désactiver"><span class="material-symbols-rounded" style="font-size:18px"><?= $z['ACTIF'] ? 'toggle_on' : 'toggle_off' ?></span></button></form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette zone ?');"><?= csrf_field() ?><input type="hidden" name="action" value="supprimer_reserve"><input type="hidden" name="id_reserve" value="<?= (int) $z['ID_RESERVE'] ?>">
                            <button class="btn btn-light btn-sm text-danger" aria-label="Supprimer"><span class="material-symbols-rounded" style="font-size:18px">delete</span></button></form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Dessin d'un polygone au clic (sans extension Leaflet) et zones existantes en rouge
        (function () {
            const carte = L.map('carte-reserve').setView([46.6, 2.2], 5);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(carte);
            const existantes = <?= js(array_map(fn($z) => ['nom' => $z['NOM'], 'g' => json_decode($z['GEOJSON'], true)], $reserves)) ?>;
            existantes.forEach(z => z.g && L.geoJSON(z.g, { style: { color: '#b42318', weight: 2, fillOpacity: 0.15 } }).bindTooltip(z.nom).addTo(carte));

            const points = [];
            let trace = null;
            const champ = document.getElementById('geojson');
            function redessiner() {
                if (trace) carte.removeLayer(trace);
                trace = points.length ? L.polygon(points, { color: '#0b5a7a' }).addTo(carte) : null;
                if (points.length >= 3) {
                    const anneau = points.map(p => [Math.round(p[1] * 1e6) / 1e6, Math.round(p[0] * 1e6) / 1e6]);
                    anneau.push(anneau[0]);
                    champ.value = JSON.stringify({ type: 'Polygon', coordinates: [anneau] });
                } else {
                    champ.value = '';
                }
            }
            carte.on('click', e => { points.push([e.latlng.lat, e.latlng.lng]); redessiner(); });
            document.getElementById('btn-annuler-point').addEventListener('click', () => { points.pop(); redessiner(); });
            document.getElementById('btn-effacer').addEventListener('click', () => { points.length = 0; redessiner(); });
        })();
    </script>
</body>
</html>
