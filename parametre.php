<?php
require_once './includes/auth_bdd.php';

$id_user = $_SESSION['user_id'];
$message_erreur = '';
$message_succes = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // =========================================================================
    // GESTION DE LA SUPPRESSION DU COMPTE
    // =========================================================================
    if (isset($_POST['delete_account']) && !empty($_POST['password_confirm'])) {
        $password_fourni = $_POST['password_confirm'];

        // 1. Récupérer les infos de l'utilisateur (Mot de passe + Photos de profil)
        $stmtUser = $pdo->prepare("SELECT mot_de_passe, PDP_CHEMIN, BANNIERE_CHEMIN FROM UTILISATEUR WHERE ID_UTILISATEUR = :id");
        $stmtUser->execute(['id' => $id_user]);
        $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            // 2. Vérification du mot de passe (Supporte password_hash et le texte brut pour tes tests[cite: 8])
            if (password_verify($password_fourni, $userData['mot_de_passe']) || $password_fourni === $userData['mot_de_passe']) {
                
                // --- A. SUPPRESSION DES FICHIERS PHYSIQUES (Photos) ---
                $fichiers_a_supprimer = [];
                if (!empty($userData['PDP_CHEMIN'])) $fichiers_a_supprimer[] = $userData['PDP_CHEMIN'];
                if (!empty($userData['BANNIERE_CHEMIN'])) $fichiers_a_supprimer[] = $userData['BANNIERE_CHEMIN'];

                // Récupérer toutes les photos de prises de l'utilisateur
                $stmtPhotos = $pdo->prepare("
                    SELECT p.PHOTO_CHEMIN 
                    FROM PRISE p 
                    JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
                    WHERE s.ID_UTILISATEUR = :id AND p.PHOTO_CHEMIN IS NOT NULL
                ");
                $stmtPhotos->execute(['id' => $id_user]);
                while ($row = $stmtPhotos->fetch(PDO::FETCH_ASSOC)) {
                    $fichiers_a_supprimer[] = $row['PHOTO_CHEMIN'];
                }

                // Exécuter la suppression des fichiers
                foreach ($fichiers_a_supprimer as $fichier) {
                    if (file_exists($fichier)) {
                        unlink($fichier);
                    }
                }

                // --- B. SUPPRESSION EN BASE DE DONNÉES (Ordre important pour les clés étrangères) ---
                
                // 1. Supprimer les prises (liées aux sessions de l'utilisateur)
                $pdo->prepare("DELETE FROM PRISE WHERE ID_SESSION IN (SELECT ID_SESSION FROM SESSION_P WHERE ID_UTILISATEUR = ?)")->execute([$id_user]);
                
                // 2. Supprimer les sessions
                $pdo->prepare("DELETE FROM SESSION_P WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
                
                // 3. Supprimer le matériel et les spots
                $pdo->prepare("DELETE FROM LEURRE WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
                $pdo->prepare("DELETE FROM APPAT WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
                $pdo->prepare("DELETE FROM SPOT WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
                
                // 4. Supprimer l'utilisateur
                $pdo->prepare("DELETE FROM UTILISATEUR WHERE ID_UTILISATEUR = ?")->execute([$id_user]);

                // --- C. DÉCONNEXION ET REDIRECTION ---
                session_destroy();
                header('Location: index.php?msg=account_deleted');
                exit();

            } else {
                $message_erreur = "Le mot de passe est incorrect. La suppression a été annulée.";
            }
        }
    }

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&icon_names=mail" />
    <style>
        .settings-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.05) !important;
        }
    </style>
</head>
<body class="bg-light pb-5 mb-5">

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="profil.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Paramètres</h1>
    </header>

    <main class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">

                <?php if(!empty($message_erreur)): ?>
                    <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
                        <span class="material-symbols-rounded align-middle me-2">error</span>
                        <?= htmlspecialchars($message_erreur) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Section : Légal et Informations -->
                <h6 class="fw-bold text-secondary text-uppercase mb-3 ms-2 mt-2" style="font-size: 0.8rem;">Informations</h6>
                
                <a href="mentions_legales.php" class="text-decoration-none">
                    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white settings-card p-3 d-flex flex-row align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <span class="material-symbols-rounded">policy</span>
                            </div>
                            <span class="text-dark fw-bold">Mentions Légales & RGPD</span>
                        </div>
                        <span class="material-symbols-rounded text-muted">chevron_right</span>
                    </div>
                </a>

                <a href="cgu.php" class="text-decoration-none">
                    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white settings-card p-3 d-flex flex-row align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <span class="material-symbols-rounded">policy</span>
                            </div>
                            <span class="text-dark fw-bold">Conditions Générales d'Utilisation</span>
                        </div>
                        <span class="material-symbols-rounded text-muted">chevron_right</span>
                    </div>
                </a>

                <a href="contact.php" class="text-decoration-none">
                    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white settings-card p-3 d-flex flex-row align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <span class="material-symbols-outlined">mail</span>
                            </div>
                            <span class="text-dark fw-bold">Nous Contacter</span>
                        </div>
                        <span class="material-symbols-rounded text-muted">chevron_right</span>
                    </div>
                </a>

                <!-- Section : Compte -->
                <h6 class="fw-bold text-secondary text-uppercase mb-3 ms-2 mt-4" style="font-size: 0.8rem;">Mon Compte</h6>
                
                <!-- Bouton Déconnexion -->
                <a href="deconnexion.php" class="text-decoration-none">
                    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-white settings-card p-3 d-flex flex-row align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <span class="material-symbols-rounded">logout</span>
                            </div>
                            <span class="text-dark fw-bold">Se déconnecter</span>
                        </div>
                        <span class="material-symbols-rounded text-muted">chevron_right</span>
                    </div>
                </a>

                <!-- Bouton Suppression (Ouvre la Modale) -->
                <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white settings-card p-3 d-flex flex-row align-items-center justify-content-between" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#deleteModal">
                    <div class="d-flex align-items-center">
                        <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                            <span class="material-symbols-rounded">delete_forever</span>
                        </div>
                        <div class="text-start">
                            <span class="text-danger fw-bold d-block">Supprimer mon compte</span>
                            <span class="text-muted small">Action irréversible</span>
                        </div>
                    </div>
                    <span class="material-symbols-rounded text-muted">chevron_right</span>
                </div>

            </div>
        </div>
    </main>

    <?php include './includes/navbar.php'; ?>

    <!-- MODAL DE CONFIRMATION DE SUPPRESSION -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <form method="POST">
                    <div class="modal-header border-0 pb-0 mt-2 mx-2">
                        <h5 class="modal-title text-danger fw-bold d-flex align-items-center" id="deleteModalLabel">
                            <span class="material-symbols-rounded me-2">warning</span> Zone de danger
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body mx-2">
                        <p class="text-muted small">
                            Êtes-vous sûr de vouloir supprimer votre compte ? Toutes vos sessions, prises, photos et matériels seront <strong>définitivement effacés</strong> de nos serveurs.
                        </p>
                        <div class="mb-3 mt-4">
                            <label for="password_confirm" class="form-label fw-medium text-dark">Confirmez votre mot de passe :</label>
                            <input type="password" class="form-control form-control-lg bg-light border-0" id="password_confirm" name="password_confirm" placeholder="Votre mot de passe" required>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 mb-2 mx-2 d-flex flex-nowrap">
                        <button type="button" class="btn btn-light rounded-pill px-4 flex-grow-1 fw-medium" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" name="delete_account" class="btn btn-danger rounded-pill px-4 flex-grow-1 fw-bold">Supprimer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>