<?php
require_once './includes/auth_bdd.php';
require_once './BDD/BDD_accueil.php'; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Récupération du pseudo pour un accueil personnalisé
    $stmtUser =$pdo->prepare("SELECT PSEUDO FROM UTILISATEUR WHERE ID_UTILISATEUR = :id");
    $stmtUser->execute(['id' => $_SESSION['user_id']]);$utilisateur = $stmtUser->fetch(PDO::FETCH_ASSOC);$pseudo = $utilisateur ? $utilisateur['PSEUDO'] : 'Pêcheur';

    // 2. Récupération dynamique des 3 dernières prises de l'utilisateur
    $stmtPrises =$pdo->prepare("
        SELECT p.PHOTO_CHEMIN, p.TAILLE_CM, e.NOM_COM, e.ICONE_CHEMIN, p.DATE_HEURE
        FROM PRISE p
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
        ORDER BY p.DATE_HEURE DESC
        LIMIT 3
    ");
    $stmtPrises->execute(['id_user' =>$_SESSION['user_id']]);
    $dernieres_prises =$stmtPrises->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

nettoyerImagesOrphelines($pdo,$_SESSION['user_id']);

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
        /* Style spécifique au widget météo */
        .weather-widget {
            background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%);
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
        
        <!-- WIDGET MÉTÉO DYNAMIQUE (Amélioré) -->
        <a href="meteo.php" class="text-decoration-none d-block mb-4">
            <div class="card border-0 shadow-sm rounded-4 weather-widget tool-card">
                <div class="card-body p-3">
                    <!-- Haut du widget : Ciel & Température -->
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                                <span class="material-symbols-rounded fs-2" id="weather-icon">location_searching</span>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-0" id="weather-desc">Localisation...</h6>
                                <small class="text-muted d-block" id="weather-city" style="font-size: 0.75rem;">Recherche météo</small>
                            </div>
                        </div>
                        <div class="text-end">
                            <h3 class="fw-bold text-primary mb-0" id="weather-temp">--°</h3>
                        </div>
                    </div>
                    
                    <!-- Bas du widget : Pression & Vent -->
                    <div class="d-flex justify-content-around border-top pt-2 mt-1">
                        <div class="text-center d-flex align-items-center">
                            <span class="material-symbols-rounded text-primary me-2" style="font-size: 20px;">compress</span>
                            <small class="fw-bold text-secondary" id="weather-press">-- hPa</small>
                        </div>
                        <div class="text-center d-flex align-items-center">
                            <span class="material-symbols-rounded text-info me-2" style="font-size: 20px;">air</span>
                            <small class="fw-bold text-secondary" id="weather-wind">-- km/h</small>
                        </div>
                    </div>
                </div>
            </div>
        </a>

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

            <!-- Tuile 4 : Statistiques -->
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
            <div class="card border-0 shadow-sm rounded-4 text-center p-5 mt-2">
                <span class="material-symbols-rounded text-muted mb-3" style="font-size: 48px;">phishing</span>
                <p class="text-muted mb-0">Aucune prise enregistrée pour le moment.<br>Préparez votre matériel !</p>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach($dernieres_prises as$prise): ?>
                    <div class="card border-0 shadow-sm rounded-4 bg-white p-3 d-flex flex-row align-items-center">
                        <?php if (!empty($prise['PHOTO_CHEMIN'])): ?>
                            <img src="<?= htmlspecialchars($prise['PHOTO_CHEMIN']) ?>" class="mini-catch-img shadow-sm" alt="Prise">
                        <?php else: ?>
                            <div class="bg-light d-flex align-items-center justify-content-center mini-catch-img shadow-sm">
                                <span class="material-symbols-rounded text-muted">no_photography</span>
                            </div>
                        <?php endif; ?>
                        
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

    <nav class="navbar fixed-bottom bg-white custom-navbar border-0 shadow-lg">
        <div class="container-fluid d-flex justify-content-around align-items-end px-2">
            <a href="accueil.php" class="nav-item active d-flex flex-column align-items-center text-primary text-decoration-none">
                <span class="material-symbols-rounded">home</span>
                <span class="menu-text" style="font-size: 0.75rem;">Accueil</span>
            </a>
            <a href="nouvelle_session.php" class="btn-add-catch">
                <span class="material-symbols-rounded text-white" style="font-size: 36px;">phishing</span>
            </a>
            <a href="profil.php" class="nav-item d-flex flex-column align-items-center text-secondary text-decoration-none">
                <span class="material-symbols-rounded">person</span>
                <span class="menu-text" style="font-size: 0.75rem;">Profil</span>
            </a>
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- SCRIPT DU WIDGET MÉTÉO (Géolocalisation & Open-Meteo avec Pression et Vent) -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const weatherDesc = document.getElementById('weather-desc');
            const weatherTemp = document.getElementById('weather-temp');
            const weatherCity = document.getElementById('weather-city');
            const weatherIcon = document.getElementById('weather-icon');
            const weatherPress = document.getElementById('weather-press');
            const weatherWind = document.getElementById('weather-wind');

            // Fonction pour traduire le code météo en icône Material Symbol
            function getWeatherDetails(code) {
                if(code === 0) return { text: 'Soleil dégagé', icon: 'clear_day' };
                if(code > 0 && code <= 3) return { text: 'Nuageux', icon: 'partly_cloudy_day' };
                if(code === 45 || code === 48) return { text: 'Brouillard', icon: 'foggy' };
                if(code >= 51 && code <= 67) return { text: 'Pluie', icon: 'rainy' };
                if(code >= 71 && code <= 77) return { text: 'Neige', icon: 'weather_snowy' };
                if(code >= 80 && code <= 82) return { text: 'Averses', icon: 'rainy' };
                if(code >= 95) return { text: 'Orage', icon: 'thunderstorm' };
                return { text: 'Inconnu', icon: 'cloud' };
            }

            // Traduction des degrés du vent en points cardinaux (basé sur ton code existant)
            function getWindDirectionStr(degree) {
                if (degree === null || degree === undefined) return '';
                if (degree > 337.5 || degree <= 22.5) return 'N';
                if (degree > 22.5 && degree <= 67.5) return 'NE';
                if (degree > 67.5 && degree <= 112.5) return 'E';
                if (degree > 112.5 && degree <= 157.5) return 'SE';
                if (degree > 157.5 && degree <= 202.5) return 'S';
                if (degree > 202.5 && degree <= 247.5) return 'SO';
                if (degree > 247.5 && degree <= 292.5) return 'O';
                if (degree > 292.5 && degree <= 337.5) return 'NO';
                return '';
            }

            // Récupération du GPS du navigateur
            if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    // Appel à Open-Meteo avec pression et vent inclus
                    fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,weathercode,surface_pressure,wind_speed_10m,wind_direction_10m`)
                    .then(response => response.json())
                    .then(data => {
                        if(data.current) {
                            const details = getWeatherDetails(data.current.weathercode);
                            const windDir = getWindDirectionStr(data.current.wind_direction_10m);
                            
                            // Mise à jour de l'affichage principal
                            weatherDesc.innerText = details.text;
                            weatherTemp.innerText = Math.round(data.current.temperature_2m) + '°C';
                            weatherCity.innerText = "Position actuelle (GPS)";
                            weatherIcon.innerText = details.icon;
                            
                            // Mise à jour des nouvelles données (Pression & Vent)
                            weatherPress.innerText = Math.round(data.current.surface_pressure) + ' hPa';
                            weatherWind.innerText = Math.round(data.current.wind_speed_10m) + ' km/h ' + (windDir ? `(${windDir})` : '');
                        }
                    })
                    .catch(error => {
                        weatherDesc.innerText = 'Erreur réseau';
                        weatherCity.innerText = 'Impossible de charger la météo';
                        weatherIcon.innerText = 'cloud_off';
                    });
                }, function(error) {
                    // Si l'utilisateur refuse le GPS
                    weatherDesc.innerText = 'GPS désactivé';
                    weatherCity.innerText = 'Activez la localisation pour la météo';
                    weatherIcon.innerText = 'location_disabled';
                });
            } else {
                weatherDesc.innerText = 'Non supporté';
                weatherCity.innerText = 'Votre navigateur ne supporte pas le GPS';
                weatherIcon.innerText = 'location_off';
            }
        });
    </script>
</body>
</html>