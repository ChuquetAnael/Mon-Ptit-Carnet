<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';
require_once './BDD/BDD_session.php'; // Inclusion des fonctions séparées

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

$etape = isset($_GET['etape']) ? (int)$_GET['etape'] : 1;

// =========================================================================
// TRAITEMENT POST DES FORMULAIRES (AVANT AFFICHAGE)
// =========================================================================

// POST ÉTAPE 1 : Upload cumulatif des photos
if ($etape === 1 and $_SERVER["REQUEST_METHOD"] == "POST") {
    $id_user = $_SESSION['user_id'];
    $dossier_temp = 'uploads/user_' . $id_user . '/';
    if (!is_dir($dossier_temp)) mkdir($dossier_temp, 0777, true);

    $_SESSION['nouvelle_session'] = [
        'photos' => [],
        'date_heure' => date('Y-m-d\TH:i'),
        'lat' => null, 
        'lng' => null
    ];

    if (!empty($_FILES['photos']['name'][0])) {
        foreach($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                $nom_fichier = uniqid() . '.jpg';
                $chemin_final = $dossier_temp . $nom_fichier;
                
                // 1. LECTURE DE L'EXIF AVANT COMPRESSION
                // On lit les données sur le fichier temporaire, car la compression supprime l'EXIF
                if ($key === 0) {
                    $exif = @exif_read_data($tmp_name);
                    if ($exif !== false) {
                        if (isset($exif['DateTimeOriginal'])) {
                            $date_exif = DateTime::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
                            if ($date_exif) $_SESSION['nouvelle_session']['date_heure'] = $date_exif->format('Y-m-d\TH:i');
                        }
                        if (isset($exif['GPSLatitude']) and isset($exif['GPSLongitude'])) {
                            $_SESSION['nouvelle_session']['lat'] = getGps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                            $_SESSION['nouvelle_session']['lng'] = getGps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
                        }
                    }
                }

                // 2. COMPRESSION ET SAUVEGARDE
                // Max 1200px de large et qualité JPEG 80%
                if (compresserImage($tmp_name, $chemin_final, 80, 1200)) {
                    $_SESSION['nouvelle_session']['photos'][] = $chemin_final;
                } else {
                    // Sécurité : si le format n'est pas supporté par GD, on fait un upload classique
                    if (move_uploaded_file($tmp_name, $chemin_final)) {
                        $_SESSION['nouvelle_session']['photos'][] = $chemin_final;
                    }
                }
            }
        }
    }
    header('Location: nouvelle_session.php?etape=2');
    exit();
}

// POST ÉTAPE 2 : Sauvegarde en mémoire ou VALIDATION DIRECTE SI CAPOT
if ($etape === 2 and $_SERVER["REQUEST_METHOD"] == "POST") {
    $_SESSION['nouvelle_session']['step2'] = [
        'date_debut' => $_POST['date_debut'],
        'date_fin' => !empty($_POST['date_fin']) ? $_POST['date_fin'] : null,
        'id_type_session' => $_POST['id_type_session'],
        'id_spot' => !empty($_POST['id_spot']) ? $_POST['id_spot'] : null,
        'is_new_spot' => $_POST['is_new_spot'] ?? '0',
        'nouveau_nom_spot' => $_POST['nouveau_nom_spot'] ?? '',
        'nouveau_type_spot' => $_POST['nouveau_type_spot'] ?? '',
        'lat_final' => !empty($_POST['latitude']) ? $_POST['latitude'] : null,
        'lng_final' => !empty($_POST['longitude']) ? $_POST['longitude'] : null,
        'temp' => $_POST['meteo_temp'] ?? null,
        'press' => $_POST['meteo_press'] ?? null,
        'wind' => $_POST['meteo_wind'] ?? null
    ];

    // NOUVEAUTÉ : SI SESSION CAPOT, ON ENREGISTRE LA SESSION ET ON SKIP L'ÉTAPE 3
    if (isset($_POST['is_capot'])) {
        $s2 = $_SESSION['nouvelle_session']['step2'];
        $id_user = $_SESSION['user_id'];

        $pdo->beginTransaction();
        try {
            $id_spot = null;
            if ($s2['is_new_spot'] == '1' && !empty($s2['nouveau_nom_spot'])) {
                $loc = ($s2['lat_final'] && $s2['lng_final']) ? $s2['lat_final'] . ', ' . $s2['lng_final'] : null;
                $stmtSpot = $pdo->prepare("INSERT INTO SPOT (ID_UTILISATEUR, ID_TYPE_SPOT, NOM_SPOT, LOCALISATION) VALUES (?, ?, ?, ?)");
                $stmtSpot->execute([$id_user, $s2['nouveau_type_spot'], $s2['nouveau_nom_spot'], $loc]);
                $id_spot = $pdo->lastInsertId();
            } elseif ($s2['id_spot'] !== 'new' && !empty($s2['id_spot'])) {
                $id_spot = $s2['id_spot'];
            }

            $stmtSess = $pdo->prepare("INSERT INTO SESSION_P (ID_UTILISATEUR, ID_TYPE_SESSION, ID_SPOT, DATE_DEBUT, DATE_FIN) VALUES (?, ?, ?, ?, ?)");
            $stmtSess->execute([$id_user, $s2['id_type_session'], $id_spot, $s2['date_debut'], $s2['date_fin']]);

            $pdo->commit();
            header('Location: nouvelle_session.php?etape=4');
            exit();
        } catch(Exception $e) {
            $pdo->rollBack();
            die("Erreur lors de l'enregistrement : " . $e->getMessage());
        }
    }

    // Sinon, on continue vers les prises normalement
    header('Location: nouvelle_session.php?etape=3');
    exit();
}

// POST ÉTAPE 3 : L'ENREGISTREMENT FINAL EN BASE DE DONNÉES !
if ($etape === 3 and $_SERVER["REQUEST_METHOD"] == "POST") {
    $s2 = $_SESSION['nouvelle_session']['step2'];
    $id_user = $_SESSION['user_id'];
    $date_heure = $_SESSION['nouvelle_session']['date_heure']; // Heure de la 1ère photo

    $pdo->beginTransaction(); // On commence une transaction sécurisée
    try {
        // 1. Enregistrement du nouveau Spot si demandé
        $id_spot = null;
        if ($s2['is_new_spot'] == '1' && !empty($s2['nouveau_nom_spot'])) {
            $loc = ($s2['lat_final'] && $s2['lng_final']) ? $s2['lat_final'] . ', ' . $s2['lng_final'] : null;
            $stmtSpot = $pdo->prepare("INSERT INTO SPOT (ID_UTILISATEUR, ID_TYPE_SPOT, NOM_SPOT, LOCALISATION) VALUES (?, ?, ?, ?)");
            $stmtSpot->execute([$id_user, $s2['nouveau_type_spot'], $s2['nouveau_nom_spot'], $loc]);
            $id_spot = $pdo->lastInsertId();
        } elseif ($s2['id_spot'] !== 'new' && !empty($s2['id_spot'])) {
            $id_spot = $s2['id_spot'];
        }

        // 2. Enregistrement de la Session
        $stmtSess = $pdo->prepare("INSERT INTO SESSION_P (ID_UTILISATEUR, ID_TYPE_SESSION, ID_SPOT, DATE_DEBUT, DATE_FIN) VALUES (?, ?, ?, ?, ?)");
        $stmtSess->execute([$id_user, $s2['id_type_session'], $id_spot, $s2['date_debut'], $s2['date_fin']]);
        $id_session = $pdo->lastInsertId();

        // 3. Enregistrement des nouveaux leurres et appâts
        $map_new_leurres = []; 
        if (!empty($_POST['new_leurres'])) {
            $stmtL = $pdo->prepare("INSERT INTO LEURRE (ID_UTILISATEUR, ID_TYPE, NOM_LEURRE, GRAMMAGE, COLORIS) VALUES (?, ?, ?, ?, ?)");
            foreach ($_POST['new_leurres'] as $temp_id => $l) {
                $stmtL->execute([$id_user, $l['type'], $l['nom'], !empty($l['poids']) ? $l['poids'] : null, !empty($l['couleur']) ? $l['couleur'] : null]);
                $map_new_leurres[$temp_id] = $pdo->lastInsertId();
            }
        }
        $map_new_appats = [];
        if (!empty($_POST['new_appats'])) {
            $stmtA = $pdo->prepare("INSERT INTO APPAT (ID_UTILISATEUR, ID_TYPE_APPAT, NOM_APPAT) VALUES (?, ?, ?)");
            foreach ($_POST['new_appats'] as $temp_id => $a) {
                $stmtA->execute([$id_user, $a['type'], $a['nom']]);
                $map_new_appats[$temp_id] = $pdo->lastInsertId();
            }
        }

        // 4. Enregistrement des Prises
        if (!empty($_POST['prises'])) {
            $stmtPrise = $pdo->prepare("INSERT INTO PRISE (ID_SESSION, ID_ESPECE, ID_LEURRE, ID_APPAT, ID_TECHNIQUE, TAILLE_CM, POIDS_KG, RELACHE, PHOTO_CHEMIN, LATITUDE, LONGITUDE, TEMPERATURE, PRESSION_HPA, VITESSE_VENT, DATE_HEURE) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($_POST['prises'] as $p) {
                if (empty($p['id_espece'])) continue;

                $id_leurre_final = !empty($p['id_leurre']) ? $p['id_leurre'] : null;
                if (strpos($id_leurre_final, 'new_l_') === 0) $id_leurre_final = $map_new_leurres[$id_leurre_final];

                $id_appat_final = !empty($p['id_appat']) ? $p['id_appat'] : null;
                if (strpos($id_appat_final, 'new_a_') === 0) $id_appat_final = $map_new_appats[$id_appat_final];

                $stmtPrise->execute([
                    $id_session, $p['id_espece'], $id_leurre_final, $id_appat_final, 
                    !empty($p['id_technique']) ? $p['id_technique'] : null,
                    !empty($p['taille']) ? $p['taille'] : null, 
                    !empty($p['poids']) ? $p['poids'] : null, 
                    isset($p['relache']) ? 1 : 0, 
                    !empty($p['photo']) && $p['photo'] !== 'no_photo' ? $p['photo'] : null,
                    $s2['lat_final'], $s2['lng_final'], $s2['temp'], $s2['press'], $s2['wind'], $date_heure
                ]);
            }
        }

        $pdo->commit(); // Tout s'est bien passé, on valide l'insertion
        header('Location: nouvelle_session.php?etape=4');
        exit();

    } catch(Exception $e) {
        $pdo->rollBack(); // En cas d'erreur, on annule tout !
        die("Erreur lors de l'enregistrement : " . $e->getMessage());
    }
}

// =========================================================================
// PRÉPARATION DES DONNÉES POUR L'AFFICHAGE HTML
// =========================================================================
$session_data = $_SESSION['nouvelle_session'] ?? [];

if ($etape === 2) {
    $spots = $pdo->prepare("SELECT ID_SPOT, NOM_SPOT FROM SPOT WHERE ID_UTILISATEUR = ? ORDER BY NOM_SPOT");
    $spots->execute([$_SESSION['user_id']]);
    $spots = $spots->fetchAll(PDO::FETCH_ASSOC);
    
    $types_spot = $pdo->query("SELECT ID_TYPE_SPOT, NOM_TYPE_SPOT FROM TYPE_SPOT ORDER BY NOM_TYPE_SPOT")->fetchAll(PDO::FETCH_ASSOC);
    $types_session = $pdo->query("SELECT ID_TYPE_SESSION, NOM_TYPE_SESSION FROM TYPE_SESSION ORDER BY NOM_TYPE_SESSION")->fetchAll(PDO::FETCH_ASSOC);
}

if ($etape === 3) {
    // On force l'extraction propre (sans caractères spéciaux buggés) pour le JavaScript
    $especes = $pdo->query("SELECT ID_ESPECE, NOM_COM, ICONE_CHEMIN FROM ESPECE ORDER BY NOM_COM")->fetchAll(PDO::FETCH_ASSOC);
    $techniques = $pdo->query("SELECT ID_TECHNIQUE, NOM_TECHNIQUE FROM TECHNIQUE ORDER BY NOM_TECHNIQUE")->fetchAll(PDO::FETCH_ASSOC);
    $types_leurre = $pdo->query("SELECT * FROM TYPE_LEURRE")->fetchAll(PDO::FETCH_NUM); 
    $types_appat = $pdo->query("SELECT * FROM TYPE_APPAT")->fetchAll(PDO::FETCH_NUM); 

    $leurres = $pdo->prepare("SELECT ID_LEURRE, NOM_LEURRE, ID_TYPE, GRAMMAGE, COLORIS FROM LEURRE WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_LEURRE");
    $leurres->execute([$_SESSION['user_id']]);
    $leurres = $leurres->fetchAll(PDO::FETCH_ASSOC);

    $appats = $pdo->prepare("SELECT ID_APPAT, NOM_APPAT, ID_TYPE_APPAT FROM APPAT WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_APPAT");
    $appats->execute([$_SESSION['user_id']]);
    $appats = $appats->fetchAll(PDO::FETCH_ASSOC);

    $photos = !empty($session_data['photos']) ? $session_data['photos'] : [];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Session - Mon Carnet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <?php if ($etape === 2): ?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" /><?php endif; ?>
    <link href="css/style.css" rel="stylesheet">
</head>
<body>

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <?php if ($etape < 4): ?>
            <a href="<?= ($etape > 1) ? 'nouvelle_session.php?etape='.($etape-1) : 'accueil.php' ?>" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
                <span class="material-symbols-rounded"><?= ($etape > 1) ? 'arrow_back_ios_new' : 'close' ?></span>
            </a>
            <h1 class="h4 mb-0 fw-semibold">Étape <?= $etape ?>/3</h1>
            <div class="progress mt-3 mx-auto" style="height: 6px; width: 60%; background-color: rgba(255,255,255,0.2);">
                <div class="progress-bar bg-info" style="width: <?= ($etape/3)*100 ?>%;"></div>
            </div>
        <?php else: ?>
            <h1 class="h4 mb-0 fw-semibold">Terminé !</h1>
        <?php endif; ?>
    </header>

    <main class="container pb-5 mb-5">
        
        <?php if ($etape === 1): ?>
        <!-- ================= ÉTAPE 1 : PHOTOS (SÉLECTION MULTIPLE AVANCÉE) ================= -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-3 text-center">
            <h5 class="fw-bold mb-3 text-dark">Vos prises du jour</h5>
            <p class="text-muted small mb-4">Uploadez vos photos une par une ou par lot.</p>
            <form action="nouvelle_session.php?etape=1" method="POST" enctype="multipart/form-data" id="form-photos">
                <div class="mb-4">
                    <!-- Faux Input (Proxy) pour ne pas vider la sélection -->
                    <label for="photos-proxy" class="btn btn-light p-4 rounded-4 w-100 border-2 border-primary border-dashed d-flex flex-column align-items-center" style="border-style: dashed;">
                        <span class="material-symbols-rounded text-primary mb-2" style="font-size: 40px;">add_a_photo</span>
                        <span class="fw-semibold text-primary">Ajouter des photos</span>
                        <input type="file" id="photos-proxy" multiple accept="image/*" class="d-none">
                    </label>
                    
                    <!-- Vrai input caché envoyé au serveur -->
                    <input type="file" id="photos-real" name="photos[]" multiple class="d-none">
                    
                    <div id="preview-container" class="d-flex flex-wrap gap-2 mt-3 justify-content-center"></div>
                </div>
                <div class="d-grid mt-4">
                    <button type="submit" id="submit-step-1" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit">
                        Continuer sans photo <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>
                    </button>
                </div>
            </form>
        </div>

        <?php elseif ($etape === 2): ?>
        <!-- ================= ÉTAPE 2 : MÉTÉO ET SPOT ================= -->
        <form action="nouvelle_session.php?etape=2" method="POST">
            <h5 class="fw-bold mb-3 text-dark">Localisation & Météo</h5>
            <div id="map" class="shadow-sm rounded-4 mb-3" style="height: 200px; z-index: 1;"></div>
            
            <input type="hidden" name="latitude" id="input_lat" value="<?= htmlspecialchars($session_data['lat'] ?? '') ?>">
            <input type="hidden" name="longitude" id="input_lng" value="<?= htmlspecialchars($session_data['lng'] ?? '') ?>">
            <input type="hidden" name="meteo_temp" id="input_temp">
            <input type="hidden" name="meteo_press" id="input_press">
            <input type="hidden" name="meteo_wind" id="input_wind">
            
            <div id="weather-card" class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-primary text-white" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); display: none;">
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">thermostat</span><span id="ui_temp">--</span>°C</span>
                    <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">compress</span><span id="ui_press">--</span> hPa</span>
                    <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">air</span><span id="ui_wind">--</span> km/h</span>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-5">
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <label class="form-label text-secondary small fw-medium">Début</label>
                        <input type="datetime-local" class="form-control bg-light border-0" name="date_debut" value="<?= htmlspecialchars($session_data['date_heure'] ?? '') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-secondary small fw-medium">Fin (Optionnel)</label>
                        <input type="datetime-local" class="form-control bg-light border-0" name="date_fin">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Type de pêche</label>
                    <select class="form-select bg-light border-0" name="id_type_session" required>
                        <option value="" selected disabled>Choisir...</option>
                        <?php foreach($types_session as $ts): ?>
                            <option value="<?= $ts['ID_TYPE_SESSION'] ?>"><?= htmlspecialchars($ts['NOM_TYPE_SESSION']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Spot / Lieu</label>
                    <select class="form-select bg-light border-0 mb-2" name="id_spot" id="spot-select" onchange="toggleNewSpot()">
                        <option value="" selected>Spot inconnu</option>
                        <?php foreach($spots as $spot): ?>
                            <option value="<?= $spot['ID_SPOT'] ?>"><?= htmlspecialchars($spot['NOM_SPOT']) ?></option>
                        <?php endforeach; ?>
                        <option value="new" class="fw-bold text-primary">+ Créer un nouveau spot</option>
                    </select>

                    <div id="new-spot-panel" class="bg-white border rounded-4 p-3 mt-2 shadow-sm" style="display: none;">
                        <input type="hidden" name="is_new_spot" id="is_new_spot" value="0">
                        <input type="text" class="form-control bg-light border-0 mb-2" name="nouveau_nom_spot" placeholder="Nom du spot">
                        <select class="form-select bg-light border-0" name="nouveau_type_spot">
                            <option value="" selected disabled>Type de milieu...</option>
                            <?php foreach($types_spot as $ts): ?>
                                <option value="<?= $ts['ID_TYPE_SPOT'] ?>"><?= htmlspecialchars($ts['NOM_TYPE_SPOT']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- NOUVEAU BOUTON CAPOT -->
                <div class="form-check form-switch mt-4 bg-light p-3 rounded-4 d-flex align-items-center">
                    <input class="form-check-input ms-0 me-3" type="checkbox" name="is_capot" id="is_capot" style="transform: scale(1.3);" onchange="toggleCapotBtn()">
                    <label class="form-check-label fw-bold text-dark mb-0" for="is_capot">Session Bredouille (Capot)</label>
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" id="btn-submit-step2" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit">
                        Continuer <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>
                    </button>
                </div>
            </div>
        </form>

        <?php elseif ($etape === 3): ?>
        <!-- ================= ÉTAPE 3 : GÉNÉRATION JS DES PRISES & BOITE DE PECHE ================= -->
        <h5 class="fw-bold mb-3 text-dark text-center">Détail des prises</h5>
        <p class="text-muted small text-center mb-4">Identifiez vos poissons et le matériel utilisé.</p>

        <form id="form-etape-3" action="nouvelle_session.php?etape=3" method="POST">
            
            <div id="catches-container"></div>
            
            <button type="button" class="btn btn-outline-primary w-100 rounded-pill border-2 fw-semibold mb-4 py-3" onclick="addCatchCard('no_photo')">
                <span class="material-symbols-rounded align-middle me-2">add_circle</span> Ajouter une prise sans photo
            </button>

            <div id="new-items-container"></div>

            <div class="d-grid mt-4">
                <button type="button" id="submit-final" class="btn btn-success btn-lg rounded-pill fw-semibold shadow-sm">
                    Terminer et Sauvegarder <span class="material-symbols-rounded align-middle ms-2">check_circle</span>
                </button>
            </div>
        </form>

        <!-- MODALE FULLSCREEN : BOITE DE PÊCHE -->
        <div class="modal fade" id="tackleBoxModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-md-down modal-dialog-scrollable">
                <div class="modal-content border-0">
                    <div class="modal-header border-0 shadow-sm bg-primary text-white pb-3 pt-4">
                        <h5 class="modal-title fw-bold"><span class="material-symbols-rounded align-middle me-2">phishing</span>Ma Boîte de Pêche</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body bg-light pt-4">
                        
                        <ul class="nav nav-pills mb-4 nav-fill" id="tackle-tab" role="tablist">
                            <li class="nav-item" role="presentation"><button class="nav-link active rounded-pill fw-medium" data-bs-toggle="pill" data-bs-target="#tab-leurres" type="button">Leurres</button></li>
                            <li class="nav-item" role="presentation"><button class="nav-link rounded-pill fw-medium" data-bs-toggle="pill" data-bs-target="#tab-appats" type="button">Appâts</button></li>
                        </ul>
                        
                        <div class="tab-content" id="tackle-tabContent">
                            <div class="tab-pane fade show active" id="tab-leurres">
                                <button class="btn btn-outline-primary w-100 rounded-3 mb-4 fw-bold border-2 border-dashed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewLeurre">
                                    + Créer un nouveau leurre
                                </button>
                                
                                <div class="collapse mb-4" id="collapseNewLeurre">
                                    <div class="card card-body border-0 shadow-sm rounded-4">
                                        <form id="form-new-leurre">
                                            <input type="text" class="form-control bg-light border-0 mb-3" id="new-l-nom" placeholder="Nom (ex: Black Minnow 120)">
                                            <select class="form-select bg-light border-0 mb-3" id="new-l-type">
                                                <option value="" selected disabled>Type de leurre...</option>
                                                <?php foreach($types_leurre as $tl): ?>
                                                    <option value="<?= $tl[0] ?>"><?= htmlspecialchars($tl[1]) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="row g-2 mb-4">
                                                <div class="col-6"><input type="number" step="0.1" class="form-control bg-light border-0" id="new-l-poids" placeholder="Poids (g)"></div>
                                                <div class="col-6"><input type="text" class="form-control bg-light border-0" id="new-l-couleur" placeholder="Coloris"></div>
                                            </div>
                                            <button type="button" class="btn btn-primary rounded-pill w-100 fw-semibold" onclick="saveNewLeurre()">Ajouter à la boîte</button>
                                        </form>
                                    </div>
                                </div>
                                <div id="list-leurres"></div>
                            </div>
                            
                            <div class="tab-pane fade" id="tab-appats">
                                <button class="btn btn-outline-primary w-100 rounded-3 mb-4 fw-bold border-2 border-dashed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewAppat">
                                    + Créer un nouvel appât
                                </button>
                                
                                <div class="collapse mb-4" id="collapseNewAppat">
                                    <div class="card card-body border-0 shadow-sm rounded-4">
                                        <form id="form-new-appat">
                                            <input type="text" class="form-control bg-light border-0 mb-3" id="new-a-nom" placeholder="Nom de l'appât">
                                            <select class="form-select bg-light border-0 mb-4" id="new-a-type">
                                                <option value="" selected disabled>Catégorie...</option>
                                                <?php foreach($types_appat as $ta): ?>
                                                    <option value="<?= $ta[0] ?>"><?= htmlspecialchars($ta[1]) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-primary rounded-pill w-100 fw-semibold" onclick="saveNewAppat()">Ajouter à la boîte</button>
                                        </form>
                                    </div>
                                </div>
                                <div id="list-appats"></div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <?php elseif ($etape === 4): ?>
        <!-- ================= ÉTAPE 4 : CONFIRMATION ================= -->
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center mt-5">
            <span class="material-symbols-rounded text-success mb-3" style="font-size: 80px;">task_alt</span>
            <h2 class="h4 fw-bold text-dark">Session enregistrée !</h2>
            <p class="text-muted">Votre session a été ajoutée avec succès à votre carnet.</p>
            <a href="accueil.php" class="btn btn-primary btn-lg rounded-pill mt-4 shadow-sm w-100 fw-semibold">Retour à l'accueil</a>
        </div>
        <?php unset($_SESSION['nouvelle_session']); ?>
        <?php endif; ?>

    </main>

    <!-- !!! BOOTSTRAP CHARGÉ AVANT TOUT LE RESTE POUR NE PAS FAIRE PLANTER LES MENUS !!! -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- INCLUSION DU SCRIPT SEPARE -->
    <?php include './script/JS_session.php'; ?>

</body>
</html>