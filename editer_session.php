<?php
require_once './includes/auth_bdd.php';

// Vérification de l'ID passé dans l'URL
if (!isset($_GET['id']) or !is_numeric($_GET['id'])) {
    header('Location: profil.php');
    exit();
}

$id_session = (int)$_GET['id'];
$id_user =$_SESSION['user_id'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // =========================================================================
    // 1. TRAITEMENT DU FORMULAIRE (Mise à jour)
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {$date_debut = $_POST['date_debut'];$date_fin = !empty($_POST['date_fin']) ?$_POST['date_fin'] : null;
        $id_type_session = $_POST['id_type_session'];$lat = !empty($_POST['latitude']) ?$_POST['latitude'] : null;
        $lng = !empty($_POST['longitude']) ?$_POST['longitude'] : null;

        $id_spot = !empty($_POST['id_spot']) ?$_POST['id_spot'] : null;

        // Création d'un nouveau spot si demandé
        if (isset($_POST['is_new_spot']) and$_POST['is_new_spot'] === '1' and !empty($_POST['nouveau_nom_spot'])) {$loc = null;
            if ($lat and $lng) {$loc = $lat . ', ' .$lng;
            }
            $stmtSpot =$pdo->prepare("INSERT INTO SPOT (ID_UTILISATEUR, ID_TYPE_SPOT, NOM_SPOT, LOCALISATION) VALUES (?, ?, ?, ?)");
            $stmtSpot->execute([$id_user,$_POST['nouveau_type_spot'], $_POST['nouveau_nom_spot'],$loc]);
            $id_spot =$pdo->lastInsertId();
        } elseif ($id_spot === 'new') {$id_spot = null;
        }

        // Préparation de la valeur de la marée
        $desc_maree_final = null;
        if (!empty($_POST['desc_maree']) and$_POST['desc_maree'] !== 'N/A') {
            $desc_maree_final =$_POST['desc_maree'];
        }

        // Mise à jour de la session globale (Table SESSION_P) incluant Météo & Marée
        $stmtUpdate =$pdo->prepare("
            UPDATE SESSION_P 
            SET DATE_DEBUT = ?, DATE_FIN = ?, ID_TYPE_SESSION = ?, ID_SPOT = ?,
                TEMPERATURE = ?, PRESSION_HPA = ?, VITESSE_VENT = ?, DIREC_VENT = ?, DESCRIP_CIEL = ?, COEFF_MAREE = ?, DESC_MAREE = ?
            WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?
        ");
        
        $stmtUpdate->execute([
            $date_debut,$date_fin, 
            $id_type_session,$id_spot, 
            !empty($_POST['meteo_temp']) ?$_POST['meteo_temp'] : null,
            !empty($_POST['meteo_press']) ?$_POST['meteo_press'] : null,
            !empty($_POST['meteo_wind']) ?$_POST['meteo_wind'] : null,
            !empty($_POST['meteo_direc']) ?$_POST['meteo_direc'] : null,
            !empty($_POST['meteo_ciel']) ?$_POST['meteo_ciel'] : null,
            !empty($_POST['coeff_maree']) ? (int)$_POST['coeff_maree'] : null,$desc_maree_final,
            $id_session,$id_user
        ]);

        // Redirection vers les détails de la session après succès
        header("Location: detail_session.php?id=" . $id_session);
        exit();
    }

    // =========================================================================
    // 2. RÉCUPÉRATION DES DONNÉES DE LA SESSION ACTUELLE
    // =========================================================================
    $stmtSession =$pdo->prepare("
        SELECT s.*, sp.LOCALISATION 
        FROM SESSION_P s
        LEFT JOIN SPOT sp ON s.ID_SPOT = sp.ID_SPOT
        WHERE s.ID_SESSION = ? AND s.ID_UTILISATEUR = ?
    ");
    $stmtSession->execute([$id_session,$id_user]);
    $session =$stmtSession->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        header('Location: profil.php');
        exit();
    }

    // Formatage des dates HTML5
    $val_debut = date('Y-m-d\TH:i', strtotime($session['DATE_DEBUT']));$val_fin = !empty($session['DATE_FIN']) ? date('Y-m-d\TH:i', strtotime($session['DATE_FIN'])) : '';

    // Coordonnées pour la carte : Spot > GPS de la première Prise > Centre France
    $lat_map = 46.603354;
    $lng_map = 1.888334;
    if (!empty($session['LOCALISATION'])) {
        $coords = explode(',',$session['LOCALISATION']);
        if (count($coords) == 2) {
            $lat_map = floatval(trim($coords[0]));
            $lng_map = floatval(trim($coords[1]));
        }
    } else {
        // S'il n'y a pas de spot enregistré, on essaie de lire les GPS de la première prise associée
        $stmtPriseGPS =$pdo->prepare("SELECT LATITUDE, LONGITUDE FROM PRISE WHERE ID_SESSION = ? AND LATITUDE IS NOT NULL LIMIT 1");
        $stmtPriseGPS->execute([$id_session]);
        $priseGPS =$stmtPriseGPS->fetch(PDO::FETCH_ASSOC);
        if ($priseGPS) {
            $lat_map = floatval($priseGPS['LATITUDE']);
            $lng_map = floatval($priseGPS['LONGITUDE']);
        }
    }

    // =========================================================================
    // 3. CHARGEMENT DES LISTES (Spots et Types)
    // =========================================================================
    $spots =$pdo->prepare("SELECT ID_SPOT, NOM_SPOT, LOCALISATION FROM SPOT WHERE ID_UTILISATEUR = ? ORDER BY NOM_SPOT");
    $spots->execute([$id_user]);
    $liste_spots =$spots->fetchAll(PDO::FETCH_ASSOC);

    $types_spot =$pdo->query("SELECT ID_TYPE_SPOT, NOM_TYPE_SPOT FROM TYPE_SPOT ORDER BY NOM_TYPE_SPOT")->fetchAll(PDO::FETCH_ASSOC);
    $types_session =$pdo->query("SELECT ID_TYPE_SESSION, NOM_TYPE_SESSION FROM TYPE_SESSION ORDER BY NOM_TYPE_SESSION")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur : " . $e->getMessage());
}
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
        <a href="detail_session.php?id=<?= $id_session ?>" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center gap-2">
            <span class="material-symbols-rounded">edit_calendar</span> Modifier la session
        </h1>
    </header>

    <main class="container pb-5 mb-5">
        
        <form action="" method="POST">
            
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
                            <option value="<?= $ts['ID_TYPE_SESSION'] ?>" <?= ($session['ID_TYPE_SESSION'] ==$ts['ID_TYPE_SESSION']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ts['NOM_TYPE_SESSION']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Spot / Lieu de pêche</label>
                    
                    <div id="map" class="shadow-sm rounded-3 mb-3 border" style="height: 200px; z-index: 1;"></div>
                    <input type="hidden" name="latitude" id="input_lat" value="<?= $lat_map ?>">
                    <input type="hidden" name="longitude" id="input_lng" value="<?= $lng_map ?>">

                    <select class="form-select form-select-lg bg-light border-0 rounded-3 mb-2" name="id_spot" id="spot-select" onchange="toggleNewSpot()">
                        <option value="">Spot non précisé</option>
                        <?php foreach($liste_spots as$spot): ?>
                            <option value="<?= $spot['ID_SPOT'] ?>" data-loc="<?= htmlspecialchars($spot['LOCALISATION'] ?? '') ?>" <?= ($session['ID_SPOT'] ==$spot['ID_SPOT']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($spot['NOM_SPOT']) ?>
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
                        <input type="text" class="form-control bg-white border-0 mb-2 p-3 rounded-3 shadow-sm" name="nouveau_nom_spot" placeholder="Nom du spot (ex: Pont de l'Europe)">
                        <select class="form-select bg-white border-0 p-3 rounded-3 shadow-sm" name="nouveau_type_spot">
                            <option value="" selected disabled>Type de milieu...</option>
                            <?php foreach($types_spot as$ts): ?>
                                <option value="<?= $ts['ID_TYPE_SPOT'] ?>"><?= htmlspecialchars($ts['NOM_TYPE_SPOT']) ?></option>
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
                    
                    <input type="hidden" name="meteo_temp" id="input_temp" value="<?= htmlspecialchars($session['TEMPERATURE'] ?? '') ?>">
                    <input type="hidden" name="meteo_press" id="input_press" value="<?= htmlspecialchars($session['PRESSION_HPA'] ?? '') ?>">
                    <input type="hidden" name="meteo_wind" id="input_wind" value="<?= htmlspecialchars($session['VITESSE_VENT'] ?? '') ?>">
                    <input type="hidden" name="meteo_direc" id="input_direc" value="<?= htmlspecialchars($session['DIREC_VENT'] ?? '') ?>">
                    <input type="hidden" name="meteo_ciel" id="input_ciel" value="<?= htmlspecialchars($session['DESCRIP_CIEL'] ?? '') ?>">

                    <!-- Badge de visualisation en direct -->
                    <div id="weather-card" class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-primary text-white" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">partly_cloudy_day</span><span id="ui_ciel"><?= htmlspecialchars($session['DESCRIP_CIEL'] ?? '--') ?></span></span>
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">thermostat</span><span id="ui_temp"><?= htmlspecialchars($session['TEMPERATURE'] ?? '--') ?></span>°C</span>
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">compress</span><span id="ui_press"><?= htmlspecialchars($session['PRESSION_HPA'] ?? '--') ?></span> hPa</span>
                            <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">air</span><span id="ui_wind"><?= htmlspecialchars($session['VITESSE_VENT'] ?? '--') ?></span> km/h <span id="ui_direc" class="ms-1 fw-normal text-muted"><?= htmlspecialchars($session['DIREC_VENT'] ?? '') ?></span></span>
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
                            <input type="number" class="form-control bg-light border-0" name="coeff_maree" placeholder="Ex: 85" min="20" max="120" value="<?= htmlspecialchars($session['COEFF_MAREE'] ?? '') ?>">
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