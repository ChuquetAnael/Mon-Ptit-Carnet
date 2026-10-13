<?php
require_once './includes/auth_bdd.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$erreurs = [];

$stmt = $pdo->prepare("SELECT PSEUDO, DESCRIPTION, PDP_CHEMIN, BANNIERE_CHEMIN FROM utilisateur WHERE ID_UTILISATEUR = :id");
$stmt->execute(['id' => $id_user]);
$profil = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pseudo = trim((string) ($_POST['pseudo'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (mb_strlen($pseudo) < 2 || mb_strlen($pseudo) > 50) $erreurs[] = "Le pseudo doit contenir entre 2 et 50 caractères.";
    if (mb_strlen($description) > 255) $erreurs[] = "La description est limitée à 255 caractères.";

    // Images recadrées (Cropper.js) : décodées, vérifiées puis ré-encodées côté serveur
    $nouvelles_images = [];
    foreach (['pdp_cropped' => ['PDP_CHEMIN', 'pdp', 400], 'banniere_cropped' => ['BANNIERE_CHEMIN', 'ban', 1050]] as $champ => [$colonne, $prefixe, $largeur]) {
        if (empty($_POST[$champ])) continue;
        $chemin = enregistrer_image_base64((string) $_POST[$champ], $id_user, $prefixe, $largeur);
        if ($chemin) {
            $nouvelles_images[$colonne] = $chemin;
        } else {
            $erreurs[] = "Une des images envoyées est invalide ou trop lourde.";
        }
    }

    if (!$erreurs) {
        $pdo->prepare("UPDATE utilisateur SET PSEUDO = :pseudo, DESCRIPTION = :descr WHERE ID_UTILISATEUR = :id")
            ->execute(['pseudo' => $pseudo, 'descr' => $description !== '' ? $description : null, 'id' => $id_user]);
        $_SESSION['user_pseudo'] = $pseudo;

        foreach ($nouvelles_images as $colonne => $chemin) {
            // $colonne vient de la liste ci-dessus (jamais de l'utilisateur)
            $pdo->prepare("UPDATE utilisateur SET $colonne = :chemin WHERE ID_UTILISATEUR = :id")->execute(['chemin' => $chemin, 'id' => $id_user]);
            supprimer_fichier_utilisateur($profil[$colonne], $id_user); // l'ancienne image ne traîne plus sur le disque
        }
        header('Location: profil.php');
        exit();
    }

    // Erreur : on supprime les images fraîchement créées et on réaffiche le formulaire
    foreach ($nouvelles_images as $chemin) supprimer_fichier_utilisateur($chemin, $id_user);
    $profil['PSEUDO'] = $pseudo;
    $profil['DESCRIPTION'] = $description;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier mon profil - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    <!-- CSS de Cropper.js -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    
    <!-- LIAISON DES FICHIERS CSS EXTERNES -->
    <link href="css/profil.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- BANNIÈRE RESPONSIVE (Reprise du profil) -->
    <header class="profile-banner-responsive" <?php if(!empty($profil['BANNIERE_CHEMIN'])) echo 'style="background-image: url(\'' . e($profil['BANNIERE_CHEMIN']) . '\');"'; ?>>
        
        <!-- BOUTON RETOUR (Design de la roue crantée mais à gauche) -->
        <a href="profil.php" class="settings-btn" style="left: 20px; right: auto;" title="Retour au profil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        
        <!-- PHOTO DE PROFIL -->
        <div class="avatar-wrapper">
            <?php if(!empty($profil['PDP_CHEMIN'])): ?>
                <img src="<?= e($profil['PDP_CHEMIN']) ?>" alt="Photo de profil" class="avatar-circle">
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
                        <?= csrf_field() ?>
                        <?php foreach ($erreurs as $err): ?>
                            <div class="alert alert-danger rounded-4 border-0 small"><?= e($err) ?></div>
                        <?php endforeach; ?>
                        
                        <h5 class="fw-bold mb-4 text-dark d-flex align-items-center">
                            <span class="material-symbols-rounded text-primary me-2">badge</span> Informations
                        </h5>
                        
                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Pseudo</label>
                            <input type="text" class="form-control bg-light border-0 p-3 rounded-4" name="pseudo" minlength="2" maxlength="50" value="<?= e($profil['PSEUDO']) ?>" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-bold text-uppercase">Description</label>
                            <textarea class="form-control bg-light border-0 p-3 rounded-4" name="description" rows="4" maxlength="255" placeholder="Parlez un peu de votre passion..."><?= e($profil['DESCRIPTION'] ?? '') ?></textarea>
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