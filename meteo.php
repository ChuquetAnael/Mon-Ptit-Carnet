<?php
require_once './includes/auth_bdd.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupération de tous les spots de l'utilisateur qui ont des coordonnées GPS
    $stmtSpots =$pdo->prepare("
        SELECT ID_SPOT, NOM_SPOT, LOCALISATION 
        FROM SPOT 
        WHERE ID_UTILISATEUR = :id_user AND LOCALISATION IS NOT NULL AND LOCALISATION != ''
        ORDER BY NOM_SPOT ASC
    ");
    $stmtSpots->execute(['id_user' =>$_SESSION['user_id']]);
    $spotsRaw =$stmtSpots->fetchAll(PDO::FETCH_ASSOC);

    // Préparation des coordonnées
    $spots = [];
    foreach ($spotsRaw as$s) {
        $coords = explode(',',$s['LOCALISATION']);
        if (count($coords) == 2) {$spots[] = [
                'id' => $s['ID_SPOT'],
                'nom' => $s['NOM_SPOT'],
                'lat' => trim($coords[0]),                 'lng' => trim($coords[1])
            ];
        }
    }

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Météo des Spots - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    
    <style>
        .top-bar-meteo {
            background: linear-gradient(135deg, #0dcaf0 0%, #0d6efd 100%);
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
            position: relative;
        }
        .btn-back {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            text-decoration: none;
        }
        .weather-main-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }
        .weather-main-card::after {
            content: 'cloud';
            font-family: 'Material Symbols Rounded';
            position: absolute;
            font-size: 150px;
            right: -20px;
            bottom: -30px;
            opacity: 0.1;
            pointer-events: none;
        }
        .forecast-card {
            background-color: #fff;
            border: 1px solid rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .forecast-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08) !important;
        }
        .select-location {
            background-color: #f8f9fa;
            border: 2px solid #e9ecef;
            font-weight: 600;
            color: #495057;
        }
        .select-location:focus {
            border-color: #0d6efd;
            box-shadow: none;
        }
        /* Style pour masquer la barre de défilement de la timeline horaire tout en permettant le scroll tactile */
        .timeline-scroll {
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none;  /* IE and Edge */
        }
        .timeline-scroll::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }
        /* Animation du chevron d'ouverture */
        .chevron-icon {
            transition: transform 0.3s ease;
        }
        .forecast-card[aria-expanded="true"] .chevron-icon {
            transform: rotate(180deg);
        }
    </style>
</head>
<body class="bg-light pb-5 mb-5">

    <header class="top-bar-meteo text-white text-center py-4 shadow-sm mb-4">
        <a href="accueil.php" class="btn-back">
            <span class="material-symbols-rounded fs-2">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center">
            <span class="material-symbols-rounded me-2">partly_cloudy_day</span> Météo
        </h1>
    </header>

    <main class="container">
        
        <!-- Sélection du Lieu -->
        <div class="mb-4">
            <label class="form-label text-secondary small fw-bold text-uppercase d-block mb-2">Choisir un emplacement</label>
            <select id="locationSelector" class="form-select form-select-lg rounded-pill select-location shadow-sm px-4">
                <option value="gps">📍 Ma position actuelle (GPS)</option>
                <?php if (!empty($spots)): ?>
                    <optgroup label="Mes Spots Enregistrés">
                        <?php foreach($spots as$spot): ?>
                            <option value="<?= $spot['id'] ?>" data-lat="<?= $spot['lat'] ?>" data-lng="<?= $spot['lng'] ?>">
                                🎣 <?= htmlspecialchars($spot['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>

        <!-- Spinner de chargement -->
        <div id="loader" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Chargement...</span>
            </div>
            <p class="text-muted mt-3 small">Récupération des données météo...</p>
        </div>

        <!-- Conteneur des données météo -->
        <div id="weatherContent" style="display: none;">
            
            <!-- MÉTÉO EN DIRECT -->
            <div class="card border-0 shadow-sm rounded-4 weather-main-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="fw-bold mb-0" id="current-city">Lieu actuel</h5>
                        <small class="text-white-50" id="current-time">Aujourd'hui</small>
                    </div>
                    <span class="material-symbols-rounded display-4" id="current-icon">clear_day</span>
                </div>
                
                <div class="mb-4">
                    <h1 class="display-2 fw-bold mb-0 d-inline-block" id="current-temp">--°</h1>
                    <span class="fs-5 align-top">C</span>
                    <p class="mb-0 fs-5" id="current-desc">Recherche...</p>
                </div>

                <div class="row g-2 border-top border-light border-opacity-25 pt-3">
                    <div class="col-6 d-flex align-items-center">
                        <span class="material-symbols-rounded text-info me-2 fs-4">air</span>
                        <div>
                            <small class="d-block text-white-50" style="font-size: 0.7rem;">Vent</small>
                            <span class="fw-semibold" id="current-wind">-- km/h</span>
                        </div>
                    </div>
                    <div class="col-6 d-flex align-items-center">
                        <span class="material-symbols-rounded text-warning me-2 fs-4">compress</span>
                        <div>
                            <small class="d-block text-white-50" style="font-size: 0.7rem;">Pression</small>
                            <span class="fw-semibold" id="current-press">-- hPa</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PRÉVISIONS SUR 7 JOURS -->
            <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-2 d-flex align-items-center">
                <span class="material-symbols-rounded me-2">calendar_month</span> Prévisions détaillées (7j)
            </h6>
            <p class="text-muted small mb-3">Appuyez sur un jour pour voir l'évolution de la journée.</p>
            
            <!-- Accordéon des jours -->
            <div id="forecast-container" class="accordion d-flex flex-column gap-2 mb-4">
                <!-- Les jours seront injectés ici par JS -->
            </div>

        </div>

    </main>

    <?php include './includes/navbar.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selector = document.getElementById('locationSelector');
            const loader = document.getElementById('loader');
            const content = document.getElementById('weatherContent');
            const forecastContainer = document.getElementById('forecast-container');

            function getWeatherDetails(code) {
                if(code === 0) return { text: 'Soleil', icon: 'clear_day', color: 'text-warning' };
                if(code > 0 && code <= 3) return { text: 'Nuageux', icon: 'partly_cloudy_day', color: 'text-secondary' };
                if(code === 45 || code === 48) return { text: 'Brouillard', icon: 'foggy', color: 'text-secondary' };
                if(code >= 51 && code <= 67) return { text: 'Pluie', icon: 'rainy', color: 'text-info' };
                if(code >= 71 && code <= 77) return { text: 'Neige', icon: 'weather_snowy', color: 'text-info' };
                if(code >= 80 && code <= 82) return { text: 'Averses', icon: 'rainy', color: 'text-primary' };
                if(code >= 95) return { text: 'Orage', icon: 'thunderstorm', color: 'text-danger' };
                return { text: 'Inconnu', icon: 'cloud', color: 'text-muted' };
            }

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

            function getDayName(dateString, index) {
                if (index === 0) return "Aujourd'hui";
                if (index === 1) return "Demain";
                const date = new Date(dateString);
                return date.toLocaleDateString('fr-FR', { weekday: 'long' }).charAt(0).toUpperCase() + date.toLocaleDateString('fr-FR', { weekday: 'long' }).slice(1);
            }

            function fetchWeather(lat, lng, locationName) {
                loader.style.display = 'block';
                content.style.display = 'none';
                forecastContainer.innerHTML = '';

                const url = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,surface_pressure,wind_speed_10m,wind_direction_10m,weathercode&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,wind_direction_10m_dominant&hourly=temperature_2m,precipitation,wind_speed_10m,wind_direction_10m,weathercode&timezone=Europe%2FParis`;

                fetch(url)
                .then(response => response.json())
                .then(data => {
                    if(data.current && data.daily && data.hourly) {
                        
                        // 1. MISE À JOUR DU DIRECT
                        const currentDetails = getWeatherDetails(data.current.weathercode);
                        const currentWindDir = getWindDirectionStr(data.current.wind_direction_10m);
                        
                        document.getElementById('current-city').innerText = locationName;
                        document.getElementById('current-temp').innerText = Math.round(data.current.temperature_2m);
                        document.getElementById('current-desc').innerText = currentDetails.text;
                        document.getElementById('current-icon').innerText = currentDetails.icon;
                        document.getElementById('current-press').innerText = Math.round(data.current.surface_pressure) + ' hPa';
                        document.getElementById('current-wind').innerText = Math.round(data.current.wind_speed_10m) + ' km/h ' + (currentWindDir ? `(${currentWindDir})` : '');

                        let hTime = data.hourly.time;
                        let hTemp = data.hourly.temperature_2m;
                        let hPrecip = data.hourly.precipitation;
                        let hWind = data.hourly.wind_speed_10m;
                        let hCode = data.hourly.weathercode;

                        // 2. MISE À JOUR DES PRÉVISIONS (JOUR PAR JOUR)
                        for(let i = 0; i < data.daily.time.length; i++) {
                            let dayDate = data.daily.time[i];
                            let maxTemp = Math.round(data.daily.temperature_2m_max[i]);
                            let minTemp = Math.round(data.daily.temperature_2m_min[i]);
                            let precip = data.daily.precipitation_sum[i];
                            let windMax = Math.round(data.daily.wind_speed_10m_max[i]);
                            let windDir = getWindDirectionStr(data.daily.wind_direction_10m_dominant[i]);
                            let details = getWeatherDetails(data.daily.weathercode[i]);
                            let dayName = getDayName(dayDate, i);
                            
                            let collapseId = `collapseDay${i}`;

                            // --- Construction de la Timeline Horaire ---
                            // Ajout de w-100 pour prendre toute la largeur disponible
                            let hourlyHtml = `<div class="d-flex overflow-auto py-3 px-1 timeline-scroll w-100">`;
                            
                            for(let j = 0; j < hTime.length; j++) {
                                if(hTime[j].startsWith(dayDate)) {
                                    let hourStr = hTime[j].substring(11, 16);
                                    let hourInt = parseInt(hourStr.substring(0,2));
                                    
                                    // On affiche toutes les 2 heures pour plus de précision (0, 2, 4, 6... 22)
                                    if (hourInt % 2 === 0) {
                                        let hDetails = getWeatherDetails(hCode[j]);
                                        
                                        // flex-fill permet d'occuper l'espace disponible de manière équitable sur grand écran
                                        // min-width garantit que sur mobile, ça déclenchera un scroll au lieu de s'écraser
                                        hourlyHtml += `
                                        <div class="text-center d-flex flex-column align-items-center flex-fill" style="min-width: 60px;">
                                            <span class="text-muted fw-bold mb-1" style="font-size: 0.75rem;">${hourStr}</span>
                                            <span class="material-symbols-rounded ${hDetails.color} mb-1" style="font-size: 26px;">${hDetails.icon}</span>
                                            <span class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">${Math.round(hTemp[j])}°</span>
                                            <span class="text-primary d-flex align-items-center justify-content-center w-100" style="font-size: 0.7rem;">
                                                <span class="material-symbols-rounded" style="font-size: 12px; margin-right:2px;">water_drop</span>
                                                ${hPrecip[j] > 0 ? hPrecip[j]+'mm' : '0'}
                                            </span>
                                            <span class="text-secondary d-flex align-items-center justify-content-center w-100 mt-1" style="font-size: 0.7rem;">
                                                <span class="material-symbols-rounded" style="font-size: 12px; margin-right:2px;">air</span>
                                                ${Math.round(hWind[j])}
                                            </span>
                                        </div>`;
                                    }
                                }
                            }
                            hourlyHtml += `</div>`;
                            // -------------------------------------------------------------

                            let html = `
                            <div class="card rounded-4 border-0 shadow-sm forecast-card overflow-hidden" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="false" aria-controls="${collapseId}">
                                <div class="card-body p-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        
                                        <!-- Jour & Date -->
                                        <div style="width: 25%;">
                                            <span class="fw-bold text-dark d-block text-truncate" style="font-size: 0.9rem;">${dayName}</span>
                                            <small class="text-muted" style="font-size: 0.7rem;">${dayDate.split('-').reverse().join('/')}</small>
                                        </div>

                                        <!-- Icône & Pluie (Global) -->
                                        <div class="text-center d-flex flex-column align-items-center justify-content-center" style="width: 25%;">
                                            <span class="material-symbols-rounded fs-2 ${details.color}">${details.icon}</span>
                                            <small class="text-primary fw-bold mt-1" style="font-size: 0.7rem;">
                                                ${precip > 0 ? `<span class="material-symbols-rounded align-middle" style="font-size: 14px;">water_drop</span> ${precip}mm` : '<span class="text-muted">Sec</span>'}
                                            </small>
                                        </div>

                                        <!-- Températures & Vent (Global) -->
                                        <div class="text-end" style="width: 40%;">
                                            <div class="mb-1">
                                                <span class="fw-bold text-danger me-2">${maxTemp}°</span>
                                                <span class="fw-bold text-primary">${minTemp}°</span>
                                            </div>
                                            <small class="text-muted d-flex align-items-center justify-content-end" style="font-size: 0.75rem;">
                                                <span class="material-symbols-rounded me-1" style="font-size: 14px;">air</span> ${windMax} km/h ${windDir}
                                            </small>
                                        </div>

                                        <!-- Chevron -->
                                        <div class="text-end" style="width: 10%;">
                                            <span class="material-symbols-rounded text-muted chevron-icon">expand_more</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- CONTENU DÉTAILLÉ CACHÉ (COLLAPSE) -->
                                <div id="${collapseId}" class="collapse" data-bs-parent="#forecast-container">
                                    <div class="card-body bg-light border-top p-2">
                                        <h6 class="small fw-bold text-secondary text-center text-uppercase mt-2 mb-1" style="font-size: 0.65rem; letter-spacing: 1px;">Évolution de la journée</h6>
                                        ${hourlyHtml}
                                    </div>
                                </div>
                            </div>
                            `;
                            forecastContainer.insertAdjacentHTML('beforeend', html);
                        }

                        loader.style.display = 'none';
                        content.style.display = 'block';
                    }
                })
                .catch(error => {
                    loader.innerHTML = '<div class="alert alert-danger rounded-4 m-3">Erreur lors de la récupération de la météo. Vérifiez votre connexion.</div>';
                });
            }

            function loadCurrentGPS() {
                loader.style.display = 'block';
                content.style.display = 'none';
                
                if ("geolocation" in navigator) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        fetchWeather(position.coords.latitude, position.coords.longitude, "Ma Position (GPS)");
                    }, function(error) {
                        loader.innerHTML = '<div class="alert alert-warning rounded-4 m-3"><span class="material-symbols-rounded align-middle me-2">location_disabled</span> GPS désactivé ou refusé. Sélectionnez un spot enregistré.</div>';
                    });
                } else {
                    loader.innerHTML = '<div class="alert alert-danger rounded-4 m-3">GPS non supporté par ce navigateur.</div>';
                }
            }

            selector.addEventListener('change', function() {
                if (this.value === 'gps') {
                    loadCurrentGPS();
                } else {
                    const selectedOption = this.options[this.selectedIndex];
                    const lat = selectedOption.getAttribute('data-lat');
                    const lng = selectedOption.getAttribute('data-lng');
                    const nom = selectedOption.text.replace('🎣 ', '').trim();
                    fetchWeather(lat, lng, nom);
                }
            });

            loadCurrentGPS();
        });
    </script>
</body>
</html>