<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: profil.php');
    exit();
}

$id_session = (int)$_GET['id'];
$id_user = $_SESSION['user_id'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // =========================================================================
    // 1. GESTION DES SUPPRESSIONS (Session entière ou Prise individuelle)
    // =========================================================================
    
    // A. Suppression de la Session complète
    if (isset($_POST['delete_session'])) {
        // Étape 1 : Récupérer toutes les photos liées à cette session
        $stmtPhotos = $pdo->prepare("SELECT PHOTO_CHEMIN FROM PRISE WHERE ID_SESSION = ? AND PHOTO_CHEMIN IS NOT NULL");
        $stmtPhotos->execute([$id_session]);
        $photos = $stmtPhotos->fetchAll(PDO::FETCH_COLUMN);

        // Étape 2 : Supprimer physiquement les fichiers du serveur
        foreach ($photos as $photo) {
            if (file_exists($photo)) {
                unlink($photo);
            }
        }

        // Étape 3 : Supprimer les prises en BDD
        $stmtDelPrises = $pdo->prepare("DELETE FROM PRISE WHERE ID_SESSION = ?");
        $stmtDelPrises->execute([$id_session]);

        // Étape 4 : Supprimer la session (on revérifie l'ID_UTILISATEUR par sécurité)
        $stmtDelSession = $pdo->prepare("DELETE FROM SESSION_P WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?");
        $stmtDelSession->execute([$id_session, $id_user]);

        // Redirection vers le profil
        header('Location: profil.php');
        exit();
    }

    // B. Suppression d'une Prise individuelle
    if (isset($_POST['delete_prise']) && isset($_POST['id_prise'])) {
        $id_prise = (int)$_POST['id_prise'];
        
        // Vérifier que la prise appartient bien à une session de cet utilisateur
        $stmtCheck = $pdo->prepare("
            SELECT p.PHOTO_CHEMIN 
            FROM PRISE p 
            JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
            WHERE p.ID_PRISE = ? AND s.ID_UTILISATEUR = ?
        ");
        $stmtCheck->execute([$id_prise, $id_user]);
        $photo_a_supprimer = $stmtCheck->fetchColumn();

        if ($photo_a_supprimer !== false) {
            // Supprimer la photo du disque
            if (!empty($photo_a_supprimer) && file_exists($photo_a_supprimer)) {
                unlink($photo_a_supprimer);
            }
            // Supprimer de la BDD
            $stmtDel = $pdo->prepare("DELETE FROM PRISE WHERE ID_PRISE = ?");
            $stmtDel->execute([$id_prise]);
            
            $message_succes = "Prise supprimée avec succès.";
        }
    }

    // =========================================================================
    // 2. RÉCUPÉRATION DES DONNÉES POUR L'AFFICHAGE
    // =========================================================================

    $stmtSess = $pdo->prepare("
        SELECT s.*, sp.NOM_SPOT, sp.LOCALISATION, ts.NOM_TYPE_SESSION
        FROM SESSION_P s
        LEFT JOIN SPOT sp ON s.ID_SPOT = sp.ID_SPOT
        LEFT JOIN TYPE_SESSION ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
        WHERE s.ID_SESSION = :id_session AND s.ID_UTILISATEUR = :id_user
    ");
    $stmtSess->execute(['id_session' => $id_session, 'id_user' => $id_user]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        header('Location: profil.php');
        exit();
    }

    // Ajout de la colonne QUANTITE dans l'extraction
    $stmtPrises = $pdo->prepare("
        SELECT p.*, e.NOM_COM, e.ICONE_CHEMIN, l.NOM_LEURRE, a.NOM_APPAT, t.NOM_TECHNIQUE
        FROM PRISE p
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE
        LEFT JOIN LEURRE l ON p.ID_LEURRE = l.ID_LEURRE
        LEFT JOIN APPAT a ON p.ID_APPAT = a.ID_APPAT
        LEFT JOIN TECHNIQUE t ON p.ID_TECHNIQUE = t.ID_TECHNIQUE
        WHERE p.ID_SESSION = :id_session
        ORDER BY p.DATE_HEURE ASC
    ");
    $stmtPrises->execute(['id_session' => $id_session]);
    $prises = $stmtPrises->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // 3. MOTEUR DE STATISTIQUES PHP
    // =========================================================================
    
    $temps_de_peche = "Non précisé";
    if (!empty($session['DATE_FIN'])) {
        $debut = new DateTime($session['DATE_DEBUT']);
        $fin = new DateTime($session['DATE_FIN']);
        $interval = $debut->diff($fin);
        $temps_de_peche = $interval->format('%h h %i min');
        if ($interval->d > 0) {
            $temps_de_peche = $interval->format('%d j %h h %i m');
        }
    }

    // NOUVEAU CALCUL : On additionne les quantités au lieu de compter les lignes
    $nb_prises = 0;
    $plus_gros_cm = 0;
    $meilleur_poisson = "Aucun";
    $stats_materiel = [];
    
    $lat_map = 46.603354; 
    $lng_map = 1.888334;
    $zoom_map = 5;

    foreach ($prises as $p) {
        // Ajout de la quantité de cette prise spécifique au total
        $qte_actuelle = !empty($p['QUANTITE']) ? (int)$p['QUANTITE'] : 1;
        $nb_prises += $qte_actuelle;

        if (!empty($p['TAILLE_CM']) && $p['TAILLE_CM'] > $plus_gros_cm) {
            $plus_gros_cm = $p['TAILLE_CM'];
            $meilleur_poisson = $p['NOM_COM'] . " (" . $plus_gros_cm . " cm)";
        }

        $matos = !empty($p['NOM_LEURRE']) ? $p['NOM_LEURRE'] : (!empty($p['NOM_APPAT']) ? $p['NOM_APPAT'] : null);
        if ($matos) {
            if (!isset($stats_materiel[$matos])) $stats_materiel[$matos] = 0;
            // Si on a pêché 11 poissons avec ce leurre, ça compte pour 11 utilisations réussies
            $stats_materiel[$matos] += $qte_actuelle;
        }
    }

    $meilleur_materiel = "Non précisé";
    if (!empty($stats_materiel)) {
        arsort($stats_materiel);
        $meilleur_materiel = array_key_first($stats_materiel);
    }

    // Récupération météo depuis la Session et non plus depuis la prise
    $meteo_temp = !empty($session['TEMPERATURE']) ? $session['TEMPERATURE'] : '--';
    $meteo_press = !empty($session['PRESSION_HPA']) ? $session['PRESSION_HPA'] : '--';
    $meteo_wind = !empty($session['VITESSE_VENT']) ? $session['VITESSE_VENT'] : '--';

    if (!empty($session['LOCALISATION'])) {
        $coords = explode(',', $session['LOCALISATION']);
        if (count($coords) == 2) {
            $lat_map = floatval(trim($coords[0]));
            $lng_map = floatval(trim($coords[1]));
            $zoom_map = 14;
        }
    } elseif (count($prises) > 0 && !empty($prises[0]['LATITUDE']) && !empty($prises[0]['LONGITUDE'])) {
        $lat_map = floatval($prises[0]['LATITUDE']);
        $lng_map = floatval($prises[0]['LONGITUDE']);
        $zoom_map = 14;
    }

} catch (PDOException $e) {
    die("Erreur : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail de Session - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <link href="css/style.css" rel="stylesheet">
    <link href="css/profil.css" rel="stylesheet">
    <link rel="stylesheet" href="detail_session.css">
</head>
<body class="bg-light">

    <!-- BANNIÈRE RESPONSIVE -->
    <header class="profile-banner-responsive d-flex flex-column align-items-center justify-content-center position-relative" style="height: 180px;">
        
        <!-- Bouton Retour -->
        <a href="profil.php" class="settings-btn" style="left: 20px; right: auto;" title="Retour au profil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        
        <!-- Boutons d'édition et suppression de la session (Top Right) -->
        <div class="position-absolute d-flex gap-2" style="right: 20px; top: 20px; z-index: 10;">
            <a href="editer_session.php?id=<?= $id_session ?>" class="btn btn-sm btn-light rounded-circle text-primary p-2 shadow-sm d-flex align-items-center justify-content-center" title="Modifier la session">
                <span class="material-symbols-rounded" style="font-size: 20px;">edit</span>
            </a>
            <form method="POST" class="m-0" onsubmit="return confirm('Es-tu sûr de vouloir supprimer cette session ? Toutes les prises et photos associées seront perdues.');">
                <button type="submit" name="delete_session" class="btn btn-sm btn-danger rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" title="Supprimer la session">
                    <span class="material-symbols-rounded" style="font-size: 20px;">delete</span>
                </button>
            </form>
        </div>
        
        <h1 class="h3 fw-bold text-white mb-2 text-shadow text-center px-4">
            <?= !empty($session['NOM_SPOT']) ? htmlspecialchars($session['NOM_SPOT']) : 'Spot non précisé' ?>
        </h1>
        <span class="badge bg-white text-primary rounded-pill px-3 py-2 shadow-sm">
            <span class="material-symbols-rounded align-middle fs-6 me-1">calendar_month</span>
            <?= date('d/m/Y à H:i', strtotime($session['DATE_DEBUT'])) ?>
        </span>
    </header>

    <main class="container pb-5 mb-5" style="margin-top: -20px; position: relative; z-index: 2;">
        
        <?php if(isset($message_succes)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm text-center mb-4" role="alert">
                <?= htmlspecialchars($message_succes) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-12 col-lg-10">

                <!-- TABLEAU DE BORD DES STATISTIQUES -->
                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                    <h5 class="fw-bold text-dark mb-4 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">query_stats</span> Bilan de la session
                    </h5>
                    
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <span class="material-symbols-rounded stat-icon">phishing</span>
                                <h3 class="fw-bold text-dark mb-0"><?= $nb_prises ?></h3>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem;">Prise(s)</small>
                            </div>
                        </div>
                        
                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <span class="material-symbols-rounded stat-icon">timer</span>
                                <h5 class="fw-bold text-dark mb-0 mt-2"><?= $temps_de_peche ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem;">Temps de pêche</small>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <span class="material-symbols-rounded stat-icon">emoji_events</span>
                                <h6 class="fw-bold text-dark mb-0 mt-2 text-truncate"><?= htmlspecialchars($meilleur_poisson) ?></h6>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem;">Plus gros poisson</small>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <span class="material-symbols-rounded stat-icon">set_meal</span>
                                <h6 class="fw-bold text-dark mb-0 mt-2 text-truncate"><?= htmlspecialchars($meilleur_materiel) ?></h6>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem;">Matériel fétiche</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARTE LEAFLET ET MÉTÉO -->
                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                    <h5 class="fw-bold text-dark mb-4 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">location_on</span> Position & Conditions Météo
                    </h5>
                    <div id="session-map" class="rounded-4 overflow-hidden shadow-sm border border-light-subtle mb-4" style="height: 250px; z-index: 1;"></div>
                    
                    <div class="row g-3">
                        <div class="col-4">
                            <div class="bg-light rounded-4 p-3 text-center border h-100 hover-card">
                                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">thermostat</span>
                                <h5 class="fw-bold text-dark mb-0"><?= $meteo_temp ?> <?= $meteo_temp !== '--' ? '°C' : '' ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">Température</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-light rounded-4 p-3 text-center border h-100 hover-card">
                                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">compress</span>
                                <h5 class="fw-bold text-dark mb-0"><?= $meteo_press ?> <?= $meteo_press !== '--' ? 'hPa' : '' ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">Pression</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-light rounded-4 p-3 text-center border h-100 hover-card">
                                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">air</span>
                                <h5 class="fw-bold text-dark mb-0"><?= $meteo_wind ?> <?= $meteo_wind !== '--' ? 'km/h' : '' ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">Vent</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GALERIE DES PRISES -->
                <div class="d-flex justify-content-between align-items-center mb-4 mt-5">
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">photo_library</span> Galerie des prises
                    </h5>
                    <a href="form_prise.php?action=add&id_session=<?= $id_session ?>" class="btn btn-sm btn-primary rounded-pill fw-semibold px-3 shadow-sm d-flex align-items-center">
                        <span class="material-symbols-rounded fs-6 me-1">add</span> Ajouter
                    </a>
                </div>

                <?php if (empty($prises)): ?>
                    <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                        <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">sentiment_dissatisfied</span>
                        <p class="text-muted mb-0">Session capot ! Aucune prise n'a été enregistrée.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach($prises as $p): ?>
                            <div class="col-12 col-md-6">
                                <div class="card border-0 shadow-sm rounded-4 h-100 bg-white hover-card position-relative overflow-hidden">
                                    
                                    <!-- Boutons de modification et suppression de la prise -->
                                    <div class="position-absolute top-0 end-0 m-2 d-flex gap-2" style="z-index: 10;">
                                        <a href="form_prise.php?action=edit&id=<?= $p['ID_PRISE'] ?>" class="btn btn-sm btn-light rounded-circle text-primary p-2 shadow-sm d-flex align-items-center justify-content-center" title="Modifier la prise">
                                            <span class="material-symbols-rounded" style="font-size: 18px;">edit</span>
                                        </a>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Es-tu sûr de vouloir supprimer cette prise et sa photo ?');">
                                            <input type="hidden" name="id_prise" value="<?= $p['ID_PRISE'] ?>">
                                            <button type="submit" name="delete_prise" class="btn btn-sm btn-light rounded-circle text-danger p-2 shadow-sm d-flex align-items-center justify-content-center" title="Supprimer la prise">
                                                <span class="material-symbols-rounded" style="font-size: 18px;">delete</span>
                                            </button>
                                        </form>
                                    </div>

                                    <?php if (!empty($p['PHOTO_CHEMIN'])): ?>
                                        <img src="<?= htmlspecialchars($p['PHOTO_CHEMIN']) ?>" alt="Prise" class="catch-img">
                                    <?php else: ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center catch-img border-bottom">
                                            <span class="material-symbols-rounded text-muted" style="font-size: 60px;">no_photography</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-start mb-2 pe-5">
                                            <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                                                <?php if(!empty($p['ICONE_CHEMIN'])): ?>
                                                    <img src="<?= htmlspecialchars($p['ICONE_CHEMIN']) ?>" alt="Icone" style="width: 25px; height: 25px; object-fit: contain;" class="me-2">
                                                <?php endif; ?>
                                                
                                                <!-- AFFICHAGE DE LA QUANTITÉ SI > 1 -->
                                                <?php 
                                                    $qte = !empty($p['QUANTITE']) ? (int)$p['QUANTITE'] : 1;
                                                    if ($qte > 1): 
                                                ?>
                                                    <span class="badge bg-primary text-white rounded-pill px-2 py-1 me-2" style="font-size: 0.8rem;">x<?= $qte ?></span>
                                                <?php endif; ?>

                                                <?= htmlspecialchars($p['NOM_COM']) ?>
                                            </h5>
                                            <?php if ($p['RELACHE']): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success rounded-pill border border-success border-opacity-25 px-2 py-1">No-Kill</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mb-3 text-muted small fw-medium">
                                            <?php if(!empty($p['TAILLE_CM'])) echo '<span class="me-3"><span class="material-symbols-rounded align-middle fs-6 me-1 text-primary">straighten</span>' . $p['TAILLE_CM'] . ' cm</span>'; ?>
                                            <?php if(!empty($p['POIDS_KG'])) echo '<span><span class="material-symbols-rounded align-middle fs-6 me-1 text-primary">scale</span>' . $p['POIDS_KG'] . ' kg</span>'; ?>
                                        </div>

                                        <ul class="list-group list-group-flush rounded-3 border">
                                            <?php if(!empty($p['NOM_LEURRE']) || !empty($p['NOM_APPAT'])): ?>
                                                <li class="list-group-item bg-transparent text-secondary small py-2 d-flex align-items-center">
                                                    <span class="material-symbols-rounded me-2 fs-6">phishing</span> 
                                                    <?= !empty($p['NOM_LEURRE']) ? htmlspecialchars($p['NOM_LEURRE']) : htmlspecialchars($p['NOM_APPAT']) ?>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($p['NOM_TECHNIQUE'])): ?>
                                                <li class="list-group-item bg-transparent text-secondary small py-2 d-flex align-items-center">
                                                    <span class="material-symbols-rounded me-2 fs-6">directions_run</span> 
                                                    <?= htmlspecialchars($p['NOM_TECHNIQUE']) ?>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <li class="list-group-item bg-transparent text-muted small py-2 fst-italic d-flex align-items-center">
                                                <span class="material-symbols-rounded me-2 fs-6">schedule</span> 
                                                Pris à <?= date('H:i', strtotime($p['DATE_HEURE'])) ?>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const lat = <?= $lat_map ?>;
            const lng = <?= $lng_map ?>;
            const zoom = <?= $zoom_map ?>;

            let map = L.map('session-map').setView([lat, lng], zoom);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
                attribution: '© OpenStreetMap' 
            }).addTo(map);

            L.marker([lat, lng]).addTo(map)
                .bindPopup("<b>Lieu de pêche</b>")
                .openPopup();
        });
    </script>
</body>
</html>