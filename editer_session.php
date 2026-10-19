<?php
require_once './includes/auth_bdd.php';

const CIELS_AUTORISES = ['Soleil dégagé', 'Nuageux', 'Brouillard', 'Pluie', 'Neige', 'Averses', 'Orage', 'Inconnu'];
const DIRECTIONS_VENT = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
const ETATS_MAREE = ['Montante', 'Haute', 'Descendante', 'Basse'];

$id_session = id_ou_null($_GET['id'] ?? null);
if (!$id_session) {
    header('Location: profil.php');
    exit();
}
$id_user = (int) $_SESSION['user_id'];
$pdo = db();
$erreurs = [];

// =========================================================================
// 1. RÉCUPÉRATION DE LA SESSION (doit appartenir à l'utilisateur)
// =========================================================================
$stmtSession = $pdo->prepare("
    SELECT s.*, sp.LOCALISATION 
    FROM session_p s
    LEFT JOIN spot sp ON s.ID_SPOT = sp.ID_SPOT
    WHERE s.ID_SESSION = ? AND s.ID_UTILISATEUR = ?
");
$stmtSession->execute([$id_session, $id_user]);
$session = $stmtSession->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    header('Location: profil.php');
    exit();
}

// =========================================================================
// 2. TRAITEMENT DU FORMULAIRE (Mise à jour)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date_debut = datetime_ou_null($_POST['date_debut'] ?? '');
    $date_fin = datetime_ou_null($_POST['date_fin'] ?? '');
    if (!$date_debut) $erreurs[] = "La date de début est invalide.";
    if ($date_debut && $date_fin && $date_fin < $date_debut) $erreurs[] = "La date de fin doit être après la date de début.";

    $id_type_session = id_ou_null($_POST['id_type_session'] ?? null);
    $stmt = $pdo->prepare("SELECT 1 FROM type_session WHERE ID_TYPE_SESSION = ?");
    $stmt->execute([$id_type_session]);
    if (!$id_type_session || !$stmt->fetchColumn()) $erreurs[] = "Type de pêche invalide.";

    $localisation = localisation_ou_null($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);

    // Spot : uniquement un spot de l'utilisateur, ou un nouveau spot
    $id_spot = null;
    $nouveau_spot = null;
    if (($_POST['id_spot'] ?? '') === 'new') {
        $nom_spot = trim((string) ($_POST['nouveau_nom_spot'] ?? ''));
        $type_spot = id_ou_null($_POST['nouveau_type_spot'] ?? null);
        $stmt = $pdo->prepare("SELECT 1 FROM type_spot WHERE ID_TYPE_SPOT = ?");
        $stmt->execute([$type_spot]);
        if ($nom_spot === '' || mb_strlen($nom_spot) > 50 || !$type_spot || !$stmt->fetchColumn()) {
            $erreurs[] = "Pour un nouveau spot, indique un nom (50 caractères max) et un type de milieu.";
        } else {
            $nouveau_spot = [$nom_spot, $type_spot];
        }
    } elseif ($id = id_ou_null($_POST['id_spot'] ?? null)) {
        $stmt = $pdo->prepare("SELECT 1 FROM spot WHERE ID_SPOT = ? AND ID_UTILISATEUR = ?");
        $stmt->execute([$id, $id_user]);
        if ($stmt->fetchColumn()) $id_spot = $id;
    }

    if (!$erreurs) {
        $pdo->beginTransaction();
        if ($nouveau_spot) {
            $stmtSpot = $pdo->prepare("INSERT INTO spot (ID_UTILISATEUR, ID_TYPE_SPOT, NOM_SPOT, LOCALISATION) VALUES (?, ?, ?, ?)");
            $stmtSpot->execute([$id_user, $nouveau_spot[1], $nouveau_spot[0], $localisation]);
            $id_spot = (int) $pdo->lastInsertId();
        }

        // 0 °C ou 0 km/h sont des valeurs valides : on ne teste plus avec empty()
        $temp = nombre_ou_null($_POST['meteo_temp'] ?? null, -60, 60);
        $wind = nombre_ou_null($_POST['meteo_wind'] ?? null, 0, 400);
        $coeff = id_ou_null($_POST['coeff_maree'] ?? null);

        $stmtUpdate = $pdo->prepare("
            UPDATE session_p 
            SET DATE_DEBUT = ?, DATE_FIN = ?, ID_TYPE_SESSION = ?, ID_SPOT = ?,
                TEMPERATURE = ?, PRESSION_HPA = ?, VITESSE_VENT = ?, DIREC_VENT = ?, DESCRIP_CIEL = ?, COEFF_MAREE = ?, DESC_MAREE = ?
            WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?
        ");
        $stmtUpdate->execute([
            $date_debut, $date_fin,
            $id_type_session, $id_spot,
            $temp === null ? null : (int) round($temp),
            nombre_ou_null($_POST['meteo_press'] ?? null, 800, 1100),
            $wind === null ? null : (int) round($wind),
            in_array($_POST['meteo_direc'] ?? '', DIRECTIONS_VENT, true) ? $_POST['meteo_direc'] : null,
            in_array($_POST['meteo_ciel'] ?? '', CIELS_AUTORISES, true) ? $_POST['meteo_ciel'] : null,
            ($coeff !== null && $coeff >= 20 && $coeff <= 120) ? $coeff : null,
            in_array($_POST['desc_maree'] ?? '', ETATS_MAREE, true) ? $_POST['desc_maree'] : null,
            $id_session, $id_user
        ]);

        // Le spot a pu changer : milieu, façade et département suivent (analyse communautaire, réglementation)
        if ($id_spot) {
            $pdo->prepare("
                UPDATE session_p s JOIN spot sp ON sp.ID_SPOT = s.ID_SPOT
                SET s.MILIEU = CASE WHEN sp.ID_TYPE_SPOT <= 8 THEN 'eau_douce' WHEN sp.ID_TYPE_SPOT = 9 THEN 'estuaire' ELSE 'mer' END,
                    s.CODE_DEPARTEMENT = COALESCE(sp.CODE_DEPARTEMENT, s.CODE_DEPARTEMENT),
                    s.FACADE = CASE WHEN sp.ID_TYPE_SPOT <= 8 THEN NULL ELSE s.FACADE END
                WHERE s.ID_SESSION = ? AND s.ID_UTILISATEUR = ?
            ")->execute([$id_session, $id_user]);
        }

        // Les prises gardent la même date que la session
        $stmtPrises = $pdo->prepare("UPDATE prise SET DATE_HEURE = CONCAT(DATE(?), ' ', TIME(DATE_HEURE)) WHERE ID_SESSION = ? AND DATE_HEURE IS NOT NULL");
        $stmtPrises->execute([$date_debut, $id_session]);
        $pdo->commit();

        header("Location: detail_session.php?id=" . $id_session);
        exit();
    }
}

// Formatage des dates HTML5
$val_debut = date('Y-m-d\TH:i', strtotime($session['DATE_DEBUT']));
$val_fin = !empty($session['DATE_FIN']) ? date('Y-m-d\TH:i', strtotime($session['DATE_FIN'])) : '';

// Coordonnées pour la carte : Spot > GPS de la première Prise > Centre France
$lat_map = 46.603354;
$lng_map = 1.888334;
if ($coords = lire_localisation($session['LOCALISATION'])) {
    [$lat_map, $lng_map] = $coords;
} else {
    $stmtPriseGPS = $pdo->prepare("SELECT LATITUDE, LONGITUDE FROM prise WHERE ID_SESSION = ? AND LATITUDE IS NOT NULL LIMIT 1");
    $stmtPriseGPS->execute([$id_session]);
    if ($priseGPS = $stmtPriseGPS->fetch(PDO::FETCH_ASSOC)) {
        $lat_map = (float) $priseGPS['LATITUDE'];
        $lng_map = (float) $priseGPS['LONGITUDE'];
    }
}

// Listes (Spots et Types)
$spots = $pdo->prepare("SELECT ID_SPOT, NOM_SPOT, LOCALISATION FROM spot WHERE ID_UTILISATEUR = ? ORDER BY NOM_SPOT");
$spots->execute([$id_user]);
$liste_spots = $spots->fetchAll(PDO::FETCH_ASSOC);

$types_spot = $pdo->query("SELECT ID_TYPE_SPOT, NOM_TYPE_SPOT FROM type_spot ORDER BY NOM_TYPE_SPOT")->fetchAll(PDO::FETCH_ASSOC);
$types_session = $pdo->query("SELECT ID_TYPE_SESSION, NOM_TYPE_SESSION FROM type_session ORDER BY NOM_TYPE_SESSION")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier la Session - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

</head>
<body class="bg-light">

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative" style="background: linear-gradient(135deg, #2a5298 0%, #1e3c72 100%); border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
        <a href="detail_session.php?id=<?= (int) $id_session ?>" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center gap-2">
            <span class="material-symbols-rounded">edit_calendar</span> Modifier la session
        </h1>
    </header>

    <main class="container pb-5 mb-5">
        
        <form action="" method="POST">
            <?= csrf_field() ?>
            <?php foreach ($erreurs as $err): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm small"><?= e($err) ?></div>
            <?php endforeach; ?>
            
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <h5 class="fw-bold mb-4 text-dark border-bottom pb-2">Dates de la session</h5>
                
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label text-secondary small fw-medium">Début</label>
                        <input type="datetime-local" class="form-control form-control-lg bg-light border-0 rounded-3" name="date_debut" value="<?= $val_debut ?>" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label text-secondary small fw-medium">Fin (Optionnel)</label>
                        <input type="datetime-local" class="form-control form-control-lg bg-light border-0 rounded-3" name="date_fin" value="<?= $val_fin ?>">
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <h5 class="fw-bold mb-4 text-dark border-bottom pb-2">Type et Lieu</h5>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Type de pêche</label>
                    <select class="form-select form-select-lg bg-light border-0 rounded-3" name="id_type_session" required>
                        <option value="" disabled>Choisir...</option>
                        <?php foreach($types_session as$ts): ?>
                            <option value="<?= (int) $ts['ID_TYPE_SESSION'] ?>" <?= ($session['ID_TYPE_SESSION'] ==$ts['ID_TYPE_SESSION']) ? 'selected' : '' ?>>
                                <?= e($ts['NOM_TYPE_SESSION']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Spot / Lieu de pêche</label>
                    
                    <div id="map" class="shadow-sm rounded-3 mb-3 border" style="height: 200px; z-index: 1;"></div>
                    <input type="hidden" name="latitude" id="input_lat" value="<?= e($lat_map) ?>">
                    <input type="hidden" name="longitude" id="input_lng" value="<?= e($lng_map) ?>">

                    <select class="form-select form-select-lg bg-light border-0 rounded-3 mb-2" name="id_spot" id="spot-select" onchange="toggleNewSpot()">
                        <option value="">Spot non précisé</option>
                        <?php foreach($liste_spots as$spot): ?>
                            <option value="<?= (int) $spot['ID_SPOT'] ?>" data-loc="<?= e($spot['LOCALISATION'] ?? '') ?>" <?= ($session['ID_SPOT'] ==$spot['ID_SPOT']) ? 'selected' : '' ?>>
                                <?= e($spot['NOM_SPOT']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="new" class="fw-bold text-primary">+ Créer un nouveau spot à cet endroit</option>
                    </select>

                    <div id="new-spot-panel" class="bg-primary bg-opacity-10 border border-primary-subtle rounded-4 p-3 mt-3" style="display: none;">
                        <input type="hidden" name="is_new_spot" id="is_new_spot" value="0">
                        <div class="d-flex align-items-center mb-3">
                            <span class="material-symbols-rounded text-primary me-2">add_location_alt</span>
                            <span class="fw-bold text-primary">Nouveau Spot</span>
                        </div>
                        <input type="text" class="form-control bg-white border-0 mb-2 p-3 rounded-3 shadow-sm" name="nouveau_nom_spot" maxlength="50" placeholder="Nom du spot (ex: Pont de l'Europe)">
                        <select class="form-select bg-white border-0 p-3 rounded-3 shadow-sm" name="nouveau_type_spot">
                            <option value="" selected disabled>Type de milieu...</option>
                            <?php foreach($types_spot as$ts): ?>
                                <option value="<?= (int) $ts['ID_TYPE_SPOT'] ?>"><?= e($ts['NOM_TYPE_SPOT']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted mt-2 d-block"><span class="material-symbols-rounded align-middle" style="font-size:14px;">touch_app</span> Déplace le marqueur sur la carte pour définir sa position.</small>
                    </div>
                </div>
            </div>

            <!-- NOUVELLE SECTION : CONDITIONS (MÉTÉO & MARÉE) -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <h5 class="fw-bold mb-2 text-dark border-bottom pb-2 d-flex justify-content-between align-items-center">
                    Météo & Marée
                    <span class="material-symbols-rounded text-muted">thermostat</span>
                </h5>
                <p class="small text-secondary mb-4">Déplacez le marqueur sur la carte pour actualiser les données météorologiques de votre session.</p>

                <div id="meteo-panel">
                    
                    <input type="hidden" name="meteo_temp" id="input_temp" value="<?= e($session['TEMPERATURE'] ?? '') ?>">
                    <input type="hidden" name="meteo_press" id="input_press" value="<?= e($session['PRESSION_HPA'] ?? '') ?>">
                    <input type="hidden" name="meteo_wind" id="input_wind" value="<?= e($session['VITESSE_VENT'] ?? '') ?>">
                    <input type="hidden" name="meteo_direc" id="input_direc" value="<?= e($session['DIREC_VENT'] ?? '') ?>">
                    <input type="hidden" name="meteo_ciel" id="input_ciel" value="<?= e($session['DESCRIP_CIEL'] ?? '') ?>">

                    <!-- Badge de visualisation en direct -->
                    <div id="weather-card" class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-primary text-white" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">partly_cloudy_day</span><span id="ui_ciel"><?= e($session['DESCRIP_CIEL'] ?? '--') ?></span></span>
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">thermostat</span><span id="ui_temp"><?= e($session['TEMPERATURE'] ?? '--') ?></span>°C</span>
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">compress</span><span id="ui_press"><?= e($session['PRESSION_HPA'] ?? '--') ?></span> hPa</span>
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">air</span><span id="ui_wind"><?= e($session['VITESSE_VENT'] ?? '--') ?></span> km/h <span id="ui_direc" class="ms-1 fw-normal text-muted"><?= e($session['DIREC_VENT'] ?? '') ?></span></span>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3 bg-light p-3 rounded-4 d-flex align-items-center">
                        <input class="form-check-input ms-0 me-3" type="checkbox" id="toggle-maree" style="transform: scale(1.3);" onchange="document.getElementById('maree-inputs').style.display = this.checked ? 'flex' : 'none';" <?php if(!empty($session['DESC_MAREE']) and$session['DESC_MAREE'] !== 'N/A') echo 'checked'; ?>>
                        <label class="form-check-label fw-bold text-dark mb-0" for="toggle-maree">🌊 Session en Mer (Activer la marée)</label>
                    </div>

                    <div id="maree-inputs" class="row g-2 mb-2" style="<?php if(empty($session['DESC_MAREE']) or$session['DESC_MAREE'] === 'N/A') echo 'display: none;'; ?>">
                        <div class="col-7">
                            <label class="form-label text-secondary small fw-medium">État de la Marée</label>
                            <select class="form-select bg-light border-0" name="desc_maree">
                                <option value="N/A" <?= ($session['DESC_MAREE'] ?? '') === 'N/A' ? 'selected' : '' ?>>Non renseigné</option>
                                <option value="Montante" <?= ($session['DESC_MAREE'] ?? '') === 'Montante' ? 'selected' : '' ?>>Marée Montante</option>
                                <option value="Haute" <?= ($session['DESC_MAREE'] ?? '') === 'Haute' ? 'selected' : '' ?>>Pleine Mer (Haute)</option>
                                <option value="Descendante" <?= ($session['DESC_MAREE'] ?? '') === 'Descendante' ? 'selected' : '' ?>>Marée Descendante</option>
                                <option value="Basse" <?= ($session['DESC_MAREE'] ?? '') === 'Basse' ? 'selected' : '' ?>>Basse Mer</option>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label text-secondary small fw-medium">Coefficient</label>
                            <input type="number" class="form-control bg-light border-0" name="coeff_maree" placeholder="Ex: 85" min="20" max="120" value="<?= e($session['COEFF_MAREE'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow py-3">
                    <span class="material-symbols-rounded align-middle me-2">save</span> Enregistrer les modifications
                </button>
            </div>
            
        </form>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="script/meteo_map.js"></script>
</body>
</html>