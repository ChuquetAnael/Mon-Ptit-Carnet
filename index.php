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
    <title>Mon P'tit Carnet - L'appli des pêcheurs</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Fonts & Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
            color: #2c3e50;
        }
        
        /* Héro Section avec Dégradé */
        .hero-section {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-bottom-left-radius: 40px;
            border-bottom-right-radius: 40px;
            padding: 5rem 1.5rem;
            box-shadow: 0 10px 30px rgba(30, 60, 114, 0.15);
        }
        
        .hero-icon {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        /* Tuiles des fonctionnalités */
        .feature-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 20px;
            height: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.08) !important;
        }
        
        .feature-icon-wrapper {
            width: 65px;
            height: 65px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- En-tête / Héro -->
    <header class="hero-section text-center position-relative mb-5">
        <div class="d-inline-flex align-items-center justify-content-center rounded-circle hero-icon mb-4 shadow-sm">
            <span class="material-symbols-rounded text-white" style="font-size: 55px;">phishing</span>
        </div>
        <h1 class="display-5 fw-bold mb-3">Mon P'tit Carnet</h1>
        <p class="lead fw-normal text-white-50 mb-5 mx-auto" style="max-width: 500px;">
            L'application indispensable pour enregistrer, analyser et revivre toutes vos sessions au bord de l'eau.
        </p>
        
        <!-- Boutons d'action -->
        <div class="d-grid gap-3 d-sm-flex justify-content-sm-center mx-auto" style="max-width: 450px;">
            <a href="connexion.php" class="btn btn-light btn-lg rounded-pill fw-bold text-primary shadow-sm px-5 py-3 d-flex align-items-center justify-content-center">
                <span class="material-symbols-rounded me-2">login</span> Se connecter
            </a>
            <a href="inscription.php" class="btn btn-outline-light btn-lg rounded-pill fw-bold px-5 py-3 d-flex align-items-center justify-content-center" style="border-width: 2px;">
                Créer un profil
            </a>
        </div>
    </header>

    <!-- Section Fonctionnalités -->
    <main class="container flex-grow-1 pb-5">
        <div class="text-center mb-5">
            <h2 class="h3 fw-bold text-dark mb-2">Tout pour le pêcheur moderne</h2>
            <p class="text-muted">Conçu pour être utilisé au bord de l'eau, directement sur votre smartphone.</p>
        </div>

        <div class="row g-4">
            <!-- Feature 1: Carnet & Prises -->
            <div class="col-12 col-md-6">
                <div class="card feature-card p-4 shadow-sm bg-white">
                    <div class="feature-icon-wrapper bg-primary bg-opacity-10 text-primary">
                        <span class="material-symbols-rounded fs-1">menu_book</span>
                    </div>
                    <h3 class="h5 fw-bold mb-2 text-dark">Carnet de Capture</h3>
                    <p class="text-muted small mb-0">Enregistrez vos prises en quelques secondes. Sauvegardez le spot, le leurre utilisé et bien sûr, la photo de votre poisson.</p>
                </div>
            </div>

            <!-- Feature 2: Codex -->
            <div class="col-12 col-md-6">
                <div class="card feature-card p-4 shadow-sm bg-white">
                    <div class="feature-icon-wrapper bg-info bg-opacity-10 text-info">
                        <span class="material-symbols-rounded fs-1">set_meal</span>
                    </div>
                    <h3 class="h5 fw-bold mb-2 text-dark">Codex Interactif</h3>
                    <p class="text-muted small mb-0">Explorez l'encyclopédie complète des poissons de France avec leurs tailles max, leurs habitats et modes de reproduction.</p>
                </div>
            </div>

            <!-- Feature 3: Statistiques & PB -->
            <div class="col-12 col-md-6">
                <div class="card feature-card p-4 shadow-sm bg-white">
                    <div class="feature-icon-wrapper bg-warning bg-opacity-10 text-warning">
                        <span class="material-symbols-rounded fs-1">emoji_events</span>
                    </div>
                    <h3 class="h5 fw-bold mb-2 text-dark">Statistiques & Records</h3>
                    <p class="text-muted small mb-0">Suivez l'évolution de vos records personnels (PB) par espèce et analysez l'efficacité globale de vos sessions de pêche.</p>
                </div>
            </div>

            <!-- Feature 4: Météo & Matériel -->
            <div class="col-12 col-md-6">
                <div class="card feature-card p-4 shadow-sm bg-white">
                    <div class="feature-icon-wrapper bg-success bg-opacity-10 text-success">
                        <span class="material-symbols-rounded fs-1">partly_cloudy_day</span>
                    </div>
                    <h3 class="h5 fw-bold mb-2 text-dark">Météo GPS & Boîte</h3>
                    <p class="text-muted small mb-0">Consultez la météo en direct sur vos spots et gérez votre boîte de pêche virtuelle (cannes, leurres) avec précision.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="mt-auto py-4 text-center border-top bg-white">
        <div class="container">
            <div class="mb-3 d-flex justify-content-center align-items-center">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px;">
                    <span class="material-symbols-rounded" style="font-size: 20px;">phishing</span>
                </div>
                <span class="fw-bold text-dark fs-5">Mon P'tit Carnet</span>
            </div>
            <div class="d-flex justify-content-center gap-3">
                <a href="mentions_legales.php" class="text-muted small text-decoration-none hover-primary">Mentions légales & Confidentialité</a>
                <span class="text-muted small">•</span>
                <a href="cgu.php" class="text-muted small text-decoration-none hover-primary">Conditions Générales d'Utilisation</a>
            </div>
            <div class="mt-3 text-muted" style="font-size: 0.7rem;">
                &copy; <?= date("Y"); ?> - Créé par un passionné, pour les passionnés.
            </div>
        </div>
    </footer>

</body>
</html>