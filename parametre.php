<?php
require_once './includes/auth_bdd.php';

$id_user = (int) $_SESSION['user_id'];
$message_erreur = '';
$pdo = db();

// VÉRIFICATION DES DROITS ADMINISTRATEUR (affichage du lien)
$is_admin = est_admin($id_user);

// =========================================================================
// GESTION DE LA SUPPRESSION DU COMPTE
// =========================================================================
if (isset($_POST['delete_account']) && !empty($_POST['password_confirm'])) {
    $stmtUser = $pdo->prepare("SELECT MOT_DE_PASSE FROM utilisateur WHERE ID_UTILISATEUR = :id");
    $stmtUser->execute(['id' => $id_user]);
    $hash = $stmtUser->fetchColumn();

    // Uniquement password_verify : la comparaison en clair (mot de passe === hash) a été retirée
    if ($hash && password_verify((string) $_POST['password_confirm'], $hash)) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM prise WHERE ID_SESSION IN (SELECT ID_SESSION FROM session_p WHERE ID_UTILISATEUR = ?)")->execute([$id_user]);
        $pdo->prepare("DELETE FROM session_p WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $pdo->prepare("DELETE FROM leurre WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $pdo->prepare("DELETE FROM appat WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $pdo->prepare("DELETE FROM spot WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $pdo->prepare("DELETE FROM admin WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $pdo->prepare("DELETE FROM utilisateur WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $pdo->commit();

        // Fichiers supprimés une fois la base à jour (photos, avatar, bannière, fichiers orphelins)
        supprimer_dossier_utilisateur($id_user);

        $_SESSION = [];
        session_destroy();
        header('Location: index.php?msg=account_deleted');
        exit();
    }
    $message_erreur = "Le mot de passe est incorrect. La suppression a été annulée.";
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
                        <?= e($message_erreur) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Section : Administration (Visible uniquement pour les Admins) -->
                <?php if($is_admin): ?>
                    <h6 class="fw-bold text-danger text-uppercase mb-3 ms-2 mt-2" style="font-size: 0.8rem;">Administration</h6>
                    
                    <a href="admin.php" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white settings-card p-3 d-flex flex-row align-items-center justify-content-between border-start border-danger border-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                    <span class="material-symbols-rounded">admin_panel_settings</span>
                                </div>
                                <span class="text-danger fw-bold">Espace Administrateur</span>
                            </div>
                            <span class="material-symbols-rounded text-muted">chevron_right</span>
                        </div>
                    </a>
                <?php endif; ?>

                <!-- Section : Légal et Informations -->
                <h6 class="fw-bold text-secondary text-uppercase mb-3 ms-2 <?= $is_admin ? 'mt-4' : 'mt-2' ?>" style="font-size: 0.8rem;">Informations</h6>
                
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
                    <?= csrf_field() ?>
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