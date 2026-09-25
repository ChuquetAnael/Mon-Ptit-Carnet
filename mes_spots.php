<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. GESTION DE LA CRÉATION D'UN SPOT
    if (isset($_POST['add_spot']) && !empty($_POST['nom_spot']) && !empty($_POST['id_type_spot'])) {$nom = trim($_POST['nom_spot']);$type = $_POST['id_type_spot'];$lat = $_POST['lat'] ?? '';$lng = $_POST['lng'] ?? '';$localisation = ($lat &&$lng) ? $lat . ', ' .$lng : null;

        $stmt =$pdo->prepare("INSERT INTO SPOT (ID_UTILISATEUR, ID_TYPE_SPOT, NOM_SPOT, LOCALISATION) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $type,$nom, $localisation]);$message = "Spot ajouté avec succès !";
    }

    // 2. GESTION DE LA SUPPRESSION D'UN SPOT
    if (isset($_POST['delete_spot']) && !empty($_POST['id_spot'])) {
        // A. On délie le spot de toutes les sessions historiques
        $stmtUpdate = $pdo->prepare("UPDATE SESSION_P SET ID_SPOT = NULL WHERE ID_SPOT = ?");
        $stmtUpdate->execute([$_POST['id_spot']]);

        // B. On supprime définitivement le spot (en vérifiant l'appartenance à l'utilisateur)
        $stmt = $pdo->prepare("DELETE FROM SPOT WHERE ID_SPOT = ? AND ID_UTILISATEUR = ?");
        $stmt->execute([$_POST['id_spot'], $_SESSION['user_id']]);
        $message = "Spot supprimé. Les sessions qui y étaient liées affichent désormais 'Lieu non précisé'.";
    }

    // 3. RÉCUPÉRATION DES DONNÉES
    // Tous les spots de l'utilisateur
    $stmtSpots =$pdo->prepare("
        SELECT s.ID_SPOT, s.NOM_SPOT, s.LOCALISATION, t.NOM_TYPE_SPOT 
        FROM SPOT s
        LEFT JOIN TYPE_SPOT t ON s.ID_TYPE_SPOT = t.ID_TYPE_SPOT
        WHERE s.ID_UTILISATEUR = ?
        ORDER BY s.NOM_SPOT ASC
    ");
    $stmtSpots->execute([$_SESSION['user_id']]);
    $mes_spots =$stmtSpots->fetchAll(PDO::FETCH_ASSOC);

    // Tous les types de milieux (pour le menu déroulant d'ajout)
    $types_spot =$pdo->query("SELECT ID_TYPE_SPOT, NOM_TYPE_SPOT FROM TYPE_SPOT ORDER BY NOM_TYPE_SPOT")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Spots - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <!-- CSS Leaflet pour la carte -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="css/style.css" rel="stylesheet">
    <style>
        /* La carte prendra une belle portion de l'écran en haut */
        #map {
            height: 45vh;
            width: 100%;
            z-index: 1;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        /* Instruction flottante sur la carte */
        .map-instruction {
            position: absolute;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: rgba(255,255,255,0.9);
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.85rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            pointer-events: none; /* Pour pouvoir cliquer au travers */
            color: #2c3e50;
        }
    </style>
</head>
<body class="bg-light">

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-3 position-absolute w-100" style="background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%); z-index: 1000;">
        <a href="accueil.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h5 mb-0 fw-semibold d-flex align-items-center justify-content-center gap-2">
            <span class="material-symbols-rounded">location_on</span> Mes Spots
        </h1>
    </header>

    <!-- Instruction sur la carte -->
    <div class="map-instruction d-flex align-items-center gap-2">
        <span class="material-symbols-rounded text-success fs-5">touch_app</span> 
        Clique sur la carte pour ajouter
    </div>

    <!-- Container de la Carte -->
    <div id="map"></div>

    <main class="container mt-4 mb-5 pb-5">
        
        <?php if(isset($message)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm text-center" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h6 fw-bold text-dark mb-0">Mes lieux de pêche (<?= count($mes_spots) ?>)</h2>
        </div>

        <!-- Liste des Spots -->
        <?php if (empty($mes_spots)): ?>
            <div class="text-center text-muted mt-4">
                <span class="material-symbols-rounded mb-2" style="font-size: 48px; opacity: 0.5;">explore</span>
                <p>Tu n'as enregistré aucun spot.<br>Clique sur la carte pour commencer !</p>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach($mes_spots as$spot): ?>
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1 fw-bold text-dark"><?= htmlspecialchars($spot['NOM_SPOT']) ?></h6>
                                <span class="badge bg-success bg-opacity-10 text-success fw-medium">
                                    <?= htmlspecialchars($spot['NOM_TYPE_SPOT'] ?? 'Non précisé') ?>
                                </span>
                            </div>
                            <div class="d-flex gap-2">
                                <!-- Bouton pour centrer la carte sur ce spot -->
                                <?php if($spot['LOCALISATION']): ?>
                                    <?php $coords = explode(', ',$spot['LOCALISATION']); ?>
                                    <button class="btn btn-sm btn-light text-primary rounded-circle d-flex align-items-center justify-content-center p-2" onclick="focusMap(<?= $coords[0] ?>, <?=$coords[1] ?>)">
                                        <span class="material-symbols-rounded" style="font-size: 20px;">my_location</span>
                                    </button>
                                <?php endif; ?>
                                
                                <!-- Bouton Supprimer -->
                                <form method="POST" class="m-0" onsubmit="return confirm('Es-tu sûr de vouloir supprimer ce spot ?');">
                                    <input type="hidden" name="id_spot" value="<?= $spot['ID_SPOT'] ?>">
                                    <button type="submit" name="delete_spot" class="btn btn-sm btn-light text-danger rounded-circle d-flex align-items-center justify-content-center p-2">
                                        <span class="material-symbols-rounded" style="font-size: 20px;">delete</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- Modale Ajout de Spot -->
    <div class="modal fade" id="addSpotModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark">Nouveau Spot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="" method="POST" id="formAddSpot">
                        <input type="hidden" name="lat" id="modal_lat">
                        <input type="hidden" name="lng" id="modal_lng">
                        
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-medium">Nom du spot</label>
                            <input type="text" class="form-control bg-light border-0 p-3 rounded-3" name="nom_spot" placeholder="Ex: Pont de l'Europe, Lac secret..." required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-secondary small fw-medium">Type de milieu</label>
                            <select class="form-select bg-light border-0 p-3 rounded-3" name="id_type_spot" required>
                                <option value="" selected disabled>Choisir...</option>
                                <?php foreach($types_spot as$ts): ?>
                                    <option value="<?= $ts['ID_TYPE_SPOT'] ?>"><?= htmlspecialchars($ts['NOM_TYPE_SPOT']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="add_spot" class="btn btn-success p-3 rounded-pill fw-semibold shadow-sm">
                                <span class="material-symbols-rounded align-middle me-2">add_location_alt</span> Enregistrer ce spot
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Navbar de base -->
    <nav class="navbar fixed-bottom bg-white custom-navbar border-0 shadow-lg">
        <div class="container-fluid d-flex justify-content-around align-items-end px-2">
            <a href="accueil.php" class="nav-item d-flex flex-column align-items-center">
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

    <!-- Scripts Bootstrap & Leaflet -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // 1. Initialisation de la carte
            // Centre par défaut (Centre de la France)
            let defaultView = [46.603354, 1.888334];
            let defaultZoom = 6;
            
            let map = L.map('map', { zoomControl: false }).setView(defaultView, defaultZoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
                attribution: '© OpenStreetMap' 
            }).addTo(map);

            // Ajout du contrôle de zoom en bas à droite (pour ne pas gêner le header)
            L.control.zoom({ position: 'bottomright' }).addTo(map);

            // 2. Récupération des spots depuis le PHP pour afficher les marqueurs
            const spots = <?= json_encode($mes_spots) ?>;
            let markerGroup = L.featureGroup().addTo(map);

            spots.forEach(spot => {
                if(spot.LOCALISATION) {
                    let coords = spot.LOCALISATION.split(', ');
                    if(coords.length === 2) {
                        let lat = parseFloat(coords[0]);
                        let lng = parseFloat(coords[1]);
                        
                        // Création du marqueur
                        let marker = L.marker([lat, lng]).addTo(markerGroup);
                        
                        // Popup d'information au clic sur le marqueur
                        marker.bindPopup(`
                            <div class="text-center">
                                <strong class="d-block text-dark">${spot.NOM_SPOT}</strong>
                                <span class="small text-muted">${spot.NOM_TYPE_SPOT}</span>
                            </div>
                        `);
                    }
                }
            });

            // Si des spots existent, on centre la carte pour tous les afficher
            if (spots.length > 0) {
                map.fitBounds(markerGroup.getBounds(), { padding: [30, 30], maxZoom: 14 });
            }

            // 3. Logique d'ajout d'un spot au CLIC sur la carte
            let tempMarker = null;
            const addSpotModal = new bootstrap.Modal(document.getElementById('addSpotModal'));

            map.on('click', function(e) {
                // Supprime le marqueur temporaire précédent s'il existe
                if(tempMarker) { map.removeLayer(tempMarker); }
                
                // Ajoute un marqueur à l'endroit cliqué
                tempMarker = L.marker(e.latlng).addTo(map);
                
                // Rempli les champs cachés du formulaire avec les coordonnées
                document.getElementById('modal_lat').value = e.latlng.lat.toFixed(6);
                document.getElementById('modal_lng').value = e.latlng.lng.toFixed(6);
                
                // Ouvre la fenêtre modale Bootstrap
                addSpotModal.show();
            });

            // Si l'utilisateur ferme la modale sans valider, on efface le marqueur temporaire
            document.getElementById('addSpotModal').addEventListener('hidden.bs.modal', function () {
                if(tempMarker) { map.removeLayer(tempMarker); tempMarker = null; }
            });

            // 4. Fonction pour centrer la carte sur un spot depuis la liste (déclarée globalement)
            window.focusMap = function(lat, lng) {
                map.setView([lat, lng], 15); // Zoom fort (15) sur le spot
                window.scrollTo({ top: 0, behavior: 'smooth' }); // Remonte l'écran tout en haut
            };
        });
    </script>
</body>
</html>