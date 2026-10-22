<?php
require_once './includes/auth_bdd.php';
require_once './includes/profil.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$erreurs = [];

$stmt = $pdo->prepare("SELECT PSEUDO, DESCRIPTION, PDP_CHEMIN, BANNIERE_CHEMIN, NIVEAU_EXPERIENCE, ANNEE_DEBUT, CODE_DEPARTEMENT, MILIEU_PREFERE, ID_ESPECE_FETICHE FROM utilisateur WHERE ID_UTILISATEUR = :id");
$stmt->execute(['id' => $id_user]);
$profil = $stmt->fetch(PDO::FETCH_ASSOC);

$liste_techniques = $pdo->query("SELECT ID_TECHNIQUE, NOM_TECHNIQUE FROM technique ORDER BY NOM_TECHNIQUE")->fetchAll(PDO::FETCH_KEY_PAIR);
$liste_types = $pdo->query("SELECT ID_TYPE_SESSION, NOM_TYPE_SESSION FROM type_session ORDER BY NOM_TYPE_SESSION")->fetchAll(PDO::FETCH_KEY_PAIR);
$liste_especes = $pdo->query("SELECT ID_ESPECE, NOM_COM FROM espece ORDER BY NOM_COM")->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $pdo->prepare("SELECT ID_TECHNIQUE FROM utilisateur_technique WHERE ID_UTILISATEUR = ?");
$stmt->execute([$id_user]);
$mes_techniques = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$stmt = $pdo->prepare("SELECT ID_TYPE_SESSION FROM utilisateur_type_session WHERE ID_UTILISATEUR = ?");
$stmt->execute([$id_user]);
$mes_types = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pseudo = trim((string) ($_POST['pseudo'] ?? ''));
    $description = trim(str_replace("\r\n", "\n", (string) ($_POST['description'] ?? '')));

    if (mb_strlen($pseudo) < 2 || mb_strlen($pseudo) > 50) $erreurs[] = "Le pseudo doit contenir entre 2 et 50 caractères.";
    if (mb_strlen($description) > 255) $erreurs[] = "La description est limitée à 255 caractères.";

    // Fiche du pêcheur : chaque valeur est vérifiée contre une liste fermée
    $niveau = array_key_exists($_POST['niveau'] ?? '', NIVEAUX_EXPERIENCE) ? $_POST['niveau'] : null;
    $milieu_prefere = array_key_exists($_POST['milieu_prefere'] ?? '', MILIEUX_PREFERES) ? $_POST['milieu_prefere'] : null;
    $departement = array_key_exists((string) ($_POST['departement'] ?? ''), DEPARTEMENTS) ? (string) $_POST['departement'] : null;
    $annee = id_ou_null($_POST['annee_debut'] ?? null);
    if ($annee !== null && ($annee < 1930 || $annee > (int) date('Y'))) {
        $erreurs[] = "L'année de début doit être comprise entre 1930 et " . date('Y') . ".";
        $annee = null;
    }
    $espece_fetiche = id_ou_null($_POST['espece_fetiche'] ?? null);
    if ($espece_fetiche !== null && !isset($liste_especes[$espece_fetiche])) $espece_fetiche = null;
    $techniques = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['techniques'] ?? [])), fn($t) => isset($liste_techniques[$t]))));
    $types = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['types'] ?? [])), fn($t) => isset($liste_types[$t]))));
    if (count($techniques) > FAVORIS_PROFIL_MAX || count($types) > FAVORIS_PROFIL_MAX) {
        $erreurs[] = "Choisis " . FAVORIS_PROFIL_MAX . " techniques et " . FAVORIS_PROFIL_MAX . " types de pêche au maximum.";
    }

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
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE utilisateur SET PSEUDO = :pseudo, DESCRIPTION = :descr, NIVEAU_EXPERIENCE = :niveau, ANNEE_DEBUT = :annee,
                       CODE_DEPARTEMENT = :dep, MILIEU_PREFERE = :milieu, ID_ESPECE_FETICHE = :espece WHERE ID_UTILISATEUR = :id")
            ->execute(['pseudo' => $pseudo, 'descr' => $description !== '' ? $description : null, 'niveau' => $niveau, 'annee' => $annee,
                       'dep' => $departement, 'milieu' => $milieu_prefere, 'espece' => $espece_fetiche, 'id' => $id_user]);
        $pdo->prepare("DELETE FROM utilisateur_technique WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $stmtT = $pdo->prepare("INSERT INTO utilisateur_technique (ID_UTILISATEUR, ID_TECHNIQUE) VALUES (?, ?)");
        foreach ($techniques as $t) $stmtT->execute([$id_user, $t]);
        $pdo->prepare("DELETE FROM utilisateur_type_session WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $stmtT = $pdo->prepare("INSERT INTO utilisateur_type_session (ID_UTILISATEUR, ID_TYPE_SESSION) VALUES (?, ?)");
        foreach ($types as $t) $stmtT->execute([$id_user, $t]);
        $pdo->commit();
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
    $profil = array_merge($profil, ['PSEUDO' => $pseudo, 'DESCRIPTION' => $description, 'NIVEAU_EXPERIENCE' => $niveau, 'ANNEE_DEBUT' => $annee,
        'CODE_DEPARTEMENT' => $departement, 'MILIEU_PREFERE' => $milieu_prefere, 'ID_ESPECE_FETICHE' => $espece_fetiche]);
    $mes_techniques = $techniques;
    $mes_types = $types;
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
                            <label class="form-label text-secondary small fw-bold text-uppercase" for="bio">Ma bio</label>
                            <textarea class="form-control bg-light border-0 p-3 rounded-4" id="bio" name="description" rows="3" maxlength="255" placeholder="Ex. : carnassiers aux leurres sur l'Orne, bar du bord l'été."><?= e($profil['DESCRIPTION'] ?? '') ?></textarea>
                            <small class="text-muted">255 caractères maximum.</small>
                        </div>

                        <hr class="my-5 text-muted opacity-25">

                        <h5 class="fw-bold mb-2 text-dark d-flex align-items-center">
                            <span class="material-symbols-rounded text-primary me-2">id_card</span> Fiche du pêcheur
                        </h5>
                        <p class="small text-muted mb-4">Tout est facultatif et visible des autres membres. Ta région s'affiche sur ton profil, jamais tes spots.</p>

                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-6">
                                <label class="form-label text-secondary small fw-bold" for="niveau">Niveau</label>
                                <select class="form-select bg-light border-0 p-3 rounded-4" id="niveau" name="niveau">
                                    <option value="">Non précisé</option>
                                    <?php foreach (NIVEAUX_EXPERIENCE as $cle => $nom): ?>
                                        <option value="<?= e($cle) ?>" <?= ($profil['NIVEAU_EXPERIENCE'] ?? '') === $cle ? 'selected' : '' ?>><?= e($nom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label text-secondary small fw-bold" for="annee-debut">Je pêche depuis (année)</label>
                                <input type="number" class="form-control bg-light border-0 p-3 rounded-4" id="annee-debut" name="annee_debut" min="1930" max="<?= date('Y') ?>" inputmode="numeric" placeholder="Ex. : 2012" value="<?= e($profil['ANNEE_DEBUT'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label text-secondary small fw-bold" for="departement">Région (département)</label>
                                <select class="form-select bg-light border-0 p-3 rounded-4" id="departement" name="departement">
                                    <option value="">Non précisée</option>
                                    <?php foreach (DEPARTEMENTS as $code => $nom): ?>
                                        <option value="<?= e($code) ?>" <?= ($profil['CODE_DEPARTEMENT'] ?? '') === (string) $code ? 'selected' : '' ?>><?= e($nom . ' (' . $code . ')') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label text-secondary small fw-bold" for="milieu-prefere">Milieu de prédilection</label>
                                <select class="form-select bg-light border-0 p-3 rounded-4" id="milieu-prefere" name="milieu_prefere">
                                    <option value="">Non précisé</option>
                                    <?php foreach (MILIEUX_PREFERES as $cle => $nom): ?>
                                        <option value="<?= e($cle) ?>" <?= ($profil['MILIEU_PREFERE'] ?? '') === $cle ? 'selected' : '' ?>><?= e($nom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-secondary small fw-bold" for="espece-fetiche">Espèce de cœur</label>
                                <select class="form-select bg-light border-0 p-3 rounded-4" id="espece-fetiche" name="espece_fetiche">
                                    <option value="">Aucune</option>
                                    <?php foreach ($liste_especes as $id => $nom): ?>
                                        <option value="<?= (int) $id ?>" <?= (int) ($profil['ID_ESPECE_FETICHE'] ?? 0) === (int) $id ? 'selected' : '' ?>><?= e($nom) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <fieldset class="mb-4 choix-multiples" data-max="<?= FAVORIS_PROFIL_MAX ?>">
                            <legend class="form-label text-secondary small fw-bold">Pêches pratiquées <span class="fw-normal">(<?= FAVORIS_PROFIL_MAX ?> maximum)</span></legend>
                            <div class="liste-choix">
                                <?php foreach ($liste_types as $id => $nom): ?>
                                    <label class="choix-etiquette"><input type="checkbox" name="types[]" value="<?= (int) $id ?>" <?= in_array((int) $id, $mes_types, true) ? 'checked' : '' ?>><span><?= e(trim($nom)) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <fieldset class="mb-4 choix-multiples" data-max="<?= FAVORIS_PROFIL_MAX ?>">
                            <legend class="form-label text-secondary small fw-bold">Techniques favorites <span class="fw-normal">(<?= FAVORIS_PROFIL_MAX ?> maximum)</span></legend>
                            <div class="liste-choix">
                                <?php foreach ($liste_techniques as $id => $nom): ?>
                                    <label class="choix-etiquette"><input type="checkbox" name="techniques[]" value="<?= (int) $id ?>" <?= in_array((int) $id, $mes_techniques, true) ? 'checked' : '' ?>><span><?= e($nom) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

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
    <script>
        // 5 choix maximum par liste : les autres cases se désactivent une fois la limite atteinte
        document.querySelectorAll('.choix-multiples').forEach(groupe => {
            const max = parseInt(groupe.dataset.max, 10);
            const cases = groupe.querySelectorAll('input[type="checkbox"]');
            const maj = () => {
                const n = [...cases].filter(c => c.checked).length;
                cases.forEach(c => { c.disabled = !c.checked && n >= max; c.closest('label').classList.toggle('desactive', c.disabled); });
            };
            cases.forEach(c => c.addEventListener('change', maj));
            maj();
        });
    </script>
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