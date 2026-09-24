<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';

// Vérification de l'ID de session passé dans l'URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: profil.php');
    exit();
}

$id_session = (int)$_GET['id'];
$id_user = $_SESSION['user_id'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Récupération des informations globales de la Session
    $stmtSess = $pdo->prepare("
        SELECT s.*, sp.NOM_SPOT, sp.LOCALISATION, ts.NOM_TYPE_SESSION
        FROM SESSION_P s
        LEFT JOIN SPOT sp ON s.ID_SPOT = sp.ID_SPOT
        LEFT JOIN TYPE_SESSION ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
        WHERE s.ID_SESSION = :id_session AND s.ID_UTILISATEUR = :id_user
    ");
    $stmtSess->execute(['id_session' => $id_session, 'id_user' => $id_user]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    // Sécurité : si la session n'existe pas ou n'appartient pas à l'utilisateur
    if (!$session) {
        header('Location: profil.php');
        exit();
    }

    // 2. Récupération du détail de toutes les Prises de cette session
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

    // 3. --- MOTEUR DE STATISTIQUES PHP ---
    
    // A. Calcul du temps de pêche
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

    // B. Extraction des records et du matériel dominant
    $nb_prises = count($prises);
    $plus_gros_cm = 0;
    $meilleur_poisson = "Aucun";
    $stats_materiel = [];
    
    $lat_map = 46.603354; // Centre de la France par défaut
    $lng_map = 1.888334;
    $zoom_map = 5;

    foreach ($prises as $p) {
        // Recherche du plus gros poisson
        if (!empty($p['TAILLE_CM']) && $p['TAILLE_CM'] > $plus_gros_cm) {
            $plus_gros_cm = $p['TAILLE_CM'];
            $meilleur_poisson = $p['NOM_COM'] . " (" . $plus_gros_cm . " cm)";
        }
        
        // Comptage des leurres / appâts
        $matos = !empty($p['NOM_LEURRE']) ? $p['NOM_LEURRE'] : (!empty($p['NOM_APPAT']) ? $p['NOM_APPAT'] : null);
        if ($matos) {
            if (!isset($stats_materiel[$matos])) $stats_materiel[$matos] = 0;
            $stats_materiel[$matos]++;
        }
    }

    // Détermination du meilleur leurre/appât
    $meilleur_materiel = "Non précisé";
    if (!empty($stats_materiel)) {
        arsort($stats_materiel);
        $meilleur_materiel = array_key_first($stats_materiel);
    }

    // C. Récupération des données Météo (depuis la première prise enregistrée)
    $meteo_temp = ($nb_prises > 0 && !empty($prises[0]['TEMPERATURE'])) ? $prises[0]['TEMPERATURE'] : '--';
    $meteo_press = ($nb_prises > 0 && !empty($prises[0]['PRESSION_HPA'])) ? $prises[0]['PRESSION_HPA'] : '--';
    $meteo_wind = ($nb_prises > 0 && !empty($prises[0]['VITESSE_VENT'])) ? $prises[0]['VITESSE_VENT'] : '--';

    // D. Récupération des coordonnées pour la carte
    // Priorité 1 : La localisation globale du spot
    if (!empty($session['LOCALISATION'])) {
        $coords = explode(',', $session['LOCALISATION']);
        if (count($coords) == 2) {
            $lat_map = floatval(trim($coords[0]));
            $lng_map = floatval(trim($coords[1]));
            $zoom_map = 14;
        }
    } 
    // Priorité 2 : Les coordonnées EXIF de la première prise
    elseif ($nb_prises > 0 && !empty($prises[0]['LATITUDE']) && !empty($prises[0]['LONGITUDE'])) {
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
    <header class="profile-banner-responsive d-flex flex-column align-items-center justify-content-center" style="height: 180px;">
        <a href="profil.php" class="settings-btn" style="left: 20px; right: auto;" title="Retour au profil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        
        <h1 class="h3 fw-bold text-white mb-2 text-shadow text-center px-4">
            <?= !empty($session['NOM_SPOT']) ? htmlspecialchars($session['NOM_SPOT']) : 'Spot non précisé' ?>
        </h1>
        <span class="badge bg-white text-primary rounded-pill px-3 py-2 shadow-sm">
            <span class="material-symbols-rounded align-middle fs-6 me-1">calendar_month</span>
            <?= date('d/m/Y à H:i', strtotime($session['DATE_DEBUT'])) ?>
        </span>
    </header>

    <main class="container pb-5 mb-5" style="margin-top: -20px; position: relative; z-index: 2;">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10">

                <!-- TABLEAU DE BORD DES STATISTIQUES -->
                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                    <h5 class="fw-bold text-dark mb-4 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">query_stats</span>
                        Bilan de la session
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

                <!-- CARTE LEAFLET ET MÉTÉO (CONDITIONS DE SESSION) -->
                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                    <h5 class="fw-bold text-dark mb-4 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">location_on</span>
                        Position & Conditions Météo
                    </h5>
                    
                    <!-- Carte GPS -->
                    <div id="session-map" class="rounded-4 overflow-hidden shadow-sm border border-light-subtle mb-4" style="height: 250px; z-index: 1;"></div>
                    
                    <!-- Cartes Météo détaillées -->
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
                <h5 class="fw-bold text-dark mb-4 mt-5 d-flex align-items-center">
                    <span class="material-symbols-rounded text-primary me-2">photo_library</span>
                    Galerie des prises
                </h5>

                <?php if (empty($prises)): ?>
                    <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                        <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">sentiment_dissatisfied</span>
                        <p class="text-muted mb-0">Session capot ! Aucune prise n'a été enregistrée.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach($prises as $p): ?>
                            <div class="col-12 col-md-6">
                                <div class="card border-0 shadow-sm rounded-4 h-100 bg-white hover-card">
                                    
                                    <?php if (!empty($p['PHOTO_CHEMIN'])): ?>
                                        <img src="<?= htmlspecialchars($p['PHOTO_CHEMIN']) ?>" alt="Prise" class="catch-img">
                                    <?php else: ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center catch-img border-bottom">
                                            <span class="material-symbols-rounded text-muted" style="font-size: 60px;">no_photography</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                                                <?php if(!empty($p['ICONE_CHEMIN'])): ?>
                                                    <img src="<?= htmlspecialchars($p['ICONE_CHEMIN']) ?>" alt="Icone" style="width: 25px; height: 25px; object-fit: contain;" class="me-2">
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

    <!-- NAVIGATION FIXE -->
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

    <!-- SCRIPTS -->
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