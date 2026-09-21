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

    // 1. Récupération des poissons avec leurs tags (Requête principale)
    $sql = "
        SELECT e.ID_ESPECE, e.NOM_COM, e.NOM_SCIEN, e.TAILLE_MAX, e.POIDS_MAX, e.ICONE_CHEMIN, 
               e.DESCRIPTION, e.HABITAT_INFO, e.REPRODUCTION,
               GROUP_CONCAT(DISTINCT r.NOM_REPARTITION SEPARATOR ',') AS REPARTITIONS,
               GROUP_CONCAT(DISTINCT h.NOM_HABIT SEPARATOR ',') AS HABITATS
        FROM ESPECE e
        LEFT JOIN SE_TROUVER st ON e.ID_ESPECE = st.ID_ESPECE
        LEFT JOIN REPARTITION_MONDE r ON st.ID_REPARTITION = r.ID_REPARTITION
        LEFT JOIN HABITER hb ON e.ID_ESPECE = hb.ID_ESPECE
        LEFT JOIN HABITAT h ON hb.ID_HABIT = h.ID_HABIT
        GROUP BY e.ID_ESPECE
        ORDER BY e.NOM_COM ASC
    ";
    $stmt = $pdo->query($sql);
    $especes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Récupération dynamique des filtres pour les menus déroulants
    $stmtHabitats = $pdo->query("SELECT DISTINCT NOM_HABIT FROM HABITAT ORDER BY NOM_HABIT ASC");
    $liste_habitats = $stmtHabitats->fetchAll(PDO::FETCH_COLUMN);

    $stmtRepartitions = $pdo->query("SELECT DISTINCT NOM_REPARTITION FROM REPARTITION_MONDE ORDER BY NOM_REPARTITION ASC");
    $liste_repartitions = $stmtRepartitions->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Codex - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body>

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="accueil.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Codex des Espèces</h1>
    </header>

    <main class="container mb-5 pb-5">
        
        <!-- BARRE DE RECHERCHE ET FILTRES -->
        <div class="bg-white p-3 rounded-4 shadow-sm mb-4">
            <div class="row g-2">
                <!-- Recherche par nom -->
                <div class="col-12">
                    <div class="input-group bg-light rounded-pill overflow-hidden border-0">
                        <span class="input-group-text bg-transparent border-0 pe-1">
                            <span class="material-symbols-rounded text-muted">search</span>
                        </span>
                        <input type="text" id="searchInput" class="form-control bg-transparent border-0 shadow-none" placeholder="Rechercher un poisson...">
                    </div>
                </div>
                
                <!-- Filtre Milieu -->
                <div class="col-6">
                    <select id="habitatFilter" class="form-select bg-light border-0 rounded-pill text-secondary" style="font-size: 0.85rem;">
                        <option value="">Tous les milieux</option>
                        <?php foreach($liste_habitats as $hab): ?>
                            <option value="<?= htmlspecialchars(strtolower($hab)) ?>"><?= htmlspecialchars($hab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Filtre Répartition -->
                <div class="col-6">
                    <select id="repartFilter" class="form-select bg-light border-0 rounded-pill text-secondary" style="font-size: 0.85rem;">
                        <option value="">Toutes les zones</option>
                        <?php foreach($liste_repartitions as $rep): ?>
                            <option value="<?= htmlspecialchars(strtolower($rep)) ?>"><?= htmlspecialchars($rep) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Message si aucun résultat -->
        <div id="no-results" class="text-center text-muted mt-5" style="display: none;">
            <span class="material-symbols-rounded mb-2" style="font-size: 48px;">sentiment_dissatisfied</span>
            <p>Aucune espèce ne correspond à vos critères.</p>
        </div>
        
        <!-- GRILLE DES POISSONS -->
        <div class="row g-3" id="fish-grid">
            <?php foreach ($especes as $espece): ?>
                <!-- On ajoute des attributs "data-*" pour stocker les informations invisibles utilisées par le JS -->
                <div class="col-6 col-md-4 col-lg-3 fish-card-container" 
                     data-nom="<?= htmlspecialchars(strtolower($espece['NOM_COM'])) ?>"
                     data-habitats="<?= htmlspecialchars(strtolower($espece['HABITATS'] ?? '')) ?>"
                     data-repartitions="<?= htmlspecialchars(strtolower($espece['REPARTITIONS'] ?? '')) ?>">
                    
                    <div class="card h-100 border-0 shadow-sm rounded-4 text-center p-3 d-flex flex-column align-items-center transition-hover" 
                         data-bs-toggle="modal" data-bs-target="#modalFish<?= $espece['ID_ESPECE'] ?>" style="cursor: pointer;">
                        
                        <div class="mb-3 d-flex align-items-center justify-content-center" style="height: 80px; width: 100%;">
                            <?php if (!empty($espece['ICONE_CHEMIN'])): ?>
                                <img src="<?= htmlspecialchars($espece['ICONE_CHEMIN']) ?>" alt="<?= htmlspecialchars($espece['NOM_COM']) ?>" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                            <?php else: ?>
                                <span class="material-symbols-rounded text-secondary" style="font-size: 40px;">set_meal</span>
                            <?php endif; ?>
                        </div>

                        <div class="mt-auto w-100">
                            <h3 class="h6 fw-bold text-dark mb-0 text-truncate"><?= htmlspecialchars($espece['NOM_COM']) ?></h3>
                            <small class="text-muted fst-italic text-truncate d-block" style="font-size: 0.65rem;"><?= htmlspecialchars($espece['NOM_SCIEN']) ?></small>
                        </div>
                    </div>
                </div>

                <!-- Modale Bootstrap -->
                <div class="modal fade" id="modalFish<?= $espece['ID_ESPECE'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content rounded-4 border-0 shadow">
                            <div class="modal-header border-0 pb-0">
                                <h5 class="modal-title fw-bold text-dark h4"><?= htmlspecialchars($espece['NOM_COM']) ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                            </div>
                            <div class="modal-body pt-1">
                                <div class="text-center mb-3 bg-light rounded-4 py-3">
                                    <?php if (!empty($espece['ICONE_CHEMIN'])): ?>
                                        <img src="<?= htmlspecialchars($espece['ICONE_CHEMIN']) ?>" alt="Icône" style="max-height: 120px; object-fit: contain;">
                                    <?php else: ?>
                                        <span class="material-symbols-rounded text-secondary" style="font-size: 60px;">set_meal</span>
                                    <?php endif; ?>
                                </div>
                                
                                <p class="text-muted fst-italic text-center mb-4 border-bottom pb-3"><?= htmlspecialchars($espece['NOM_SCIEN']) ?></p>

                                <?php if(!empty($espece['TAILLE_MAX']) || !empty($espece['POIDS_MAX'])): ?>
                                    <div class="d-flex justify-content-around text-center mb-4">
                                        <div>
                                            <span class="d-block text-muted small text-uppercase fw-semibold">Taille Max</span>
                                            <span class="text-primary fw-bold"><?= htmlspecialchars($espece['TAILLE_MAX']) ?> cm</span>
                                        </div>
                                        <div>
                                            <span class="d-block text-muted small text-uppercase fw-semibold">Poids Max</span>
                                            <span class="text-primary fw-bold"><?= htmlspecialchars($espece['POIDS_MAX']) ?> kg</span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <h6 class="fw-bold text-dark mt-3 d-flex align-items-center">
                                    <span class="material-symbols-rounded text-primary me-2">menu_book</span> Description
                                </h6>
                                <p class="small text-secondary text-justify"><?= nl2br(htmlspecialchars($espece['DESCRIPTION'] ?? 'Description non renseignée.')) ?></p>

                                <h6 class="fw-bold text-dark mt-4 d-flex align-items-center">
                                    <span class="material-symbols-rounded text-primary me-2">home_pin</span> Habitat
                                </h6>
                                <p class="small text-secondary text-justify"><?= nl2br(htmlspecialchars($espece['HABITAT_INFO'] ?? 'Habitat non renseigné.')) ?></p>

                                <h6 class="fw-bold text-dark mt-4 d-flex align-items-center">
                                    <span class="material-symbols-rounded text-primary me-2">favorite</span> Reproduction
                                </h6>
                                <p class="small text-secondary text-justify mb-4">
                                    <?= nl2br(htmlspecialchars($espece['REPRODUCTION'] ?? 'Reproduction non renseignée.')) ?>
                                </p>

                                <div class="border-top pt-4 mt-2">
                                    <span class="d-block text-muted small text-uppercase fw-semibold mb-3">Milieux & Répartition</span>
                                    
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <?php if(!empty($espece['HABITATS'])): ?>
                                            <?php foreach(explode(',', $espece['HABITATS']) as $tag): ?>
                                                <span class="badge rounded-pill fw-normal px-3 py-2" style="background-color: rgba(0, 180, 219, 0.15); color: #0083b0; border: 1px solid rgba(0, 180, 219, 0.3); font-size: 0.75rem;">
                                                    <?= htmlspecialchars(trim($tag)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if(!empty($espece['REPARTITIONS'])): ?>
                                            <?php foreach(explode(',', $espece['REPARTITIONS']) as $tag): ?>
                                                <span class="badge rounded-pill fw-normal px-3 py-2" style="background-color: rgba(30, 60, 114, 0.1); color: #1e3c72; border: 1px solid rgba(30, 60, 114, 0.2); font-size: 0.75rem;">
                                                    <span class="material-symbols-rounded align-middle" style="font-size: 14px; margin-right: 3px;">public</span><?= htmlspecialchars(trim($tag)) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <nav class="navbar fixed-bottom bg-white custom-navbar border-0">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- SCRIPT DE FILTRAGE DYNAMIQUE -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const habitatFilter = document.getElementById('habitatFilter');
            const repartFilter = document.getElementById('repartFilter');
            const cards = document.querySelectorAll('.fish-card-container');
            const noResults = document.getElementById('no-results');

            function filterCards() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                const habitatTerm = habitatFilter.value;
                const repartTerm = repartFilter.value;
                let visibleCount = 0;

                cards.forEach(card => {
                    // On récupère les attributs de la carte
                    const nom = card.getAttribute('data-nom');
                    const habitats = card.getAttribute('data-habitats');
                    const repartitions = card.getAttribute('data-repartitions');

                    // Vérification des 3 conditions
                    const matchSearch = nom.includes(searchTerm);
                    const matchHabitat = habitatTerm === "" || habitats.includes(habitatTerm);
                    const matchRepart = repartTerm === "" || repartitions.includes(repartTerm);

                    // Si toutes les conditions sont remplies, on affiche la carte
                    if (matchSearch && matchHabitat && matchRepart) {
                        card.style.display = "";
                        visibleCount++;
                    } else {
                        card.style.display = "none";
                    }
                });

                // Affichage du message si aucun poisson n'est trouvé
                noResults.style.display = (visibleCount === 0) ? "block" : "none";
            }

            // On déclenche le filtrage à chaque frappe de touche et à chaque changement de menu
            searchInput.addEventListener('input', filterCards);
            habitatFilter.addEventListener('change', filterCards);
            repartFilter.addEventListener('change', filterCards);
        });
    </script>
</body>
</html>