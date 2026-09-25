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

    $id_user = $_SESSION['user_id'];

    // 1. Récupération des informations de l'utilisateur
    $stmt = $pdo->prepare("SELECT PSEUDO, MAIL, DATE_CREATION, DESCRIPTION, PDP_CHEMIN, BANNIERE_CHEMIN FROM UTILISATEUR WHERE ID_UTILISATEUR = :id");
    $stmt->execute(['id' => $id_user]);
    $profil = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Récupération des sessions (Avec l'image générique et la somme des quantités)
    $stmt_sessions = $pdo->prepare("
        SELECT 
            s.ID_SESSION, 
            s.DATE_DEBUT, 
            ts.NOM_TYPE_SESSION, 
            ts.TYPE_SES_ICONE_CHEMIN,
            sp.NOM_SPOT,
            COALESCE(SUM(p.QUANTITE), 0) AS nb_prises,
            (SELECT p2.PHOTO_CHEMIN FROM PRISE p2 WHERE p2.ID_SESSION = s.ID_SESSION AND p2.PHOTO_CHEMIN IS NOT NULL LIMIT 1) AS premiere_photo
        FROM SESSION_P s
        LEFT JOIN TYPE_SESSION ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
        LEFT JOIN SPOT sp ON s.ID_SPOT = sp.ID_SPOT
        LEFT JOIN PRISE p ON s.ID_SESSION = p.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id
        GROUP BY s.ID_SESSION
        ORDER BY s.DATE_DEBUT DESC
        LIMIT 10
    ");
    $stmt_sessions->execute(['id' => $id_user]);
    $dernieres_sessions = $stmt_sessions->fetchAll(PDO::FETCH_ASSOC);

    // 3. Récupération des Records Personnels (PB)
    $stmt_pb = $pdo->prepare("
        SELECT 
            e.NOM_COM, 
            e.ICONE_CHEMIN, 
            MAX(p.TAILLE_CM) AS record_taille
        FROM PRISE p
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE
        WHERE s.ID_UTILISATEUR = :id AND p.TAILLE_CM IS NOT NULL
        GROUP BY e.ID_ESPECE, e.NOM_COM, e.ICONE_CHEMIN
        ORDER BY record_taille DESC
    ");
    $stmt_pb->execute(['id' => $id_user]);
    $records_personnels = $stmt_pb->fetchAll(PDO::FETCH_ASSOC);

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
<body class="bg-light">

    <!-- BANNIÈRE RESPONSIVE -->
    <header class="profile-banner-responsive" <?php if(!empty($profil['BANNIERE_CHEMIN'])) echo 'style="background-image: url(\'' . htmlspecialchars($profil['BANNIERE_CHEMIN']) . '\');"'; ?>>
        
        <!-- BOUTON DÉCONNEXION (Roue crantée) -->
        <a href="deconnexion.php" class="settings-btn" title="Se déconnecter">
            <span class="material-symbols-rounded">settings</span>
        </a>
        
        <!-- PHOTO DE PROFIL -->
        <div class="avatar-wrapper">
            <?php if(!empty($profil['PDP_CHEMIN'])): ?>
                <img src="<?= htmlspecialchars($profil['PDP_CHEMIN']) ?>" alt="Photo de profil" class="avatar-circle">
            <?php else: ?>
                <div class="avatar-circle">
                    <span class="material-symbols-rounded">person</span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container pb-5 mb-5 main-profile-content">
        
        <!-- GRILLE CENTRALE -->
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">
                
                <!-- INFOS UTILISATEUR & DESCRIPTION -->
                <div class="text-center mb-4">
                    <h1 class="h2 fw-bold text-dark mb-1"><?= htmlspecialchars($profil['PSEUDO']) ?></h1>
                    
                    <?php if(!empty($profil['DESCRIPTION'])): ?>
                        <p class="text-secondary bg-white p-3 rounded-4 shadow-sm mt-3 mx-auto" style="font-size: 1rem; max-width: 600px;">
                            "<?= htmlspecialchars($profil['DESCRIPTION']) ?>"
                        </p>
                    <?php endif; ?>

                    <!-- BOUTON MODIFIER -->
                    <a href="modifier_profil.php" class="btn btn-outline-primary rounded-pill px-4 py-2 mt-3 fw-bold border-2 shadow-sm">
                        Modifier mon profil
                    </a>
                </div>

                <hr class="my-5 text-muted opacity-25">

                <!-- ONGLETS -->
                <ul class="nav nav-pills mb-4 nav-fill custom-tabs gap-2" id="profil-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill fw-bold py-2" id="sessions-tab" data-bs-toggle="pill" data-bs-target="#tab-sessions" type="button" role="tab">Dernière sortie</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill fw-bold py-2" id="pb-tab" data-bs-toggle="pill" data-bs-target="#tab-pb" type="button" role="tab">Mes PB</button>
                    </li>
                </ul>

                <div class="tab-content" id="profil-tabsContent">
                    
                    <!-- ================= CONTENU : DERNIÈRES SORTIES ================= -->
                    <div class="tab-pane fade show active" id="tab-sessions" role="tabpanel">
                        <?php if(empty($dernieres_sessions)): ?>
                            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white mt-4">
                                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">history</span>
                                <p class="text-muted mb-0">Vous n'avez pas encore enregistré de session.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-4 mt-2">
                                <?php foreach($dernieres_sessions as $sess): ?>
                                    <div class="col-12 col-md-6">
                                        <a href="detail_session.php?id=<?= $sess['ID_SESSION'] ?>" class="text-decoration-none text-dark d-block h-100 hover-card">
                                            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden h-100 d-flex flex-column">
                                                
                                                <!-- IMAGE DE LA SESSION (Vraie photo ou Illustration générique) -->
                                                <div class="position-relative">
                                                    <?php if(!empty($sess['premiere_photo'])): ?>
                                                        <img src="<?= htmlspecialchars($sess['premiere_photo']) ?>" class="card-img-top w-100" style="height: 220px; object-fit: cover;" alt="Photo session">
                                                    <?php elseif(!empty($sess['TYPE_SES_ICONE_CHEMIN'])): ?>
                                                        <img src="<?= htmlspecialchars($sess['TYPE_SES_ICONE_CHEMIN']) ?>" class="card-img-top w-100" style="height: 220px; object-fit: cover;" alt="Illustration session">
                                                    <?php else: ?>
                                                        <div class="bg-light d-flex align-items-center justify-content-center w-100" style="height: 220px;">
                                                            <span class="material-symbols-rounded text-muted" style="font-size: 60px;">no_photography</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Badge de date sur l'image -->
                                                    <span class="badge bg-dark bg-opacity-75 text-white position-absolute top-0 end-0 m-3 px-3 py-2 rounded-pill shadow-sm fs-6">
                                                        <?= date('d/m/Y', strtotime($sess['DATE_DEBUT'])) ?>
                                                    </span>
                                                </div>
                                                
                                                <!-- INFOS SOUS L'IMAGE -->
                                                <div class="card-body p-4 d-flex flex-column justify-content-between">
                                                    <div>
                                                        <h5 class="fw-bold mb-1 text-dark">
                                                            <?= !empty($sess['NOM_SPOT']) ? htmlspecialchars($sess['NOM_SPOT']) : 'Spot non précisé' ?>
                                                        </h5>
                                                        <p class="text-muted mb-3 d-flex align-items-center">
                                                            <span class="material-symbols-rounded me-2 text-primary" style="font-size: 20px;">water_drop</span>
                                                            <?= !empty($sess['NOM_TYPE_SESSION']) ? htmlspecialchars($sess['NOM_TYPE_SESSION']) : 'Milieu non défini' ?>
                                                        </p>
                                                    </div>
                                                    <div class="text-end border-top pt-3 mt-2">
                                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill fs-6 fw-bold">
                                                            <?= $sess['nb_prises'] ?> prise(s) <span class="material-symbols-rounded align-middle ms-1" style="font-size: 18px;">chevron_right</span>
                                                        </span>
                                                    </div>
                                                </div>
                                                
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ================= CONTENU : MES PB (Records) ================= -->
                    <div class="tab-pane fade" id="tab-pb" role="tabpanel">
                        <?php if(empty($records_personnels)): ?>
                            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white mt-4">
                                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">emoji_events</span>
                                <p class="text-muted mb-0">Aucun record enregistré. Renseignez la taille lors de vos prises !</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mt-2">
                                <?php foreach($records_personnels as $rec): ?>
                                    <div class="col-12 col-md-6 col-lg-4">
                                        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center bg-white hover-card">
                                            
                                            <!-- Icône du poisson -->
                                            <div class="bg-light rounded-3 p-2 me-3 d-flex justify-content-center align-items-center" style="width: 70px; height: 70px;">
                                                <?php if(!empty($rec['ICONE_CHEMIN'])): ?>
                                                    <img src="<?= htmlspecialchars($rec['ICONE_CHEMIN']) ?>" alt="Icone" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                                <?php else: ?>
                                                    <span class="material-symbols-rounded text-secondary" style="font-size: 40px;">set_meal</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="flex-grow-1">
                                                <h6 class="fw-bold mb-1 text-dark fs-5">
                                                    <?= htmlspecialchars($rec['NOM_COM']) ?> <span class="text-primary">: <?= $rec['record_taille'] ?> cm</span>
                                                </h6>
                                            </div>
                                            
                                            <span class="material-symbols-rounded text-warning fs-3 opacity-50 ms-2">emoji_events</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
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