<?php
require_once './includes/auth_bdd.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$action = ($_GET['action'] ?? 'add') === 'edit' ? 'edit' : 'add';
$id_session = null;
$id_prise = null;
$prise_data = null;
$photos_existantes = [];
$erreur = null;

// =========================================================================
// 1. VÉRIFICATION DES IDs (la prise / session doit appartenir à l'utilisateur)
// =========================================================================
if ($action === 'edit' && ($id_prise = id_ou_null($_GET['id'] ?? null))) {
    $stmt = $pdo->prepare("
        SELECT p.*, s.ID_UTILISATEUR, s.DATE_DEBUT 
        FROM prise p
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        WHERE p.ID_PRISE = ? AND s.ID_UTILISATEUR = ?
    ");
    $stmt->execute([$id_prise, $id_user]);
    $prise_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prise_data) {
        header('Location: profil.php');
        exit();
    }
    $id_session = (int) $prise_data['ID_SESSION'];

    $stmtPh = $pdo->prepare("SELECT ID_PHOTO, CHEMIN, STATUT_MODERATION FROM photo_prise WHERE ID_PRISE = ? ORDER BY ORDRE, ID_PHOTO");
    $stmtPh->execute([$id_prise]);
    $photos_existantes = $stmtPh->fetchAll(PDO::FETCH_ASSOC);
} elseif ($action === 'add' && ($id_session = id_ou_null($_GET['id_session'] ?? null))) {
    $stmtSess = $pdo->prepare("SELECT DATE_DEBUT FROM session_p WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?");
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
    $id_espece = id_ou_null($_POST['id_espece'] ?? null);
    $stmt = $pdo->prepare("SELECT 1 FROM espece WHERE ID_ESPECE = ?");
    $stmt->execute([$id_espece]);

    if (!$id_espece || !$stmt->fetchColumn()) {
        $erreur = "L'espèce du poisson est obligatoire.";
    } else {
        $taille = nombre_ou_null($_POST['taille'] ?? null, 0, 1000);
        $poids = nombre_ou_null($_POST['poids'] ?? null, 0, 999.99);
        $quantite = min(id_ou_null($_POST['quantite'] ?? null) ?? 1, 999);
        $id_technique = id_ou_null($_POST['id_technique'] ?? null);
        if ($id_technique) {
            $stmt = $pdo->prepare("SELECT 1 FROM technique WHERE ID_TECHNIQUE = ?");
            $stmt->execute([$id_technique]);
            if (!$stmt->fetchColumn()) $id_technique = null;
        }
        $type_valide = function (string $table, string $colonne, $id) use ($pdo): bool {
            if (!id_ou_null($id)) return false;
            $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE $colonne = ?");
            $stmt->execute([$id]);
            return (bool) $stmt->fetchColumn();
        };
        $relache = isset($_POST['relache']) ? 1 : 0;
        $heure = heure_ou_null($_POST['heure'] ?? null) ?? substr((string) $prise_data['DATE_HEURE'], 11, 5);
        $date_heure_finale = $date_base . ' ' . ($heure ?: '00:00') . ':00';

        $pdo->beginTransaction();

        // A. Matériel : création à la volée, sinon vérification qu'il appartient à l'utilisateur (ou au matériel commun)
        $id_leurre_final = $_POST['id_leurre'] ?? '';
        if (preg_match('/^new_l_\d+$/', $id_leurre_final) && !empty($_POST['new_leurres'][$id_leurre_final]['nom']) && $type_valide('type_leurre', 'ID_TYPE', $_POST['new_leurres'][$id_leurre_final]['type'] ?? null)) {
            $nl = $_POST['new_leurres'][$id_leurre_final];
            $stmtL = $pdo->prepare("INSERT INTO leurre (ID_UTILISATEUR, ID_TYPE, NOM_LEURRE, GRAMMAGE, COLORIS) VALUES (?, ?, ?, ?, ?)");
            $stmtL->execute([$id_user, id_ou_null($nl['type'] ?? null), mb_substr(trim($nl['nom']), 0, 50), nombre_ou_null($nl['poids'] ?? null, 0, 999), mb_substr(trim((string) ($nl['couleur'] ?? '')), 0, 50) ?: null]);
            $id_leurre_final = (int) $pdo->lastInsertId();
        } else {
            $id_leurre_final = id_ou_null($id_leurre_final);
            if ($id_leurre_final) {
                $stmt = $pdo->prepare("SELECT 1 FROM leurre WHERE ID_LEURRE = ? AND (ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL)");
                $stmt->execute([$id_leurre_final, $id_user]);
                if (!$stmt->fetchColumn()) $id_leurre_final = null;
            }
        }

        $id_appat_final = $_POST['id_appat'] ?? '';
        if (preg_match('/^new_a_\d+$/', $id_appat_final) && !empty($_POST['new_appats'][$id_appat_final]['nom']) && $type_valide('type_appat', 'ID_TYPE_APPAT', $_POST['new_appats'][$id_appat_final]['type'] ?? null)) {
            $na = $_POST['new_appats'][$id_appat_final];
            $stmtA = $pdo->prepare("INSERT INTO appat (ID_UTILISATEUR, ID_TYPE_APPAT, NOM_APPAT) VALUES (?, ?, ?)");
            $stmtA->execute([$id_user, id_ou_null($na['type'] ?? null), mb_substr(trim($na['nom']), 0, 50)]);
            $id_appat_final = (int) $pdo->lastInsertId();
        } else {
            $id_appat_final = id_ou_null($id_appat_final);
            if ($id_appat_final) {
                $stmt = $pdo->prepare("SELECT 1 FROM appat WHERE ID_APPAT = ? AND (ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL)");
                $stmt->execute([$id_appat_final, $id_user]);
                if (!$stmt->fetchColumn()) $id_appat_final = null;
            }
        }

        // B. Position : conservée en édition, mise à jour si une nouvelle photo contient du GPS
        $lat = $action === 'edit' ? $prise_data['LATITUDE'] : null;
        $lng = $action === 'edit' ? $prise_data['LONGITUDE'] : null;

        // C. Enregistrement de la prise
        if ($action === 'add') {
            $stmtInsert = $pdo->prepare("
                INSERT INTO prise (ID_SESSION, ID_ESPECE, ID_LEURRE, ID_APPAT, ID_TECHNIQUE, TAILLE_CM, POIDS_KG, RELACHE, LATITUDE, LONGITUDE, QUANTITE, DATE_HEURE) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([$id_session, $id_espece, $id_leurre_final, $id_appat_final, $id_technique, $taille === null ? null : (int) round($taille), $poids, $relache, $lat, $lng, $quantite, $date_heure_finale]);
            $id_prise = (int) $pdo->lastInsertId();
        }

        // D. Photos : suppression des photos cochées puis ajout des nouvelles (6 max)
        $a_supprimer = array_map('intval', (array) ($_POST['supprimer_photos'] ?? []));
        $restantes = 0;
        foreach ($photos_existantes as $ph) {
            if (in_array((int) $ph['ID_PHOTO'], $a_supprimer, true)) {
                $pdo->prepare("DELETE FROM photo_prise WHERE ID_PHOTO = ? AND ID_PRISE = ?")->execute([$ph['ID_PHOTO'], $id_prise]);
                supprimer_fichier_utilisateur($ph['CHEMIN'], $id_user);
            } else {
                $restantes++;
            }
        }

        $refusees = 0;
        $stmtPhoto = $pdo->prepare("INSERT INTO photo_prise (ID_PRISE, CHEMIN, ORDRE) VALUES (?, ?, ?)");
        foreach (isset($_FILES['photos']) ? fichiers_multiples($_FILES['photos']) : [] as $fichier) {
            if ($fichier['error'] === UPLOAD_ERR_NO_FILE) continue;
            if ($restantes >= PHOTOS_MAX_PAR_PRISE) { $refusees++; continue; }
            $resultat = enregistrer_image_uploadee($fichier, $id_user);
            if (!$resultat) { $refusees++; continue; }

            $stmtPhoto->execute([$id_prise, $resultat['chemin'], $restantes++]);
            $exif = $resultat['exif'];
            if (is_array($exif) && isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
                $lat = getGps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                $lng = getGps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
            }
        }

        $stmtUpdate = $pdo->prepare("
            UPDATE prise 
            SET ID_ESPECE = ?, ID_LEURRE = ?, ID_APPAT = ?, ID_TECHNIQUE = ?, TAILLE_CM = ?, POIDS_KG = ?, RELACHE = ?, LATITUDE = ?, LONGITUDE = ?, QUANTITE = ?, DATE_HEURE = ?
            WHERE ID_PRISE = ?
        ");
        $stmtUpdate->execute([$id_espece, $id_leurre_final, $id_appat_final, $id_technique, $taille === null ? null : (int) round($taille), $poids, $relache, $lat, $lng, $quantite, $date_heure_finale, $id_prise]);

        $pdo->commit();

        header("Location: detail_session.php?id=" . $id_session . ($refusees ? '&photos_refusees=' . $refusees : ''));
        exit();
    }
}

// =========================================================================
// 3. CHARGEMENT DES LISTES ET PRÉPARATION DE L'AFFICHAGE
// =========================================================================
$especes = $pdo->query("SELECT ID_ESPECE, NOM_COM, ICONE_CHEMIN FROM espece ORDER BY NOM_COM")->fetchAll(PDO::FETCH_ASSOC);
$techniques = $pdo->query("SELECT ID_TECHNIQUE, NOM_TECHNIQUE FROM technique ORDER BY NOM_TECHNIQUE")->fetchAll(PDO::FETCH_ASSOC);

// Récupération globale pour la boîte de pêche
$types_leurre = $pdo->query("SELECT * FROM type_leurre")->fetchAll(PDO::FETCH_NUM); 
$types_appat = $pdo->query("SELECT * FROM type_appat")->fetchAll(PDO::FETCH_NUM); 

$leurres = $pdo->prepare("SELECT ID_LEURRE, NOM_LEURRE, ID_TYPE, GRAMMAGE, COLORIS FROM leurre WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_LEURRE");
$leurres->execute([$id_user]);
$liste_leurres = $leurres->fetchAll(PDO::FETCH_ASSOC);

$appats = $pdo->prepare("SELECT ID_APPAT, NOM_APPAT, ID_TYPE_APPAT FROM appat WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_APPAT");
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
$heure_affichee = !empty($prise_data['DATE_HEURE']) ? date('H:i', strtotime($prise_data['DATE_HEURE'])) : date('H:i');
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $action === 'add' ? 'Ajouter une prise' : 'Modifier la prise' ?></title>
    
    <?php include './includes/head.php'; ?>
    
</head>
<body class="bg-light">

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
        <a href="detail_session.php?id=<?= (int) $id_session ?>" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">
            <?= $action === 'add' ? 'Nouvelle Prise' : 'Modifier la Prise' ?>
        </h1>
    </header>

    <main class="container pb-5 mb-5">
        
        <?php if($erreur): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm text-center" role="alert">
                <?= e($erreur) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" enctype="multipart/form-data" id="main-form">
            <?= csrf_field() ?>

            <!-- Conteneur invisible pour les nouveaux leurres -->
            <div id="new-items-container"></div>

            <!-- PHOTOS (plusieurs par prise) -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white text-center">
                <h5 class="fw-bold mb-3 text-dark">Photos de la prise <small class="text-muted fw-normal">(<?= PHOTOS_MAX_PAR_PRISE ?> max)</small></h5>

                <?php if (!empty($photos_existantes)): ?>
                    <div class="d-flex flex-wrap gap-2 justify-content-center mb-3">
                        <?php foreach ($photos_existantes as $ph): ?>
                            <label class="position-relative d-block" style="cursor:pointer;">
                                <img src="<?= e($ph['CHEMIN']) ?>" alt="Photo de la prise" class="rounded-3 shadow-sm border" style="width: 110px; height: 110px; object-fit: cover;">
                                <?php if ($ph['STATUT_MODERATION'] !== 'visible'): ?>
                                    <span class="badge bg-danger position-absolute bottom-0 start-0 m-1" style="font-size:0.6rem;">Masquée (modération)</span>
                                <?php endif; ?>
                                <span class="position-absolute top-0 end-0 m-1 bg-white rounded-pill px-2 py-1 shadow-sm small">
                                    <input type="checkbox" class="form-check-input m-0 me-1" name="supprimer_photos[]" value="<?= (int) $ph['ID_PHOTO'] ?>">Suppr.
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="small text-muted">Coche les photos à retirer, ou ajoutes-en d'autres ci-dessous.</p>
                <?php endif; ?>

                <label for="photo_upload" class="btn btn-light p-4 rounded-4 w-100 border-2 border-primary border-dashed d-flex flex-column align-items-center" style="border-style: dashed;">
                    <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">add_a_photo</span>
                    <span class="fw-semibold text-primary">Ajouter des photos</span>
                    <input type="file" id="photo_upload" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" class="d-none" onchange="previewImages(this)">
                </label>
                <div id="image_preview" class="d-flex flex-wrap gap-2 justify-content-center mt-3"></div>
            </div>

            <!-- DÉTAILS DU POISSON -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <h5 class="fw-bold mb-4 text-dark border-bottom pb-2">Détails du poisson</h5>
                
                <!-- MENU DE RECHERCHE DYNAMIQUE (AVEC ICÔNES) -->
                <div class="mb-4 dropdown custom-select-espece w-100">
                    <label class="form-label text-secondary small fw-bold text-uppercase tracking-wider">Espèce <span class="text-danger">*</span></label>
                    <button class="btn bg-light border-0 w-100 d-flex justify-content-between align-items-center text-start p-3 rounded-4 dropdown-toggle species-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="selected-espece-text text-dark d-flex align-items-center fw-medium">
                            <?php if(!empty($selected_espece_icon)): ?>
                                <img src="<?= e($selected_espece_icon) ?>" alt="" style="width:25px; height:25px; object-fit:contain;" class="me-2">
                            <?php endif; ?>
                            <?= e($selected_espece_name) ?>
                        </span>
                    </button>
                    <div class="dropdown-menu w-100 p-2 shadow-lg border-0 rounded-4 mt-1">
                        <div class="px-2 pb-2">
                            <input type="text" class="form-control bg-light border-0 shadow-none search-espece" placeholder="Taper un nom...">
                        </div>
                        <div class="espece-list" style="max-height: 250px; overflow-y: auto;">
                            <?php foreach($especes as $e): ?>
                                <a class="dropdown-item d-flex align-items-center espece-option rounded-3 py-2 mb-1" href="#" data-value="<?= (int) $e['ID_ESPECE'] ?>" data-nom="<?= e($e['NOM_COM']) ?>">
                                    <?php if($e['ICONE_CHEMIN']): ?>
                                        <img src="<?= e($e['ICONE_CHEMIN']) ?>" alt="" style="width:35px; height:35px; object-fit:contain;" class="me-3">
                                    <?php else: ?>
                                        <span class="material-symbols-rounded text-muted me-3" style="font-size:35px;">set_meal</span>
                                    <?php endif; ?>
                                    <span class="fw-medium text-dark"><?= e($e['NOM_COM']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" name="id_espece" class="hidden-espece-input" value="<?= e($prise_data['ID_ESPECE'] ?? '') ?>" required>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-4">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Quantité</label>
                        <input type="number" min="1" max="999" class="form-control bg-light border-0 p-3 rounded-4 fw-bold text-primary text-center" name="quantite" value="<?= e($prise_data['QUANTITE'] ?? '1') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Taille (cm)</label>
                        <input type="number" step="1" min="0" max="1000" inputmode="numeric" class="form-control bg-light border-0 p-3 rounded-4" name="taille" placeholder="0" value="<?= e($prise_data['TAILLE_CM'] ?? '') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label text-secondary small fw-bold text-uppercase">Poids (kg)</label>
                        <input type="number" step="0.01" min="0" max="999.99" inputmode="decimal" class="form-control bg-light border-0 p-3 rounded-4" name="poids" placeholder="0.00" value="<?= e($prise_data['POIDS_KG'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- MATÉRIEL : BOÎTE DE PÊCHE -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <h5 class="fw-bold mb-4 text-dark border-bottom pb-2">Matériel utilisé</h5>
                
                <label class="form-label text-secondary small fw-bold text-uppercase d-block">Appât / Leurre utilisé</label>
                <button type="button" class="btn bg-light w-100 rounded-4 d-flex align-items-center justify-content-between p-3 border-0" onclick="openTackleBox()">
                    <span id="tackle-text" class="text-truncate fw-medium <?= !empty($prise_data['ID_LEURRE']) || !empty($prise_data['ID_APPAT']) ? 'text-dark' : 'text-muted' ?>" style="max-width:85%;">
                        <?= e($selected_tackle_name) ?>
                    </span>
                    <span class="material-symbols-rounded text-primary">phishing</span>
                </button>
                <input type="hidden" name="id_leurre" id="hidden-leurre" value="<?= e($prise_data['ID_LEURRE'] ?? '') ?>">
                <input type="hidden" name="id_appat" id="hidden-appat" value="<?= e($prise_data['ID_APPAT'] ?? '') ?>">

                <div class="mt-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Technique</label>
                    <select class="form-select bg-light border-0 p-3 rounded-4" name="id_technique">
                        <option value="">Non précisée</option>
                        <?php foreach($techniques as $t): ?>
                            <option value="<?= (int) $t['ID_TECHNIQUE'] ?>" <?= ($prise_data['ID_TECHNIQUE'] ?? '') == $t['ID_TECHNIQUE'] ? 'selected' : '' ?>>
                                <?= e($t['NOM_TECHNIQUE']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- INFOS COMPLÉMENTAIRES -->
            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Heure de capture</label>
                    <input type="time" class="form-control bg-light border-0 p-3 rounded-4" name="heure" value="<?= e($heure_affichee) ?>" required>
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

    <?php include './includes/modal_boite_peche.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>

        // 1. PRÉVISUALISATION DES IMAGES
        function previewImages(input) {
            const preview = document.getElementById('image_preview');
            preview.innerHTML = '';
            Array.from(input.files || []).forEach(file => {
                const img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.alt = 'Aperçu';
                img.className = 'rounded-3 shadow-sm border';
                img.style.cssText = 'width: 110px; height: 110px; object-fit: cover;';
                preview.appendChild(img);
            });
        }

        // 2. GESTION DE LA BOÎTE DE PÊCHE (AJOUT ET RENDU)
        let leurres = <?= js($liste_leurres) ?>;
        let appats = <?= js($liste_appats) ?>;
        const typesLeurre = <?= js($types_leurre) ?>;
        const typesAppat = <?= js($types_appat) ?>;

    </script>
    <script src="script/gestion_prise.js"></script>
</body>
</html>