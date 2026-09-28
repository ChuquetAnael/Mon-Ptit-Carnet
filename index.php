<?php
session_start();

// Si l'utilisateur est déjà connecté, on l'envoie directement sur son tableau de bord
if (isset($_SESSION['user_id'])) {
    header('Location: accueil.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon P'tit Carnet - Accueil</title>
    
    <?php include './includes/head.php'; ?>
</head>
<body class="d-flex flex-column justify-content-center" style="padding-bottom: 0; min-height: 100vh; background-color: #f4f7f6;">

    <main class="container text-center px-4">
        <!-- Logo et Titre -->
        <div class="mb-5">
            <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-circle shadow-sm mb-3" style="width: 100px; height: 100px;">
                <span class="material-symbols-rounded" style="font-size: 50px;">phishing</span>
            </div>
            <h1 class="h2 fw-bold text-dark mb-2">Mon Carnet de Pêche</h1>
            <p class="text-secondary">Votre carnet numérique pour enregistrer et analyser toutes vos sessions au bord de l'eau.</p>
        </div>

        <!-- Boutons d'action -->
        <div class="d-grid gap-3 mx-auto" style="max-width: 350px;">
            <a href="connexion.php" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit">
                Se connecter
            </a>
            <a href="inscription.php" class="btn btn-outline-primary btn-lg rounded-pill fw-semibold shadow-sm" style="border-width: 2px;">
                Créer un profil
            </a>
        </div>
    </main>
    <div class="text-center mt-5 mb-3">
        <a href="mentions_legales.php" class="text-muted small text-decoration-none">Mentions légales & Confidentialité   </a>
        <a href="cgu.php" class="text-muted small text-decoration-none">Conditions Générales d'Utilisation</a>
    </div>
</body>
</html>