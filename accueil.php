<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';
require_once './BDD/BDD_accueil.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

nettoyerImagesOrphelines($pdo, $_SESSION['user_id']);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body>

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4">
        <h1 class="h4 mb-0 fw-semibold">Tableau de Bord</h1>
    </header>

    <main class="container">
        <!-- Section Outils -->
        <h2 class="h6 fw-bold text-secondary mb-3 text-uppercase">Mes Outils</h2>

        <!-- Bouton pour le Codex -->
        <a href="codex.php" class="btn btn-white d-flex align-items-center justify-content-between p-3 mb-3 rounded-4 shadow-sm text-decoration-none bg-white border" style="border-color: rgba(42, 82, 152, 0.1) !important;">
            <div class="d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                    <span class="material-symbols-rounded">set_meal</span>
                </div>
                <div class="text-start">
                    <span class="text-dark fw-bold d-block">Codex des espèces</span>
                    <span class="text-muted small">Consulter les poissons de France</span>
                </div>
            </div>
            <span class="material-symbols-rounded text-muted">chevron_right</span>
        </a>

        <!-- Nouveau bouton Boîte de Pêche -->
        <a href="boite_peche.php" class="btn btn-white d-flex align-items-center justify-content-between p-3 mb-3 rounded-4 shadow-sm text-decoration-none bg-white border" style="border-color: rgba(42, 82, 152, 0.1) !important;">
            <div class="d-flex align-items-center">
                <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                    <span class="material-symbols-rounded">inventory_2</span>
                </div>
                <div class="text-start">
                    <span class="text-dark fw-bold d-block">Ma Boîte de pêche</span>
                    <span class="text-muted small">Gérer mes leurres et appâts</span>
                </div>
            </div>
            <span class="material-symbols-rounded text-muted">chevron_right</span>
        </a>

        <!-- Nouveau bouton Mes Spots -->
        <a href="mes_spots.php" class="btn btn-white d-flex align-items-center justify-content-between p-3 mb-4 rounded-4 shadow-sm text-decoration-none bg-white border" style="border-color: rgba(42, 82, 152, 0.1) !important;">
            <div class="d-flex align-items-center">
                <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                    <span class="material-symbols-rounded">location_on</span>
                </div>
                <div class="text-start">
                    <span class="text-dark fw-bold d-block">Mes Spots</span>
                    <span class="text-muted small">Gérer mes lieux de pêche</span>
                </div>
            </div>
            <span class="material-symbols-rounded text-muted">chevron_right</span>
        </a>

        <!-- Dernières prises -->
        <h2 class="h5 fw-bold text-dark mb-3">Dernières prises</h2>
        
        <div class="card border-0 shadow-sm rounded-4 text-center p-5 mt-2 mb-5">
            <span class="material-symbols-rounded text-muted mb-3" style="font-size: 48px;">phishing</span>
            <p class="text-muted mb-0">Aucune prise enregistrée pour le moment.<br>Préparez votre matériel !</p>
        </div>
    </main>

    <nav class="navbar fixed-bottom bg-white custom-navbar border-0">
        <div class="container-fluid d-flex justify-content-around align-items-end px-2">
            <a href="accueil.php" class="nav-item active d-flex flex-column align-items-center">
                <span class="material-symbols-rounded">home</span>
                <span class="menu-text">Accueil</span>
            </a>
            <a href="nouvelle_session.php" class="btn-add-catch">
                <span class="material-symbols-rounded text-white" style="font-size: 36px;">phishing</span>
            </a>
            <a href="profil.php" class="nav-item d-flex flex-column align-items-center">
                <span class="material-symbols-rounded">person</span>
                <span class="menu-text">Profil</span>
            </a>
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>