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

    // 1. Récupération des informations de l'utilisateur
    $stmt = $pdo->prepare("SELECT PSEUDO, MAIL, DATE_CREATION, DESCRIPTION, PDP_CHEMIN, BANNIERE_CHEMIN FROM UTILISATEUR WHERE ID_UTILISATEUR = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $profil = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. NOUVEAU : Récupération des 3 dernières sessions avec le nom du spot, le type et le nombre de prises
    $stmt_sessions = $pdo->prepare("
        SELECT 
            s.ID_SESSION, 
            s.DATE_DEBUT, 
            ts.NOM_TYPE_SESSION, 
            sp.NOM_SPOT,
            COUNT(p.ID_PRISE) AS nb_prises
        FROM SESSION_P s
        LEFT JOIN TYPE_SESSION ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
        LEFT JOIN SPOT sp ON s.ID_SPOT = sp.ID_SPOT
        LEFT JOIN PRISE p ON s.ID_SESSION = p.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id
        GROUP BY s.ID_SESSION
        ORDER BY s.DATE_DEBUT DESC
        LIMIT 3
    ");
    $stmt_sessions->execute(['id' => $_SESSION['user_id']]);
    $dernieres_sessions = $stmt_sessions->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    
    <link href="css/style.css" rel="stylesheet">
    <link href="css/profil.css" rel="stylesheet">

</head>
<body>

    <!-- Si BANNIERE_CHEMIN existe, on remplace le fond par l'image -->
    <header class="profile-banner" <?php if(!empty($profil['BANNIERE_CHEMIN'])) echo 'style="background-image: url(\'' . htmlspecialchars($profil['BANNIERE_CHEMIN']) . '\');"'; ?>>
        
        <div class="profile-avatar-wrapper">
            <?php if(!empty($profil['PDP_CHEMIN'])): ?>
                <img src="<?= htmlspecialchars($profil['PDP_CHEMIN']) ?>" alt="Photo de profil" class="profile-avatar">
            <?php else: ?>
                <div class="profile-avatar">
                    <span class="material-symbols-rounded">person</span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container pb-5 mb-5">
        <div class="profile-info text-center mb-4">
            <h1 class="h3 fw-bold text-dark mb-1"><?= htmlspecialchars($profil['PSEUDO']) ?></h1>
            <p class="text-muted small mb-3"><?= htmlspecialchars($profil['MAIL']) ?></p>
            
            <?php if(!empty($profil['DESCRIPTION'])): ?>
                <p class="text-secondary bg-white p-3 rounded-4 shadow-sm mx-auto" style="max-width: 400px;">
                    "<?= htmlspecialchars($profil['DESCRIPTION']) ?>"
                </p>
            <?php else: ?>
                <p class="text-secondary fst-italic small">Aucune description renseignée.</p>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm rounded-4 p-3 mb-5 mx-auto" style="max-width: 400px;">
            <a href="modifier_profil.php" class="btn btn-light d-flex align-items-center justify-content-between p-3 mb-2 rounded-3 text-decoration-none">
                <div class="d-flex align-items-center">
                    <span class="material-symbols-rounded text-primary me-3">edit</span>
                    <span class="text-dark fw-medium">Modifier mon profil</span>
                </div>
                <span class="material-symbols-rounded text-muted">chevron_right</span>
            </a>
            
            <a href="#" class="btn btn-light d-flex align-items-center justify-content-between p-3 mb-3 rounded-3 text-decoration-none">
                <div class="d-flex align-items-center">
                    <span class="material-symbols-rounded text-primary me-3">analytics</span>
                    <span class="text-dark fw-medium">Mes statistiques</span>
                </div>
                <span class="material-symbols-rounded text-muted">chevron_right</span>
            </a>

            <a href="deconnexion.php" class="btn btn-outline-danger d-flex align-items-center justify-content-center p-3 rounded-3 fw-bold">
                <span class="material-symbols-rounded me-2">logout</span>
                Se déconnecter
            </a>
        </div>

        <!-- ================= SECTION : MES DERNIÈRES SESSIONS ================= -->
        <h5 class="fw-bold mb-3 text-dark text-center">Mes dernières sorties</h5>
        <div class="mx-auto mb-5" style="max-width: 400px;">
            <?php if(empty($dernieres_sessions)): ?>
                <div class="card border-0 shadow-sm rounded-4 p-4 text-center bg-white">
                    <span class="material-symbols-rounded text-muted mb-2" style="font-size: 36px;">history</span>
                    <p class="text-muted small mb-0">Vous n'avez pas encore enregistré de session.</p>
                </div>
            <?php else: ?>
                <?php foreach($dernieres_sessions as $sess): ?>
                    <div class="card border-0 shadow-sm rounded-4 p-3 mb-3 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-primary rounded-pill bg-opacity-10 text-primary">
                                <span class="material-symbols-rounded align-middle me-1" style="font-size: 14px;">calendar_today</span>
                                <?= date('d/m/Y', strtotime($sess['DATE_DEBUT'])) ?>
                            </span>
                            <span class="badge bg-light text-dark border shadow-sm"><?= $sess['nb_prises'] ?> prise(s)</span>
                        </div>
                        <h6 class="fw-bold mb-1 text-dark">
                            <?= !empty($sess['NOM_SPOT']) ? htmlspecialchars($sess['NOM_SPOT']) : 'Spot non précisé' ?>
                        </h6>
                        <p class="text-muted small mb-0 d-flex align-items-center">
                            <span class="material-symbols-rounded me-1 text-secondary" style="font-size: 16px;">phishing</span>
                            <?= !empty($sess['NOM_TYPE_SESSION']) ? htmlspecialchars($sess['NOM_TYPE_SESSION']) : 'Non défini' ?>
                        </p>
                    </div>
                <?php endforeach; ?>
                
                <div class="text-center mt-3">
                    <a href="carnet.php" class="text-primary fw-medium text-decoration-none small">Voir tout mon carnet <span class="material-symbols-rounded align-middle" style="font-size: 16px;">arrow_forward</span></a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <nav class="navbar fixed-bottom bg-white custom-navbar border-0 shadow-lg">
        <div class="container-fluid d-flex justify-content-around align-items-end px-2">
            
            <a href="accueil.php" class="nav-item d-flex flex-column align-items-center">
                <span class="material-symbols-rounded">home</span>
                <span class="menu-text">Accueil</span>
            </a>
            
            <a href="nouvelle_session.php" class="btn-add-catch">
                <span class="material-symbols-rounded text-white" style="font-size: 36px;">phishing</span>
            </a>

            <a href="profil.php" class="nav-item active d-flex flex-column align-items-center">
                <span class="material-symbols-rounded">person</span>
                <span class="menu-text">Profil</span>
            </a>
            
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>