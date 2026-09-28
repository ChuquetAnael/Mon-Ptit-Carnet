<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';

$id_user = $_SESSION['user_id'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. KPI : Total des sessions
    $stmtSess = $pdo->prepare("SELECT COUNT(*) as total_sessions FROM SESSION_P WHERE ID_UTILISATEUR = :id_user");
    $stmtSess->execute(['id_user' => $id_user]);
    $total_sessions = $stmtSess->fetchColumn();

    // 2. KPI : Total des prises et No-Kill
    $stmtPrises = $pdo->prepare("
        SELECT 
            SUM(COALESCE(p.QUANTITE, 1)) as total_poissons,
            SUM(CASE WHEN p.RELACHE = 1 THEN COALESCE(p.QUANTITE, 1) ELSE 0 END) as relaches
        FROM PRISE p
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
    ");
    $stmtPrises->execute(['id_user' => $id_user]);
    $kpi_prises = $stmtPrises->fetch(PDO::FETCH_ASSOC);
    
    $total_poissons = $kpi_prises['total_poissons'] ?? 0;
    $taux_nokill = ($total_poissons > 0) ? round(($kpi_prises['relaches'] / $total_poissons) * 100) : 0;

    // 3. KPI : Plus gros poisson (Record Personnel / PB)
    $stmtRecord = $pdo->prepare("
        SELECT MAX(p.TAILLE_CM) as record_taille, e.NOM_COM 
        FROM PRISE p 
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY p.ID_ESPECE, e.NOM_COM
        ORDER BY record_taille DESC LIMIT 1
    ");
    $stmtRecord->execute(['id_user' => $id_user]);
    $record = $stmtRecord->fetch(PDO::FETCH_ASSOC);

    // 4. Liste détaillée des espèces
    $stmtEspeces = $pdo->prepare("
        SELECT e.NOM_COM, e.ICONE_CHEMIN, 
               SUM(COALESCE(p.QUANTITE, 1)) as nb,
               MAX(p.TAILLE_CM) as pb_cm
        FROM PRISE p 
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY e.ID_ESPECE, e.NOM_COM, e.ICONE_CHEMIN
        ORDER BY nb DESC
    ");
    $stmtEspeces->execute(['id_user' => $id_user]);
    $liste_especes = $stmtEspeces->fetchAll(PDO::FETCH_ASSOC);

    // 5. Statistiques par Spot (Classé par Ratio Prise/Session)
    // Utilisation d'une sous-requête pour éviter la duplication des sessions
    $stmtSpots = $pdo->prepare("
        SELECT sp.NOM_SPOT, 
               COUNT(s.ID_SESSION) as nb_sessions,
               SUM(COALESCE(sub.nb_poissons, 0)) as nb_prises,
               (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM SESSION_P s
        JOIN SPOT sp ON s.ID_SPOT = sp.ID_SPOT
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM PRISE
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
        GROUP BY sp.ID_SPOT, sp.NOM_SPOT
        ORDER BY ratio DESC LIMIT 5
    ");
    $stmtSpots->execute(['id_user' => $id_user]);
    $top_spots = $stmtSpots->fetchAll(PDO::FETCH_ASSOC);

    // 6. Top Techniques & Leurres
    $stmtTech = $pdo->prepare("
        SELECT t.NOM_TECHNIQUE, SUM(COALESCE(p.QUANTITE, 1)) as nb 
        FROM PRISE p 
        JOIN TECHNIQUE t ON p.ID_TECHNIQUE = t.ID_TECHNIQUE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY t.ID_TECHNIQUE, t.NOM_TECHNIQUE ORDER BY nb DESC LIMIT 3
    ");
    $stmtTech->execute(['id_user' => $id_user]);
    $top_techs = $stmtTech->fetchAll(PDO::FETCH_ASSOC);

    $stmtLeurre = $pdo->prepare("
        SELECT l.NOM_LEURRE, SUM(COALESCE(p.QUANTITE, 1)) as nb 
        FROM PRISE p 
        JOIN LEURRE l ON p.ID_LEURRE = l.ID_LEURRE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY l.ID_LEURRE, l.NOM_LEURRE ORDER BY nb DESC LIMIT 3
    ");
    $stmtLeurre->execute(['id_user' => $id_user]);
    $top_leurres = $stmtLeurre->fetchAll(PDO::FETCH_ASSOC);

    // 7. Graphique : Prises et Temps de pêche par mois (Correction du temps multiplié)
    $stmtMois = $pdo->prepare("
        SELECT 
            DATE_FORMAT(s.DATE_DEBUT, '%m') as mois_num, 
            DATE_FORMAT(s.DATE_DEBUT, '%Y') as annee,
            SUM(COALESCE(sub.nb_poissons, 0)) as nb_prises,
            SUM(TIMESTAMPDIFF(MINUTE, s.DATE_DEBUT, s.DATE_FIN)) as minutes_peche
        FROM SESSION_P s 
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM PRISE
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY annee, mois_num 
        ORDER BY annee ASC, mois_num ASC LIMIT 12
    ");
    $stmtMois->execute(['id_user' => $id_user]);
    $data_mois = $stmtMois->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatage pour Chart.js
    $mois_fr = ['01'=>'Jan', '02'=>'Fév', '03'=>'Mar', '04'=>'Avr', '05'=>'Mai', '06'=>'Juin', '07'=>'Juil', '08'=>'Aoû', '09'=>'Sep', '10'=>'Oct', '11'=>'Nov', '12'=>'Déc'];
    $labels_mois = []; $valeurs_prises = []; $valeurs_heures = [];
    
    foreach($data_mois as $m) {
        $labels_mois[] = $mois_fr[$m['mois_num']] . ' ' . substr($m['annee'], 2);
        $valeurs_prises[] = (int)$m['nb_prises'];
        $valeurs_heures[] = round(($m['minutes_peche'] ?? 0) / 60, 1); // Conversion propre en heures
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
    <title>Statistiques - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .stat-kpi {
            background-color: #f8f9fa;
            border-radius: 1rem;
            padding: 1.2rem;
            text-align: center;
            border: 1px solid #e9ecef;
            height: 100%;
        }
        .top-bar-stats {
            background: linear-gradient(135deg, #00c6ff 0%, #0072ff 100%);
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
        .list-group-item {
            border-color: rgba(0,0,0,0.05);
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
    </style>
</head>
<body class="bg-light pb-5 mb-5">

    <header class="top-bar-stats text-white text-center py-4 shadow-sm mb-4">
        <a href="accueil.php" class="btn-back">
            <span class="material-symbols-rounded fs-2">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center">
            <span class="material-symbols-rounded me-2">analytics</span> Mon Bilan
        </h1>
    </header>

    <main class="container">
        <!-- KPIs Rapides -->
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-primary fs-2 mb-1">phishing</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $total_poissons ?></h4>
                    <small class="text-muted" style="font-size: 0.7rem;">Prises</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-success fs-2 mb-1">map</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $total_sessions ?></h4>
                    <small class="text-muted" style="font-size: 0.7rem;">Sorties</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-warning fs-2 mb-1">emoji_events</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $record ? $record['record_taille'] : '0' ?> <span class="fs-6">cm</span></h4>
                    <small class="text-muted text-truncate d-block" style="font-size: 0.7rem;"><?= $record ? htmlspecialchars($record['NOM_COM']) : 'Aucun PB' ?></small>
                </div>
            </div>
        </div>

        <!-- Taux de No-Kill -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="fw-bold mb-1"><span class="material-symbols-rounded text-success align-middle me-2">eco</span>Taux de No-Kill</h6>
                </div>
                <div class="fs-4 fw-bold <?= $taux_nokill >= 50 ? 'text-success' : 'text-warning' ?>">
                    <?= $taux_nokill ?>%
                </div>
            </div>
            <div class="progress mx-3 mb-3" style="height: 8px;">
                <div class="progress-bar bg-success" style="width: <?= $taux_nokill ?>%"></div>
            </div>
        </div>

        <!-- Bilan par Espèce -->
        <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-4">Bilan par Espèce</h6>
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden">
            <ul class="list-group list-group-flush">
                <?php if(empty($liste_especes)): ?>
                    <li class="list-group-item p-4 text-center text-muted">Aucune prise enregistrée.</li>
                <?php else: ?>
                    <?php foreach($liste_especes as $esp): 
                        $pourcentage = round(($esp['nb'] / $total_poissons) * 100);
                    ?>
                    <li class="list-group-item p-3 d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <?php if(!empty($esp['ICONE_CHEMIN'])): ?>
                                <img src="<?= htmlspecialchars($esp['ICONE_CHEMIN']) ?>" style="width: 35px; height: 35px; object-fit: contain;" class="me-3">
                            <?php endif; ?>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($esp['NOM_COM']) ?></h6>
                                <small class="text-muted">
                                    <span class="fw-bold text-primary"><?= $esp['nb'] ?></span> prise(s) (<?= $pourcentage ?>%)
                                </small>
                            </div>
                        </div>
                        <?php if(!empty($esp['pb_cm'])): ?>
                            <div class="text-end">
                                <span class="badge bg-warning bg-opacity-25 text-dark border border-warning rounded-pill">
                                    <span class="material-symbols-rounded align-middle fs-6 text-warning">emoji_events</span> 
                                    <?= $esp['pb_cm'] ?> cm
                                </span>
                            </div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Graphique Mixte (Corrigé) -->
        <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-4">Effort de pêche mensuel</h6>
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-3">
            <?php if($total_sessions > 0): ?>
                <div class="chart-container">
                    <canvas id="effortChart"></canvas>
                </div>
            <?php else: ?>
                <p class="text-muted text-center small my-4">Pas encore assez de données.</p>
            <?php endif; ?>
        </div>

        <!-- Les Tops (Spots mis à jour, Techniques, Leurres) -->
        <div class="row g-3 mb-4">
            <!-- Top Spots : Classé par Ratio -->
            <div class="col-12">
                <h6 class="fw-bold text-secondary text-uppercase mb-2">Tes meilleurs Spots</h6>
                <div class="card border-0 shadow-sm rounded-4 bg-white">
                    <ul class="list-group list-group-flush">
                        <?php foreach($top_spots as $index => $spot): ?>
                            <li class="list-group-item p-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-dark fw-bold d-block">
                                        <span class="text-muted me-1">#<?= $index + 1 ?></span> 
                                        <?= htmlspecialchars($spot['NOM_SPOT']) ?>
                                    </span>
                                    <small class="text-muted"><?= $spot['nb_prises'] ?> prises en <?= $spot['nb_sessions'] ?> session(s)</small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-primary rounded-pill mb-1"><?= round($spot['ratio'], 1) ?> prises/session</span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if(empty($top_spots)) echo "<li class='list-group-item p-3 text-muted text-center'>Aucune donnée</li>"; ?>
                    </ul>
                </div>
            </div>

            <!-- Top Techniques -->
            <div class="col-6">
                <h6 class="fw-bold text-secondary text-uppercase mb-2" style="font-size: 0.8rem;">Top Techniques</h6>
                <div class="card border-0 shadow-sm rounded-4 bg-white h-100">
                    <ul class="list-group list-group-flush">
                        <?php foreach($top_techs as $tech): ?>
                            <li class="list-group-item px-3 py-2 small d-flex justify-content-between align-items-center">
                                <span class="text-truncate me-2"><?= htmlspecialchars($tech['NOM_TECHNIQUE']) ?></span>
                                <span class="fw-bold text-primary"><?= $tech['nb'] ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if(empty($top_techs)) echo "<li class='list-group-item py-2 small text-muted'>Aucune donnée</li>"; ?>
                    </ul>
                </div>
            </div>

            <!-- Top Leurres -->
            <div class="col-6">
                <h6 class="fw-bold text-secondary text-uppercase mb-2" style="font-size: 0.8rem;">Top Matériel</h6>
                <div class="card border-0 shadow-sm rounded-4 bg-white h-100">
                    <ul class="list-group list-group-flush">
                        <?php foreach($top_leurres as $leurre): ?>
                            <li class="list-group-item px-3 py-2 small d-flex justify-content-between align-items-center">
                                <span class="text-truncate me-2"><?= htmlspecialchars($leurre['NOM_LEURRE']) ?></span>
                                <span class="fw-bold text-primary"><?= $leurre['nb'] ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if(empty($top_leurres)) echo "<li class='list-group-item py-2 small text-muted'>Aucune donnée</li>"; ?>
                    </ul>
                </div>
            </div>
        </div>

    </main>

    <!-- Navbar standard -->
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

    <!-- Configuration Graphique Mixte -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Chart.defaults.font.family = "'Poppins', sans-serif";
            
            <?php if($total_sessions > 0): ?>
            const ctxEffort = document.getElementById('effortChart').getContext('2d');
            new Chart(ctxEffort, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($labels_mois) ?>,
                    datasets: [
                        {
                            type: 'line',
                            label: 'Prises',
                            data: <?= json_encode($valeurs_prises) ?>,
                            borderColor: '#0d6efd',
                            backgroundColor: '#0d6efd',
                            borderWidth: 3,
                            tension: 0.3,
                            yAxisID: 'yPrises'
                        },
                        {
                            type: 'bar',
                            label: 'Temps (Heures)',
                            data: <?= json_encode($valeurs_heures) ?>,
                            backgroundColor: 'rgba(25, 135, 84, 0.2)',
                            borderColor: '#198754',
                            borderWidth: 1,
                            borderRadius: 4,
                            yAxisID: 'yTemps'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        yPrises: { 
                            type: 'linear', display: true, position: 'left',
                            title: { display: true, text: 'Nbr Prises', font: {size: 10} },
                            ticks: { precision: 0 }
                        },
                        yTemps: { 
                            type: 'linear', display: true, position: 'right',
                            title: { display: true, text: 'Heures', font: {size: 10} },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>