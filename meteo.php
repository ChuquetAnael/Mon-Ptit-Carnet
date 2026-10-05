<?php
require_once './includes/auth_bdd.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupération de tous les spots de l'utilisateur qui ont des coordonnées GPS
    $stmtSpots = $pdo->prepare("
        SELECT ID_SPOT, NOM_SPOT, LOCALISATION 
        FROM SPOT 
        WHERE ID_UTILISATEUR = :id_user AND LOCALISATION IS NOT NULL AND LOCALISATION != ''
        ORDER BY NOM_SPOT ASC
    ");
    $stmtSpots->execute(['id_user' => $_SESSION['user_id']]);
    $spotsRaw = $stmtSpots->fetchAll(PDO::FETCH_ASSOC);

    // Préparation des coordonnées
    $spots = [];
    foreach ($spotsRaw as $s) {
        $coords = explode(',', $s['LOCALISATION']);
        if (count($coords) == 2) {
            $spots[] = [
                'id' => $s['ID_SPOT'],
                'nom' => $s['NOM_SPOT'],
                'lat' => trim($coords[0]),
                'lng' => trim($coords[1])
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
    <title>Météo & Marées - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <link rel="stylesheet" href="css/meteo.css">

</head>
<body class="bg-light pb-5 mb-5">

    <header class="top-bar-meteo text-white text-center py-4 shadow-sm mb-4">
        <a href="accueil.php" class="btn-back">
            <span class="material-symbols-rounded fs-2">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center">
            <span class="material-symbols-rounded me-2">partly_cloudy_day</span> Météo & Marées
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
                        <?php foreach($spots as $spot): ?>
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
            <p class="text-muted mt-3 small">Récupération des conditions...</p>
        </div>

        <!-- Conteneur global (caché pendant le chargement) -->
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

            <!-- TOGGLE SWITCH (Météo 7J vs Marée) -->
            <div class="bg-white rounded-pill shadow-sm border p-1 mb-4 d-flex mx-auto segmented-control" style="max-width: 350px;">
                <input type="radio" class="btn-check" name="viewToggle" id="btn-meteo" value="meteo" autocomplete="off" checked>
                <label class="btn rounded-pill flex-fill fw-bold border-0 text-muted" for="btn-meteo">Météo (7J)</label>

                <input type="radio" class="btn-check" name="viewToggle" id="btn-maree" value="maree" autocomplete="off">
                <label class="btn rounded-pill flex-fill fw-bold border-0 text-muted" for="btn-maree">Marée (Jour)</label>
            </div>

            <!-- SECTION 1 : PRÉVISIONS 7 JOURS -->
            <div id="section-meteo">
                <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-2 d-flex align-items-center">
                    <span class="material-symbols-rounded me-2">calendar_month</span> Prévisions détaillées
                </h6>
                <div id="forecast-container" class="accordion d-flex flex-column gap-2 mb-4">
                    <!-- Les jours injectés par JS -->
                </div>
            </div>

            <!-- SECTION 2 : MARÉE DU JOUR -->
            <div id="section-maree" style="display: none;">
                <div id="tide-container">
                    <!-- Les données et graphiques de marée injectées par JS -->
                </div>
            </div>

        </div>

        <!-- Crédits APIs ultra discrets -->
        <div class="text-center mt-4">
            <small class="text-muted" style="font-size: 0.65rem;">
                Météo par <a href="https://open-meteo.com/" target="_blank" class="text-decoration-none text-muted fw-bold">Open-Meteo</a> • Marées par <a href="https://api-maree.fr/" target="_blank" class="text-decoration-none text-muted fw-bold">api-maree.fr</a> (Ifremer/SHOM)
            </small>
        </div>
    </main>

    <?php include './includes/navbar.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script/meteo.js"></script>
</body>
</html>