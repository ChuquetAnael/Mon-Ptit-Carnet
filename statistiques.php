<?php
require_once './includes/auth_bdd.php';

$id_user = $_SESSION['user_id'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    require_once'./BDD/BDD_stat.php';

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
    
    <?php include './includes/head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="stylesheet" href="./css/stat.css">
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

        <!-- Graphique : Moment de la journée -->
        <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-4">Activité moyenne par heure</h6>
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-3">
            <?php if($total_poissons > 0): ?>
                <div class="chart-container-sm">
                    <canvas id="heuresChart"></canvas>
                </div>
            <?php else: ?>
                <p class="text-muted text-center small my-4">Pas encore assez de données.</p>
            <?php endif; ?>
        </div>

        <!-- CONDITIONS OPTIMALES (MÉTÉO) -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <h6 class="fw-bold text-secondary text-uppercase mb-2">Impact de la Météo (Ratio Prise/Session)</h6>
            </div>
            
            <!-- Graphique Ciel -->
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3 h-100">
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center" style="font-size: 0.9rem;">
                        <span class="material-symbols-rounded text-primary me-2 fs-5">partly_cloudy_day</span> État du Ciel
                    </h6>
                    <div class="chart-container-sm">
                        <canvas id="cielChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Graphique Température -->
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3 h-100">
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center" style="font-size: 0.9rem;">
                        <span class="material-symbols-rounded text-danger me-2 fs-5">thermostat</span> Température Idéale
                    </h6>
                    <div class="chart-container-sm">
                        <canvas id="tempChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Graphique Boussole (Radar Vent) -->
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3">
                    <h6 class="fw-bold text-dark text-center mb-3 d-flex align-items-center justify-content-center" style="font-size: 0.9rem;">
                        <span class="material-symbols-rounded text-info me-2 fs-5">explore</span> Boussole des Vents
                    </h6>
                    <p class="text-center text-muted small mb-2">Ratio Prises/Session par direction</p>
                    <div class="chart-container-radar">
                        <canvas id="ventChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Effort de pêche mensuel -->
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

        <!-- Les Tops (Spots, Techniques, Leurres) -->
        <div class="row g-3 mb-4">
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

    <?php include './includes/navbar.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Chart.defaults.font.family = "'Poppins', sans-serif";
            
            <?php if($total_poissons > 0): ?>
            
            // 1. Graphique Activité par Heure (Boucle 0h -> 23h -> 0h)
            const ctxHeures = document.getElementById('heuresChart').getContext('2d');
            new Chart(ctxHeures, {
                type: 'line',
                data: {
                    labels: <?= json_encode($labels_heures) ?>,
                    datasets: [{
                        label: 'Prises / Session',
                        data: <?= json_encode(array_values($valeurs_heures_touches)) ?>,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.2)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4, 
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#f59e0b',
                        pointBorderWidth: 2,
                        pointRadius: function(context) {
                            return context.dataIndex === 0 || context.dataIndex === 24 ? 0 : 4; 
                        }
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { 
                            grid: { display: false }, 
                            ticks: { 
                                font: {size: 10},
                                maxTicksLimit: 12 
                            } 
                        },
                        y: { 
                            type: 'linear', display: true, beginAtZero: true,
                            title: { display: true, text: 'Prises / Session', font: {size: 10} },
                            ticks: { font: {size: 10} }
                        }
                    }
                }
            });

            // 2. Graphique État du Ciel (Barre VERTICALE avec Y en ordonnée)
            const ctxCiel = document.getElementById('cielChart').getContext('2d');
            new Chart(ctxCiel, {
                type: 'bar',
                data: {
                    labels: <?= $labels_ciel ?>,
                    datasets: [{
                        label: 'Prises / Session',
                        data: <?= $valeurs_ciel ?>,
                        backgroundColor: '#0dcaf0',
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: {size: 10} } },
                        y: { 
                            beginAtZero: true, 
                            title: { display: true, text: 'Prises / Session', font: {size: 10} }
                        }
                    }
                }
            });

            // 3. Graphique Température (Barre VERTICALE avec intervalles fixes)
            const ctxTemp = document.getElementById('tempChart').getContext('2d');
            new Chart(ctxTemp, {
                type: 'bar',
                data: {
                    labels: <?= $labels_temp_json ?>,
                    datasets: [{
                        label: 'Prises / Session',
                        data: <?= $valeurs_temp_json ?>,
                        backgroundColor: '#dc3545',
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { 
                            grid: { display: false }, 
                            ticks: { 
                                font: {size: 9},
                                maxRotation: 45,
                                minRotation: 45
                            } 
                        },
                        y: { 
                            beginAtZero: true, 
                            title: { display: true, text: 'Prises / Session', font: {size: 10} }
                        }
                    }
                }
            });

            // 4. Boussole des Vents (Radar)
            const ctxVent = document.getElementById('ventChart').getContext('2d');
            new Chart(ctxVent, {
                type: 'radar',
                data: {
                    labels: <?= json_encode($points_cardinaux) ?>,
                    datasets: [{
                        label: 'Prises / Session',
                        data: <?= json_encode(array_values($data_vent_finale)) ?>,
                        backgroundColor: 'rgba(13, 110, 253, 0.2)',
                        borderColor: '#0d6efd',
                        pointBackgroundColor: '#0d6efd',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#0d6efd',
                        borderWidth: 2,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        r: {
                            angleLines: { display: true },
                            suggestedMin: 0,
                            ticks: { display: false } 
                        }
                    }
                }
            });

            <?php endif; ?>

            <?php if($total_sessions > 0): ?>
            // 5. Graphique Effort vs Prises
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
                            data: <?= json_encode($valeurs_heures_peche) ?>,
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