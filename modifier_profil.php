<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $id_user = $_SESSION['user_id'];
        
        // 1. Mise à jour des textes
        $stmt = $pdo->prepare("UPDATE UTILISATEUR SET PSEUDO = :pseudo, DESCRIPTION = :desc WHERE ID_UTILISATEUR = :id");
        $stmt->execute([
            'pseudo' => $_POST['pseudo'],
            'desc' => $_POST['description'],
            'id' => $id_user
        ]);

        // Création du sous-dossier unique par utilisateur
        $dossier_upload = 'uploads/user_' . $id_user . '/';
        if (!is_dir($dossier_upload)) {
            mkdir($dossier_upload, 0777, true);
        }

        // 2. Fonction simplifiée pour décoder et sauvegarder le Base64 fourni par Cropper.js
        function sauvegarderBase64($base64_string, $dossier, $prefixe, $id_user, $pdo, $colonne_bdd) {
            $image_parts = explode(";base64,", $base64_string);
            if (count($image_parts) == 2) {
                $image_base64 = base64_decode($image_parts[1]);
                // On force le format JPG et on garantit un nom unique
                $nom_unique = uniqid() . '_' . $prefixe . '_' . $id_user . '.jpg';
                $chemin_final = $dossier . $nom_unique;
                
                if (file_put_contents($chemin_final, $image_base64)) {
                    $stmtImg = $pdo->prepare("UPDATE UTILISATEUR SET $colonne_bdd = :chemin WHERE ID_UTILISATEUR = :id");
                    $stmtImg->execute(['chemin' => $chemin_final, 'id' => $id_user]);
                }
            }
        }

        // 3. Sauvegarde de la Photo de profil si recadrée
        if (!empty($_POST['pdp_cropped'])) {
            sauvegarderBase64($_POST['pdp_cropped'], $dossier_upload, 'pdp', $id_user, $pdo, 'PDP_CHEMIN');
        }

        // 4. Sauvegarde de la Bannière si recadrée
        if (!empty($_POST['banniere_cropped'])) {
            sauvegarderBase64($_POST['banniere_cropped'], $dossier_upload, 'ban', $id_user, $pdo, 'BANNIERE_CHEMIN');
        }

        header('Location: profil.php');
        exit();
    }

    // Récupération des données pour pré-remplir le formulaire (et afficher la bannière/avatar actuelle)
    $stmt = $pdo->prepare("SELECT PSEUDO, DESCRIPTION, PDP_CHEMIN, BANNIERE_CHEMIN FROM UTILISATEUR WHERE ID_UTILISATEUR = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $profil = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier mon profil - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    
    <!-- CSS de Cropper.js -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    
    <!-- LIAISON DES FICHIERS CSS EXTERNES -->
    <link href="css/style.css" rel="stylesheet">
    <link href="css/profil.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- BANNIÈRE RESPONSIVE (Reprise du profil) -->
    <header class="profile-banner-responsive" <?php if(!empty($profil['BANNIERE_CHEMIN'])) echo 'style="background-image: url(\'' . htmlspecialchars($profil['BANNIERE_CHEMIN']) . '\');"'; ?>>
        
        <!-- BOUTON RETOUR (Design de la roue crantée mais à gauche) -->
        <a href="profil.php" class="settings-btn" style="left: 20px; right: auto;" title="Retour au profil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        
        <!-- PHOTO DE PROFIL -->
        <div class="avatar-wrapper">
            <?php if(!empty($profil['PDP_CHEMIN'])): ?>
                <img src="<?= htmlspecialchars($profil['PDP_CHEMIN']) ?>" alt="Photo de profil" class="avatar-circle">
            <?php else: ?>
                <div class="avatar-circle">
                    <span class="material-symbols-rounded">person</span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container pb-5 mb-5 main-profile-content">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">
                
                <div class="text-center mb-5">
                    <h1 class="h3 fw-bold text-dark mb-1">Modifier mon profil</h1>
                    <p class="text-muted small">Personnalisez vos informations et vos photos</p>
                </div>

                <!-- CARTE DU FORMULAIRE -->
                <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 mb-5 bg-white">
                    <form id="form-profil" action="modifier_profil.php" method="POST">
                        
                        <h5 class="fw-bold mb-4 text-dark d-flex align-items-center">
                            <span class="material-symbols-rounded text-primary me-2">badge</span> Informations
                        </h5>
                        
                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Pseudo</label>
                            <input type="text" class="form-control bg-light border-0 p-3 rounded-4" name="pseudo" value="<?= htmlspecialchars($profil['PSEUDO']) ?>" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Description</label>
                            <textarea class="form-control bg-light border-0 p-3 rounded-4" name="description" rows="4" placeholder="Parlez un peu de votre passion..."><?= htmlspecialchars($profil['DESCRIPTION'] ?? '') ?></textarea>
                        </div>

                        <hr class="my-5 text-muted opacity-25">
                        
                        <h5 class="fw-bold mb-4 text-dark d-flex align-items-center">
                            <span class="material-symbols-rounded text-primary me-2">wallpaper</span> Personnalisation
                        </h5>
                        
                        <!-- ZONE : Photo de Profil -->
                        <div class="mb-5">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Nouvelle Photo de profil</label>
                            <input type="file" class="form-control bg-light border-0 p-3 rounded-4 mb-3" id="pdp-input" accept="image/*">
                            <input type="hidden" name="pdp_cropped" id="pdp_cropped">
                            
                            <!-- Conteneur Cropper PDP -->
                            <div id="pdp-cropper-container" class="rounded-4 overflow-hidden shadow-sm border border-light-subtle" style="display:none; max-height: 400px;">
                                <img id="pdp-image-to-crop" style="max-width: 100%; display: block;">
                            </div>
                        </div>

                        <!-- ZONE : Image de Bannière -->
                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Nouvelle Bannière</label>
                            <input type="file" class="form-control bg-light border-0 p-3 rounded-4 mb-3" id="banniere-input" accept="image/*">
                            <input type="hidden" name="banniere_cropped" id="banniere_cropped">
                            
                            <!-- Conteneur Cropper Bannière -->
                            <div id="banniere-cropper-container" class="rounded-4 overflow-hidden shadow-sm border border-light-subtle" style="display:none; max-height: 400px;">
                                <img id="banniere-image-to-crop" style="max-width: 100%; display: block;">
                            </div>
                        </div>

                        <div class="d-grid mt-5">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm p-3 hover-card">
                                Enregistrer les modifications
                            </button>
                        </div>
                    </form>
                </div>
                
            </div>
        </div>
    </main>

    <!-- Script Cropper.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <script>
        let cropperBan, cropperPdp;

        // --- GESTION DE LA PHOTO DE PROFIL ---
        document.getElementById('pdp-input').addEventListener('change', function (e) {
            const files = e.target.files;
            if (files && files.length > 0) {
                const reader = new FileReader();
                reader.onload = function (event) {
                    const imgToCrop = document.getElementById('pdp-image-to-crop');
                    imgToCrop.src = event.target.result;
                    document.getElementById('pdp-cropper-container').style.display = 'block';
                    
                    if (cropperPdp) cropperPdp.destroy();
                    
                    // Ratio 1:1 pour un rond parfait de photo de profil
                    cropperPdp = new Cropper(imgToCrop, {
                        aspectRatio: 1 / 1,
                        viewMode: 1,
                        autoCropArea: 1
                    });
                };
                reader.readAsDataURL(files[0]);
            }
        });

        // --- GESTION DE LA BANNIÈRE ---
        document.getElementById('banniere-input').addEventListener('change', function (e) {
            const files = e.target.files;
            if (files && files.length > 0) {
                const reader = new FileReader();
                reader.onload = function (event) {
                    const imgToCrop = document.getElementById('banniere-image-to-crop');
                    imgToCrop.src = event.target.result;
                    document.getElementById('banniere-cropper-container').style.display = 'block';
                    
                    if (cropperBan) cropperBan.destroy();
                    
                    // Ratio 21:9 parfaitement calibré avec le profil.css
                    cropperBan = new Cropper(imgToCrop, {
                        aspectRatio: 21 / 9,
                        viewMode: 1,
                        autoCropArea: 1
                    });
                };
                reader.readAsDataURL(files[0]);
            }
        });

        // --- INTERCEPTION DU BOUTON ENREGISTRER ---
        document.getElementById('form-profil').addEventListener('submit', function(e) {
            // Si l'utilisateur a recadré une bannière
            if (cropperBan) {
                // Tailles de coupe proportionnelles à 21/9
                const canvasBan = cropperBan.getCroppedCanvas({ width: 1050, height: 450 });
                document.getElementById('banniere_cropped').value = canvasBan.toDataURL('image/jpeg', 0.8);
            }
            
            // Si l'utilisateur a recadré une photo de profil
            if (cropperPdp) {
                const canvasPdp = cropperPdp.getCroppedCanvas({ width: 400, height: 400 });
                document.getElementById('pdp_cropped').value = canvasPdp.toDataURL('image/jpeg', 0.8);
            }
        });
    </script>
</body>
</html>