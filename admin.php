<?php
session_start();
require_once './bdd/env.php';

// 1. VÉRIFICATION DES DROITS D'ADMINISTRATION
if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

$id_user = $_SESSION['user_id'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // L'utilisateur connecté est-il dans la table ADMIN ?
    $stmt_check = $pdo->prepare("SELECT 1 FROM ADMIN WHERE ID_UTILISATEUR = ?");
    $stmt_check->execute([$id_user]);
    if (!$stmt_check->fetch()) {
        header('Location: accueil.php');
        exit();
    }

    // =========================================================================
    // 2. RÉCUPÉRATION DES STATISTIQUES GLOBALES DU SITE
    // =========================================================================

    // Total des utilisateurs
    $nb_utilisateurs = $pdo->query("SELECT COUNT(*) FROM UTILISATEUR")->fetchColumn();

    // Total des sessions
    $nb_sessions = $pdo->query("SELECT COUNT(*) FROM SESSION_P")->fetchColumn();

    // Total des prises et taux de No-Kill global
    $prises_data = $pdo->query("
        SELECT 
            COUNT(ID_PRISE) as total_prises, 
            SUM(CASE WHEN RELACHE = 1 THEN 1 ELSE 0 END) as relachees 
        FROM PRISE
    ")->fetch(PDO::FETCH_ASSOC);
    
    $nb_prises = $prises_data['total_prises'] ?? 0;
    $taux_nokill = ($nb_prises > 0) ? round(($prises_data['relachees'] / $nb_prises) * 100) : 0;

    // Tickets en attente
    $nb_tickets = $pdo->query("SELECT COUNT(*) FROM TICKET WHERE STATUT = 'Ouvert'")->fetchColumn();

    // Les 5 derniers inscrits
    $derniers_inscrits = $pdo->query("
        SELECT PSEUDO, MAIL, DATE_CREATION, PDP_CHEMIN 
        FROM UTILISATEUR 
        ORDER BY DATE_CREATION DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // 3. DONNÉES POUR LE GRAPHIQUE (Croissance des utilisateurs - 6 derniers mois)
    // =========================================================================
    $stmtCroissance = $pdo->query("
        SELECT 
            DATE_FORMAT(DATE_CREATION, '%m') as mois_num, 
            DATE_FORMAT(DATE_CREATION, '%Y') as annee,
            COUNT(*) as nb_inscrits
        FROM UTILISATEUR
        GROUP BY annee, mois_num
        ORDER BY annee ASC, mois_num ASC
        LIMIT 6
    ");
    $data_croissance = $stmtCroissance->fetchAll(PDO::FETCH_ASSOC);
    
    $mois_fr = ['01'=>'Jan', '02'=>'Fév', '03'=>'Mar', '04'=>'Avr', '05'=>'Mai', '06'=>'Juin', '07'=>'Juil', '08'=>'Aoû', '09'=>'Sep', '10'=>'Oct', '11'=>'Nov', '12'=>'Déc'];
    $labels_mois = []; 
    $valeurs_inscrits = [];
    
    foreach($data_croissance as $m) {
        $labels_mois[] = $mois_fr[$m['mois_num']] . ' ' . substr($m['annee'], 2);
        $valeurs_inscrits[] = (int)$m['nb_inscrits'];
    }

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}

function formaterDate($date_str) {
    return date('d/m/Y', strtotime($date_str));
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Mon Carnet de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .top-bar-admin {
            background: linear-gradient(135deg, #dc3545 0%, #a8202e 100%);
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
            position: relative;
        }
        .stat-card {
            background-color: #f8f9fa;
            border-radius: 1rem;
            padding: 1.2rem;
            text-align: center;
            border: 1px solid #e9ecef;
            height: 100%;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .admin-nav-card {
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
        }
        .admin-nav-card:hover {
            transform: translateY(-2px);
            background-color: #f8f9fa !important;
        }
        .user-avatar-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="bg-light pb-5 mb-5">

    <!-- En-tête -->
    <header class="top-bar-admin text-white text-center py-4 shadow-sm mb-4">
        <a href="parametre.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded fs-2">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center">
            <span class="material-symbols-rounded me-2">admin_panel_settings</span> Dashboard
        </h1>
    </header>

    <main class="container">
        
        <!-- MODULE DE NAVIGATION ADMIN -->
        <div class="row g-3 mb-4">
            <div class="col-6">
                <a href="admin_compte.php" class="card border-0 shadow-sm rounded-4 bg-white admin-nav-card p-3 d-flex flex-column align-items-center justify-content-center h-100">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px;">
                        <span class="material-symbols-rounded fs-3">manage_accounts</span>
                    </div>
                    <span class="fw-bold text-dark text-center" style="font-size: 0.9rem;">Gérer les Comptes</span>
                </a>
            </div>
            <div class="col-6">
                <a href="admin_tickets.php" class="card border-0 shadow-sm rounded-4 bg-white admin-nav-card p-3 d-flex flex-column align-items-center justify-content-center h-100 position-relative">
                    <?php if($nb_tickets > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="margin-top: 10px; margin-left: -15px;">
                            <?= $nb_tickets ?>
                        </span>
                    <?php endif; ?>
                    <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px;">
                        <span class="material-symbols-rounded fs-3">support_agent</span>
                    </div>
                    <span class="fw-bold text-dark text-center" style="font-size: 0.9rem;">Gérer les Tickets</span>
                </a>
            </div>
        </div>

        <hr class="text-muted my-4">

        <h6 class="fw-bold text-secondary text-uppercase mb-3">Activité de l'Application</h6>

        <!-- KPIs GLOBAUX -->
        <div class="row g-2 mb-4">
            <div class="col-4">
                <div class="stat-card shadow-sm">
                    <span class="material-symbols-rounded text-primary fs-3 mb-1">group</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $nb_utilisateurs ?></h4>
                    <small class="text-muted text-uppercase" style="font-size: 0.65rem;">Membres</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-card shadow-sm">
                    <span class="material-symbols-rounded text-success fs-3 mb-1">map</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $nb_sessions ?></h4>
                    <small class="text-muted text-uppercase" style="font-size: 0.65rem;">Sessions</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-card shadow-sm">
                    <span class="material-symbols-rounded text-info fs-3 mb-1">phishing</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $nb_prises ?></h4>
                    <small class="text-muted text-uppercase" style="font-size: 0.65rem;">Prises</small>
                </div>
            </div>
        </div>

        <!-- Taux de No-Kill Global -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <h6 class="fw-bold mb-1"><span class="material-symbols-rounded text-success align-middle me-2">eco</span>No-Kill Global (Site)</h6>
                    <small class="text-muted" style="font-size: 0.8rem;">Proportion de poissons relâchés</small>
                </div>
                <div class="fs-4 fw-bold <?= $taux_nokill >= 50 ? 'text-success' : 'text-warning' ?>">
                    <?= $taux_nokill ?>%
                </div>
            </div>
            <div class="progress mx-3 mb-3" style="height: 8px;">
                <div class="progress-bar bg-success" style="width: <?= $taux_nokill ?>%"></div>
            </div>
        </div>

        <!-- Graphique : Croissance -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-3">
            <h6 class="fw-bold text-dark mb-3 d-flex align-items-center" style="font-size: 0.9rem;">
                <span class="material-symbols-rounded text-primary me-2 fs-5">trending_up</span> Nouvelles Inscriptions
            </h6>
            <?php if(count($labels_mois) > 0): ?>
                <div style="position: relative; height: 200px; width: 100%;">
                    <canvas id="croissanceChart"></canvas>
                </div>
            <?php else: ?>
                <p class="text-muted text-center small my-4">Pas encore assez de données.</p>
            <?php endif; ?>
        </div>

        <!-- Les 5 derniers inscrits -->
        <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-4">Derniers Pêcheurs Inscrits</h6>
        <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-4">
            <ul class="list-group list-group-flush">
                <?php if(empty($derniers_inscrits)): ?>
                    <li class="list-group-item p-4 text-center text-muted">Aucun inscrit pour le moment.</li>
                <?php else: ?>
                    <?php foreach($derniers_inscrits as $u): ?>
                    <li class="list-group-item p-3 d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <?php if(!empty($u['PDP_CHEMIN'])): ?>
                                <img src="<?= htmlspecialchars($u['PDP_CHEMIN']) ?>" class="user-avatar-sm me-3 border shadow-sm">
                            <?php else: ?>
                                <div class="user-avatar-sm me-3 text-muted border shadow-sm">
                                    <span class="material-symbols-rounded">person</span>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($u['PSEUDO']) ?></h6>
                                <small class="text-muted" style="font-size: 0.75rem;">
                                    <?= htmlspecialchars($u['MAIL']) ?>
                                </small>
                            </div>
                        </div>
                        <span class="badge bg-light text-secondary border rounded-pill" style="font-size: 0.7rem;">
                            <?= formaterDate($u['DATE_CREATION']) ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a href="admin_compte.php" class="list-group-item list-group-item-action text-center text-primary fw-bold p-3" style="font-size: 0.9rem;">
                    Voir tous les comptes <span class="material-symbols-rounded align-middle fs-5">arrow_forward</span>
                </a>
            </ul>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Chart.defaults.font.family = "'Poppins', sans-serif";
            
            <?php if(count($labels_mois) > 0): ?>
            const ctxCroissance = document.getElementById('croissanceChart').getContext('2d');
            new Chart(ctxCroissance, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($labels_mois) ?>,
                    datasets: [{
                        label: 'Nouveaux inscrits',
                        data: <?= json_encode($valeurs_inscrits) ?>,
                        backgroundColor: 'rgba(13, 110, 253, 0.8)',
                        borderRadius: 6,
                        barPercentage: 0.6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: {size: 10} } },
                        y: { 
                            type: 'linear', display: true, beginAtZero: true,
                            ticks: { precision: 0, font: {size: 10} },
                            grid: { borderDash: [2, 4] }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>