<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';
require_once './BDD/BDD_accueil.php'; //[cite: 4]

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Récupération du pseudo pour un accueil personnalisé[cite: 3]
    $stmtUser = $pdo->prepare("SELECT PSEUDO FROM UTILISATEUR WHERE ID_UTILISATEUR = :id");
    $stmtUser->execute(['id' => $_SESSION['user_id']]);
    $utilisateur = $stmtUser->fetch(PDO::FETCH_ASSOC);
    $pseudo = $utilisateur ? $utilisateur['PSEUDO'] : 'Pêcheur';

    // 2. Récupération dynamique des 3 dernières prises de l'utilisateur
    $stmtPrises = $pdo->prepare("
        SELECT p.PHOTO_CHEMIN, p.TAILLE_CM, e.NOM_COM, e.ICONE_CHEMIN, p.DATE_HEURE
        FROM PRISE p
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
        ORDER BY p.DATE_HEURE DESC
        LIMIT 3
    ");
    $stmtPrises->execute(['id_user' => $_SESSION['user_id']]);
    $dernieres_prises = $stmtPrises->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

nettoyerImagesOrphelines($pdo, $_SESSION['user_id']); //[cite: 4]

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
    
    <style>
        /* Animation moderne au survol pour les nouvelles tuiles */
        .tool-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid rgba(42, 82, 152, 0.05);
        }
        .tool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important;
        }
        /* Style pour les miniatures des dernières prises */
        .mini-catch-img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 12px;
        }
    </style>
</head>
<body>

    <!-- En-tête avec message de bienvenue personnalisé -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4">
        <h1 class="h4 mb-1 fw-bold">Bonjour, <?= htmlspecialchars($pseudo) ?> !</h1>
        <p class="mb-0 small text-white-50">Prêt pour de nouvelles prises ?</p>
    </header>

    <main class="container pb-5 mb-5">
        <!-- Section Outils (Transformée en grille moderne 2x2) -->
        <div class="d-flex justify-content-between align-items-end mb-3">
            <h2 class="h6 fw-bold text-secondary text-uppercase mb-0">Mes Outils</h2>
        </div>

        <div class="row g-3 mb-5">
            <!-- Tuile 1 : Codex -->
            <div class="col-6">
                <a href="codex.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100 tool-card bg-white">
                    <div class="card-body text-center p-3">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 55px; height: 55px;">
                            <span class="material-symbols-rounded fs-2">set_meal</span>
                        </div>
                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">Codex</h6>
                        <small class="text-muted d-block" style="font-size: 0.7rem;">Espèces</small>
                    </div>
                </a>
            </div>

            <!-- Tuile 2 : Boîte de Pêche -->
            <div class="col-6">
                <a href="boite_peche.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100 tool-card bg-white">
                    <div class="card-body text-center p-3">
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 55px; height: 55px;">
                            <span class="material-symbols-rounded fs-2">inventory_2</span>
                        </div>
                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">Matériel</h6>
                        <small class="text-muted d-block" style="font-size: 0.7rem;">Boîte de pêche</small>
                    </div>
                </a>
            </div>

            <!-- Tuile 3 : Spots -->
            <div class="col-6">
                <a href="mes_spots.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100 tool-card bg-white">
                    <div class="card-body text-center p-3">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 55px; height: 55px;">
                            <span class="material-symbols-rounded fs-2">location_on</span>
                        </div>
                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">Spots</h6>
                        <small class="text-muted d-block" style="font-size: 0.7rem;">Lieux favoris</small>
                    </div>
                </a>
            </div>

            <!-- Tuile 4 : Statistiques (Nouveau) -->
            <div class="col-6">
                <a href="statistiques.php" class="card border-0 shadow-sm rounded-4 text-decoration-none h-100 tool-card bg-white">
                    <div class="card-body text-center p-3">
                        <div class="bg-info bg-opacity-10 text-info rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 55px; height: 55px;">
                            <span class="material-symbols-rounded fs-2">analytics</span>
                        </div>
                        <h6 class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">Statistiques</h6>
                        <small class="text-muted d-block" style="font-size: 0.7rem;">Mon bilan</small>
                    </div>
                </a>
            </div>
        </div>

        <!-- Section : Dernières prises avec données dynamiques -->
        <div class="d-flex justify-content-between align-items-end mb-3">
            <h2 class="h6 fw-bold text-secondary text-uppercase mb-0">Dernières prises</h2>
            <?php if (!empty($dernieres_prises)): ?>
                <a href="profil.php" class="text-primary small text-decoration-none fw-medium">Tout voir</a>
            <?php endif; ?>
        </div>
        
        <?php if (empty($dernieres_prises)): ?>
            <!-- Affichage vide si aucune prise[cite: 4] -->
            <div class="card border-0 shadow-sm rounded-4 text-center p-5 mt-2">
                <span class="material-symbols-rounded text-muted mb-3" style="font-size: 48px;">phishing</span>
                <p class="text-muted mb-0">Aucune prise enregistrée pour le moment.<br>Préparez votre matériel !</p>
            </div>
        <?php else: ?>
            <!-- Liste dynamique des dernières prises -->
            <div class="d-flex flex-column gap-3">
                <?php foreach($dernieres_prises as $prise): ?>
                    <div class="card border-0 shadow-sm rounded-4 bg-white p-3 d-flex flex-row align-items-center">
                        <!-- Photo de la prise ou icône par défaut -->
                        <?php if (!empty($prise['PHOTO_CHEMIN'])): ?>
                            <img src="<?= htmlspecialchars($prise['PHOTO_CHEMIN']) ?>" class="mini-catch-img shadow-sm" alt="Prise">
                        <?php else: ?>
                            <div class="bg-light d-flex align-items-center justify-content-center mini-catch-img shadow-sm">
                                <span class="material-symbols-rounded text-muted">no_photography</span>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Informations -->
                        <div class="ms-3 flex-grow-1">
                            <h6 class="fw-bold text-dark mb-1 d-flex align-items-center">
                                <?php if(!empty($prise['ICONE_CHEMIN'])): ?>
                                    <img src="<?= htmlspecialchars($prise['ICONE_CHEMIN']) ?>" style="width: 20px; height: 20px; object-fit: contain;" class="me-2">
                                <?php endif; ?>
                                <?= htmlspecialchars($prise['NOM_COM']) ?>
                            </h6>
                            <div class="text-muted small d-flex align-items-center">
                                <?php if(!empty($prise['TAILLE_CM'])): ?>
                                    <span class="me-3"><span class="material-symbols-rounded align-middle fs-6 me-1">straighten</span><?= $prise['TAILLE_CM'] ?> cm</span>
                                <?php endif; ?>
                                <span><span class="material-symbols-rounded align-middle fs-6 me-1">schedule</span><?= date('d/m', strtotime($prise['DATE_HEURE'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- Navbar inchangée[cite: 4] -->
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