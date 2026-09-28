<?php
session_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conditions Générales d'Utilisation - Mon Carnet de Pêche</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Fonts & Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    
    <!-- Ton fichier CSS externe -->
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-light pb-5">

    <!-- En-tête avec bouton retour -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="parametre.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Conditions d'Utilisation</h1>
    </header>

    <main class="container mb-5">
        
        <p class="text-muted text-center small mb-4">En vigueur au <?= date('d/m/Y') ?></p>

        <!-- 1. Accès et Inscription -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">login</span> 1. Accès et Inscription
            </h2>
            <p class="text-secondary small text-justify">
                L'application "Mon P'tit Carnet" est accessible gratuitement à tout utilisateur disposant d'un accès à Internet. L'éditeur se réserve le droit de proposer ultérieurement des fonctionnalités supplémentaires sous forme d'abonnement optionnel.<br><br>
                <strong>Mineurs :</strong> L'inscription et l'utilisation de l'application par des mineurs sont autorisées. Néanmoins, il est rappelé que l'utilisation de la plateforme par un mineur se fait sous l'entière responsabilité et avec l'autorisation de ses parents ou représentants légaux.
            </p>
        </div>

        <!-- 2. Le secret des spots de pêche -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">visibility_off</span> 2. Confidentialité absolue des Spots
            </h2>
            <p class="text-secondary small text-justify">
                Nous savons à quel point le secret d'un bon coin de pêche est précieux. Il est ici expressément garanti à l'utilisateur que <strong>les coordonnées GPS exactes de ses spots et de ses prises sont strictement privées</strong>. <br><br>
                Même dans l'éventualité du déploiement de fonctionnalités publiques (comme un fil d'actualité ou des classements), la localisation précise de vos captures ne sera jamais divulguée publiquement sans votre action explicite.
            </p>
        </div>

        <!-- 3. Photos et Droits d'auteur -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">photo_camera</span> 3. Contenus et Droits sur les Images
            </h2>
            <p class="text-secondary small text-justify">
                L'utilisateur conserve <strong>l'intégralité des droits de propriété intellectuelle</strong> sur les photographies (profil, bannières, poissons) qu'il téléverse sur la plateforme.<br><br>
                Toutefois, en utilisant le service, l'utilisateur concède à l'éditeur de "Mon P'tit Carnet" une licence non-exclusive et gratuite lui permettant d'utiliser occasionnellement ces images dans un but strictement promotionnel (par exemple : captures d'écran pour illustrer l'application sur un store, les réseaux sociaux ou une page de présentation).
            </p>
        </div>

        <!-- 4. Modération et Signalement -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">gavel</span> 4. Règles de conduite et Modération
            </h2>
            <p class="text-secondary small text-justify">
                L'utilisateur s'engage à ne publier aucun contenu à caractère pornographique, violent, discriminatoire, illicite ou portant atteinte à la dignité animale et humaine. <br><br>
                La plateforme est soumise à une <strong>modération a posteriori</strong>. Un système de signalement permet à la communauté de remonter les contenus inappropriés. L'éditeur se réserve le droit souverain de supprimer toute photo, session, ou compte utilisateur enfreignant ces règles, et ce sans préavis ni justification.
            </p>
        </div>

        <!-- 5. Responsabilité et Sécurité -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">warning</span> 5. Avertissements et Responsabilités
            </h2>
            <p class="text-secondary small text-justify">
                <strong>Données fournies :</strong> Les données météorologiques (issues de l'API Open-Meteo) et les coordonnées de géolocalisation sont fournies à titre purement indicatif et peuvent comporter des erreurs.<br><br>
                <strong>Sécurité :</strong> "Mon P'tit Carnet" est un outil d'archivage. L'utilisateur demeure le seul responsable de sa sécurité au bord de l'eau, du respect de la législation en vigueur concernant la pêche, et de la validation de ses droits de pêche (carte de pêche à jour, respect des tailles légales et des périodes de fermeture). L'éditeur décline toute responsabilité en cas d'accident ou d'infraction commise par un utilisateur.
            </p>
        </div>

    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>