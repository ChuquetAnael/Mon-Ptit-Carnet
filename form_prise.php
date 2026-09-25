<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';
require_once './BDD/BDD_session.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $id_user = $_SESSION['user_id'];
    $action = $_GET['action'] ?? 'add';
    $id_session = null;
    $id_prise = null;
    $prise_data = null;
    $erreur = null;

    // =========================================================================
    // 1. VÉRIFICATION DES IDs ET RÉCUPÉRATION DES DONNÉES INITIALES
    // =========================================================================
    if ($action === 'edit' and isset($_GET['id']) and is_numeric($_GET['id'])) {
        $id_prise = (int)$_GET['id'];
        
        $stmt = $pdo->prepare("
            SELECT p.*, s.ID_UTILISATEUR, s.DATE_DEBUT 
            FROM PRISE p
            JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION
            WHERE p.ID_PRISE = ? AND s.ID_UTILISATEUR = ?
        ");
        $stmt->execute([$id_prise, $id_user]);
        $prise_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prise_data) {
            header('Location: profil.php');
            exit();
        }
        $id_session = $prise_data['ID_SESSION'];
    } 
    elseif ($action === 'add' and isset($_GET['id_session']) and is_numeric($_GET['id_session'])) {
        $id_session = (int)$_GET['id_session'];
        
        $stmtSess = $pdo->prepare("SELECT DATE_DEBUT FROM SESSION_P WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?");
        $stmtSess->execute([$id_session, $id_user]);
        $session_actuelle = $stmtSess->fetch(PDO::FETCH_ASSOC);

        if (!$session_actuelle) {
            header('Location: profil.php');
            exit();
        }
        $prise_data = [
            'DATE_DEBUT' => $session_actuelle['DATE_DEBUT'],
            'DATE_HEURE' => $session_actuelle['DATE_DEBUT'], 
            'QUANTITE' => 1,
            'RELACHE' => 1
        ];
    } else {
        header('Location: profil.php');
        exit();
    }

    $date_base = substr($prise_data['DATE_DEBUT'], 0, 10); 

    // =========================================================================
    // 2. TRAITEMENT DU FORMULAIRE ENVOYÉ (POST)
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id_espece = $_POST['id_espece'] ?? null;
        $quantite = (!empty($_POST['quantite']) and is_numeric($_POST['quantite']) and $_POST['quantite'] > 0) ? (int)$_POST['quantite'] : 1;
        $taille = !empty($_POST['taille']) ? str_replace(',', '.', $_POST['taille']) : null;
        $poids = !empty($_POST['poids']) ? str_replace(',', '.', $_POST['poids']) : null;
        $id_technique = !empty($_POST['id_technique']) ? $_POST['id_technique'] : null;
        $relache = isset($_POST['relache']) ? 1 : 0;
        
        $id_leurre_final = !empty($_POST['id_leurre']) ? $_POST['id_leurre'] : null;
        $id_appat_final = !empty($_POST['id_appat']) ? $_POST['id_appat'] : null;

        $heure_post = !empty($_POST['heure']) ? $_POST['heure'] : '00:00';
        $date_heure_finale = $date_base . ' ' . $heure_post . ':00';

        if ($id_espece) {
            
            // A. Création à la volée des nouveaux leurres / appâts
            if (strpos((string)$id_leurre_final, 'new_l_') === 0 and !empty($_POST['new_leurres'][$id_leurre_final])) {
                $nl = $_POST['new_leurres'][$id_leurre_final];
                $stmtL = $pdo->prepare("INSERT INTO LEURRE (ID_UTILISATEUR, ID_TYPE, NOM_LEURRE, GRAMMAGE, COLORIS) VALUES (?, ?, ?, ?, ?)");
                $stmtL->execute([$id_user, $nl['type'], $nl['nom'], !empty($nl['poids']) ? $nl['poids'] : null, !empty($nl['couleur']) ? $nl['couleur'] : null]);
                $id_leurre_final = $pdo->lastInsertId();
            }

            if (strpos((string)$id_appat_final, 'new_a_') === 0 and !empty($_POST['new_appats'][$id_appat_final])) {
                $na = $_POST['new_appats'][$id_appat_final];
                $stmtA = $pdo->prepare("INSERT INTO APPAT (ID_UTILISATEUR, ID_TYPE_APPAT, NOM_APPAT) VALUES (?, ?, ?)");
                $stmtA->execute([$id_user, $na['type'], $na['nom']]);
                $id_appat_final = $pdo->lastInsertId();
            }

            // B. Gestion de la photo
            $photo_chemin = $action === 'edit' ? $prise_data['PHOTO_CHEMIN'] : null;
            $lat = $action === 'edit' ? $prise_data['LATITUDE'] : null;
            $lng = $action === 'edit' ? $prise_data['LONGITUDE'] : null;

            if (isset($_FILES['photo']) and $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $dossier = 'uploads/user_' . $id_user . '/';
                if (!is_dir($dossier)) mkdir($dossier, 0777, true);
                
                $chemin_upload = $dossier . uniqid() . '.jpg';
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $chemin_upload)) {
                    $exif = @exif_read_data($chemin_upload);
                    if ($exif !== false) {
                        if (isset($exif['GPSLatitude']) and isset($exif['GPSLongitude'])) {
                            $lat = getGps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                            $lng = getGps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
                        }
                    }
                    compresserImage($chemin_upload, $chemin_upload, 80, 1200);
                    
                    if ($action === 'edit' and !empty($prise_data['PHOTO_CHEMIN']) and file_exists($prise_data['PHOTO_CHEMIN'])) {
                        unlink($prise_data['PHOTO_CHEMIN']);
                    }
                    $photo_chemin = $chemin_upload;
                }
            }

            // C. Exécution SQL
            if ($action === 'add') {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO PRISE (ID_SESSION, ID_ESPECE, ID_LEURRE, ID_APPAT, ID_TECHNIQUE, TAILLE_CM, POIDS_KG, RELACHE, PHOTO_CHEMIN, LATITUDE, LONGITUDE, QUANTITE, DATE_HEURE) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsert->execute([$id_session, $id_espece, $id_leurre_final, $id_appat_final, $id_technique, $taille, $poids, $relache, $photo_chemin, $lat, $lng, $quantite, $date_heure_finale]);
            } 
            elseif ($action === 'edit') {
                $stmtUpdate = $pdo->prepare("
                    UPDATE PRISE 
                    SET ID_ESPECE = ?, ID_LEURRE = ?, ID_APPAT = ?, ID_TECHNIQUE = ?, TAILLE_CM = ?, POIDS_KG = ?, RELACHE = ?, PHOTO_CHEMIN = ?, LATITUDE = ?, LONGITUDE = ?, QUANTITE = ?, DATE_HEURE = ?
                    WHERE ID_PRISE = ?
                ");
                $stmtUpdate->execute([$id_espece, $id_leurre_final, $id_appat_final, $id_technique, $taille, $poids, $relache, $photo_chemin, $lat, $lng, $quantite, $date_heure_finale, $id_prise]);
            }

            header("Location: detail_session.php?id=" . $id_session);
            exit();
        } else {
            $erreur = "L'espèce du poisson est obligatoire.";
        }
    }

    // =========================================================================
    // 3. CHARGEMENT DES LISTES ET PRÉPARATION DE L'AFFICHAGE
    // =========================================================================
    $especes = $pdo->query("SELECT ID_ESPECE, NOM_COM, ICONE_CHEMIN FROM ESPECE ORDER BY NOM_COM")->fetchAll(PDO::FETCH_ASSOC);
    $techniques = $pdo->query("SELECT ID_TECHNIQUE, NOM_TECHNIQUE FROM TECHNIQUE ORDER BY NOM_TECHNIQUE")->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupération globale pour la boîte de pêche
    $types_leurre = $pdo->query("SELECT * FROM TYPE_LEURRE")->fetchAll(PDO::FETCH_NUM); 
    $types_appat = $pdo->query("SELECT * FROM TYPE_APPAT")->fetchAll(PDO::FETCH_NUM); 

    $leurres = $pdo->prepare("SELECT ID_LEURRE, NOM_LEURRE, ID_TYPE, GRAMMAGE, COLORIS FROM LEURRE WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_LEURRE");
    $leurres->execute([$id_user]);
    $liste_leurres = $leurres->fetchAll(PDO::FETCH_ASSOC);

    $appats = $pdo->prepare("SELECT ID_APPAT, NOM_APPAT, ID_TYPE_APPAT FROM APPAT WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_APPAT");
    $appats->execute([$id_user]);
    $liste_appats = $appats->fetchAll(PDO::FETCH_ASSOC);

    // Pré-sélection de l'espèce (Texte et Icône)
    $selected_espece_name = "Rechercher une espèce...";
    $selected_espece_icon = "";
    if (!empty($prise_data['ID_ESPECE'])) {
        foreach ($especes as $e) {
            if ($e['ID_ESPECE'] == $prise_data['ID_ESPECE']) {
                $selected_espece_name = $e['NOM_COM'];
                $selected_espece_icon = $e['ICONE_CHEMIN'];
                break;
            }
        }
    }

    // Pré-sélection du matériel
    $selected_tackle_name = "Choisir dans la boîte...";
    if (!empty($prise_data['ID_LEURRE'])) {
        foreach ($liste_leurres as $l) {
            if ($l['ID_LEURRE'] == $prise_data['ID_LEURRE']) {
                $desc = $l['GRAMMAGE'] ? $l['GRAMMAGE'].'g' : '';
                $col = $l['COLORIS'] ? $l['COLORIS'] : '';
                $details = implode(' - ', array_filter([$desc, $col]));
                $selected_tackle_name = $l['NOM_LEURRE'] . ($details ? " ($details)" : "");
                break;
            }
        }
    } elseif (!empty($prise_data['ID_APPAT'])) {
        foreach ($liste_appats as $a) {
            if ($a['ID_APPAT'] == $prise_data['ID_APPAT']) {
                $selected_tackle_name = $a['NOM_APPAT'];
                break;
            }
        }
    }

} catch (PDOException $e) {
    die("Erreur : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $action === 'add' ? 'Ajouter une prise' : 'Modifier la prise' ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
        <a href="detail_session.php?id=<?= $id_session ?>" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">
            <?= $action === 'add' ? 'Nouvelle Prise' : 'Modifier la Prise' ?>
        </h1>
    </header>

    <main class="container pb-5 mb-5">
        
        <?php if($erreur): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm text-center" role="alert">
                <?= htmlspecialchars($erreur) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data" id="main-form">

            <!-- Conteneur invisible pour les nouveaux leurres -->
            <div id="new-items-container"></div>

            <!-- PHOTO -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white text-center">
                <h5 class="fw-bold mb-3 text-dark">Photo de la prise</h5>
                
                <?php if(!empty($prise_data['PHOTO_CHEMIN'])): ?>
                    <img src="<?= htmlspecialchars($prise_data['PHOTO_CHEMIN']) ?>" class="rounded-4 mb-3 shadow-sm w-100" style="max-height: 250px; object-fit: cover;">
                    <p class="small text-muted">Sélectionnez une nouvelle image ci-dessous pour remplacer l'actuelle.</p>
                <?php endif; ?>

                <label for="photo_upload" class="btn btn-light p-4 rounded-4 w-100 border-2 border-primary border-dashed d-flex flex-column align-items-center" style="border-style: dashed;">
                    <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">add_a_photo</span>
                    <span class="fw-semibold text-primary"><?= !empty($prise_data['PHOTO_CHEMIN']) ? 'Remplacer la photo' : 'Ajouter une photo' ?></span>
                    <input type="file" id="photo_upload" name="photo" accept="image/*" class="d-none" onchange="previewImage(this)">
                </label>
                <div id="image_preview" class="mt-3"></div>
            </div>

            <!-- DÉTAILS DU POISSON -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <h5 class="fw-bold mb-4 text-dark border-bottom pb-2">Détails du poisson</h5>
                
                <!-- MENU DE RECHERCHE DYNAMIQUE (AVEC ICÔNES) -->
                <div class="mb-4 dropdown w-100">
                    <label class="form-label text-secondary small fw-bold text-uppercase tracking-wider">Espèce <span class="text-danger">*</span></label>
                    <button class="btn bg-light border-0 w-100 d-flex justify-content-between align-items-center text-start p-3 rounded-4 dropdown-toggle species-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="selected-espece-text text-dark d-flex align-items-center fw-medium">
                            <?php if(!empty($selected_espece_icon)): ?>
                                <img src="<?= htmlspecialchars($selected_espece_icon) ?>" style="width:25px; height:25px; object-fit:contain;" class="me-2">
                            <?php endif; ?>
                            <?= htmlspecialchars($selected_espece_name) ?>
                        </span>
                    </button>
                    <div class="dropdown-menu w-100 p-2 shadow-lg border-0 rounded-4 mt-1">
                        <div class="px-2 pb-2">
                            <input type="text" class="form-control bg-light border-0 shadow-none search-espece" placeholder="Taper un nom...">
                        </div>
                        <div class="espece-list" style="max-height: 250px; overflow-y: auto;">
                            <?php foreach($especes as $e): ?>
                                <a class="dropdown-item d-flex align-items-center espece-option rounded-3 py-2 mb-1" href="#" data-value="<?= $e['ID_ESPECE'] ?>">
                                    <?php if($e['ICONE_CHEMIN']): ?>
                                        <img src="<?= htmlspecialchars($e['ICONE_CHEMIN']) ?>" style="width:35px; height:35px; object-fit:contain;" class="me-3">
                                    <?php else: ?>
                                        <span class="material-symbols-rounded text-muted me-3" style="font-size:35px;">set_meal</span>
                                    <?php endif; ?>
                                    <span class="fw-medium text-dark"><?= htmlspecialchars($e['NOM_COM']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" name="id_espece" class="hidden-espece-input" value="<?= $prise_data['ID_ESPECE'] ?? '' ?>" required>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-4">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Quantité</label>
                        <input type="number" min="1" class="form-control bg-light border-0 p-3 rounded-4 fw-bold text-primary text-center" name="quantite" value="<?= htmlspecialchars($prise_data['QUANTITE'] ?? '1') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Taille (cm)</label>
                        <input type="number" step="0.5" class="form-control bg-light border-0 p-3 rounded-4" name="taille" placeholder="0.0" value="<?= htmlspecialchars($prise_data['TAILLE_CM'] ?? '') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Poids (kg)</label>
                        <input type="number" step="0.01" class="form-control bg-light border-0 p-3 rounded-4" name="poids" placeholder="0.00" value="<?= htmlspecialchars($prise_data['POIDS_KG'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- MATÉRIEL : BOÎTE DE PÊCHE -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <h5 class="fw-bold mb-4 text-dark border-bottom pb-2">Matériel utilisé</h5>
                
                <label class="form-label text-secondary small fw-bold text-uppercase d-block">Appât / Leurre utilisé</label>
                <button type="button" class="btn bg-light w-100 rounded-4 d-flex align-items-center justify-content-between p-3 border-0" onclick="openTackleBox()">
                    <span id="tackle-text" class="text-truncate fw-medium <?= !empty($prise_data['ID_LEURRE']) || !empty($prise_data['ID_APPAT']) ? 'text-dark' : 'text-muted' ?>" style="max-width:85%;">
                        <?= htmlspecialchars($selected_tackle_name) ?>
                    </span>
                    <span class="material-symbols-rounded text-primary">phishing</span>
                </button>
                <input type="hidden" name="id_leurre" id="hidden-leurre" value="<?= $prise_data['ID_LEURRE'] ?? '' ?>">
                <input type="hidden" name="id_appat" id="hidden-appat" value="<?= $prise_data['ID_APPAT'] ?? '' ?>">

                <div class="mt-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Technique</label>
                    <select class="form-select bg-light border-0 p-3 rounded-4" name="id_technique">
                        <option value="">Non précisée</option>
                        <?php foreach($techniques as $t): ?>
                            <option value="<?= $t['ID_TECHNIQUE'] ?>" <?= ($prise_data['ID_TECHNIQUE'] ?? '') == $t['ID_TECHNIQUE'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['NOM_TECHNIQUE']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- INFOS COMPLÉMENTAIRES -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Heure de capture</label>
                    <input type="time" class="form-control bg-light border-0 p-3 rounded-4" name="heure" value="<?= date('H:i', strtotime($prise_data['DATE_HEURE'])) ?>" required>
                </div>

                <div class="form-check form-switch bg-light p-3 rounded-4 d-flex align-items-center">
                    <input class="form-check-input ms-0 me-3" type="checkbox" name="relache" id="relache" style="transform: scale(1.3);" <?= ($prise_data['RELACHE'] ?? 1) == 1 ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-success mb-0" for="relache">Poisson relâché (No-Kill)</label>
                </div>
            </div>

            <div class="d-grid mt-4">
                <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow py-3">
                    <span class="material-symbols-rounded align-middle me-2">save</span> <?= $action === 'add' ? 'Ajouter à la session' : 'Enregistrer les modifications' ?>
                </button>
            </div>
            
        </form>
    </main>

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
                            <button class="btn btn-outline-primary w-100 rounded-3 mb-4 fw-bold border-2 border-dashed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewLeurre">+ Créer un nouveau leurre perso</button>
                            <div class="collapse mb-4" id="collapseNewLeurre">
                                <div class="card card-body border-0 shadow-sm rounded-4">
                                    <form id="form-new-leurre">
                                        <input type="text" class="form-control bg-light border-0 mb-3" id="new-l-nom" placeholder="Nom (ex: Black Minnow)">
                                        <select class="form-select bg-light border-0 mb-3" id="new-l-type">
                                            <option value="" selected disabled>Type de leurre...</option>
                                            <?php foreach($types_leurre as$tl): ?><option value="<?= $tl[0] ?>"><?= htmlspecialchars($tl[1]) ?></option><?php endforeach; ?>
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
                            <button class="btn btn-outline-primary w-100 rounded-3 mb-4 fw-bold border-2 border-dashed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewAppat">+ Créer un nouvel appât perso</button>
                            <div class="collapse mb-4" id="collapseNewAppat">
                                <div class="card card-body border-0 shadow-sm rounded-4">
                                    <form id="form-new-appat">
                                        <input type="text" class="form-control bg-light border-0 mb-3" id="new-a-nom" placeholder="Nom de l'appât">
                                        <select class="form-select bg-light border-0 mb-4" id="new-a-type">
                                            <option value="" selected disabled>Catégorie...</option>
                                            <?php foreach($types_appat as$ta): ?><option value="<?= $ta[0] ?>"><?= htmlspecialchars($ta[1]) ?></option><?php endforeach; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 1. RECHERCHE D'ESPÈCE (Gestion D-FLEX vs D-NONE)
        document.querySelector('.search-espece').addEventListener('input', function() {
            const term = this.value.toLowerCase().trim();
            const options = document.querySelectorAll('.espece-option');
            
            options.forEach(opt => {
                const text = opt.innerText.toLowerCase();
                if (text.includes(term)) {
                    opt.style.setProperty('display', 'flex', 'important');
                } else {
                    opt.style.setProperty('display', 'none', 'important');
                }
            });
        });

        document.querySelectorAll('.espece-option').forEach(opt => {
            opt.addEventListener('click', function(e) {
                e.preventDefault();
                // Copier la valeur dans l'input caché
                document.querySelector('.hidden-espece-input').value = this.getAttribute('data-value');
                // Copier l'icône et le texte dans le bouton visible
                document.querySelector('.selected-espece-text').innerHTML = this.innerHTML;
                
                // Fermer le menu Bootstrap
                const bsDropdown = bootstrap.Dropdown.getInstance(document.querySelector('.species-btn')) || new bootstrap.Dropdown(document.querySelector('.species-btn'));
                bsDropdown.hide();
            });
        });

        // 2. PRÉVISUALISATION D'IMAGE
        function previewImage(input) {
            const preview = document.getElementById('image_preview');
            preview.innerHTML = '';
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" class="rounded-4 shadow-sm w-100" style="max-height: 250px; object-fit: cover;">`;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // 3. GESTION DE LA BOÎTE DE PÊCHE (AJOUT ET RENDU)
        let leurres = <?php echo json_encode($liste_leurres, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        let appats = <?php echo json_encode($liste_appats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const typesLeurre = <?php echo json_encode($types_leurre, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const typesAppat = <?php echo json_encode($types_appat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        
        let tackleModalInstance = null;

        function renderTackleBox() {
            let htmlLeurres = '';
            typesLeurre.forEach(type => {
                let items = leurres.filter(l => l.ID_TYPE == type[0]);
                if(items.length > 0) {
                    htmlLeurres += `<h6 class="fw-bold mt-4 mb-3 text-primary border-bottom pb-2">${type[1]}</h6><div class="row g-2">`;
                    items.forEach(l => {
                        let desc = l.GRAMMAGE ? `${l.GRAMMAGE}g` : '';
                        let col = l.COLORIS ? `${l.COLORIS}` : '';
                        let details = [desc, col].filter(Boolean).join(' - ');
                        htmlLeurres += `
                        <div class="col-6">
                            <div class="card border border-primary-subtle h-100 shadow-sm" onclick="selectTackle('leurre', '${l.ID_LEURRE}', '${l.NOM_LEURRE.replace(/'/g, "\\'")}', '${details}')" style="cursor:pointer;">
                                <div class="card-body p-2 text-center">
                                    <span class="d-block fw-bold text-dark small">${l.NOM_LEURRE}</span>
                                    <span class="d-block text-muted" style="font-size:0.65rem;">${details}</span>
                                </div>
                            </div>
                        </div>`;
                    });
                    htmlLeurres += `</div>`;
                }
            });
            document.getElementById('list-leurres').innerHTML = htmlLeurres;

            let htmlAppats = '';
            typesAppat.forEach(type => {
                let items = appats.filter(a => a.ID_TYPE_APPAT == type[0]);
                if(items.length > 0) {
                    htmlAppats += `<h6 class="fw-bold mt-4 mb-3 text-primary border-bottom pb-2">${type[1]}</h6><div class="row g-2">`;
                    items.forEach(a => {
                        htmlAppats += `
                        <div class="col-6">
                            <div class="card border border-primary-subtle h-100 shadow-sm" onclick="selectTackle('appat', '${a.ID_APPAT}', '${a.NOM_APPAT.replace(/'/g, "\\'")}', '')" style="cursor:pointer;">
                                <div class="card-body p-2 text-center">
                                    <span class="d-block fw-bold text-dark small">${a.NOM_APPAT}</span>
                                </div>
                            </div>
                        </div>`;
                    });
                    htmlAppats += `</div>`;
                }
            });
            document.getElementById('list-appats').innerHTML = htmlAppats;
        }

        function openTackleBox() {
            renderTackleBox();
            if(!tackleModalInstance) tackleModalInstance = new bootstrap.Modal(document.getElementById('tackleBoxModal'));
            tackleModalInstance.show();
        }

        function selectTackle(type, id, nom, details) {
            const displayStr = details ? `${nom} (${details})` : nom;
            const btn = document.getElementById('tackle-text');
            btn.innerText = displayStr;
            btn.classList.replace('text-muted', 'text-dark');
            btn.classList.add('fw-bold');
            
            if(type === 'leurre') {
                document.getElementById('hidden-leurre').value = id;
                document.getElementById('hidden-appat').value = '';
            } else {
                document.getElementById('hidden-appat').value = id;
                document.getElementById('hidden-leurre').value = '';
            }
            tackleModalInstance.hide();
        }

        function saveNewLeurre() {
            let nom = document.getElementById('new-l-nom').value;
            let type = document.getElementById('new-l-type').value;
            let poids = document.getElementById('new-l-poids').value;
            let couleur = document.getElementById('new-l-couleur').value;
            if(!nom || !type) { alert("Veuillez renseigner le nom et le type du leurre."); return; }
            
            let tempId = 'new_l_' + Date.now();
            leurres.push({ ID_LEURRE: tempId, NOM_LEURRE: nom, ID_TYPE: type, GRAMMAGE: poids, COLORIS: couleur });
            
            document.getElementById('new-items-container').insertAdjacentHTML('beforeend', `
                <input type="hidden" name="new_leurres[${tempId}][nom]" value="${nom}">
                <input type="hidden" name="new_leurres[${tempId}][type]" value="${type}">
                <input type="hidden" name="new_leurres[${tempId}][poids]" value="${poids}">
                <input type="hidden" name="new_leurres[${tempId}][couleur]" value="${couleur}">
            `);
            
            document.getElementById('form-new-leurre').reset();
            bootstrap.Collapse.getInstance(document.getElementById('collapseNewLeurre')).hide();
            renderTackleBox();
        }

        function saveNewAppat() {
            let nom = document.getElementById('new-a-nom').value;
            let type = document.getElementById('new-a-type').value;
            if(!nom || !type) { alert("Veuillez renseigner le nom et la catégorie."); return; }
            
            let tempId = 'new_a_' + Date.now();
            appats.push({ ID_APPAT: tempId, NOM_APPAT: nom, ID_TYPE_APPAT: type });
            
            document.getElementById('new-items-container').insertAdjacentHTML('beforeend', `
                <input type="hidden" name="new_appats[${tempId}][nom]" value="${nom}">
                <input type="hidden" name="new_appats[${tempId}][type]" value="${type}">
            `);
            
            document.getElementById('form-new-appat').reset();
            bootstrap.Collapse.getInstance(document.getElementById('collapseNewAppat')).hide();
            renderTackleBox();
        }
    </script>
</body>
</html>