<?php
require_once './includes/auth_bdd.php';

$id_user = (int) $_SESSION['user_id'];
$pdo = db();
require_once './BDD/BDD_stat.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

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
                    <h4 class="fw-bold text-dark mb-0"><?= (int) $total_poissons ?></h4>
                    <small class="text-muted" style="font-size: 0.7rem;">Prises</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-success fs-2 mb-1">map</span>
                    <h4 class="fw-bold text-dark mb-0"><?= (int) $total_sessions ?></h4>
                    <small class="text-muted" style="font-size: 0.7rem;">Sorties</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-warning fs-2 mb-1">emoji_events</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $record ? e($record['record_taille']) : '0' ?> <span class="fs-6">cm</span></h4>
                    <small class="text-muted text-truncate d-block" style="font-size: 0.7rem;"><?= $record ? e($record['NOM_COM']) : 'Aucun PB' ?></small>
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

        <!-- Efficacité (nouveau) -->
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-info fs-2 mb-1">speed</span>
                    <h4 class="fw-bold text-dark mb-0"><?= $prises_par_heure !== null ? e($prises_par_heure) : '--' ?></h4>
                    <small class="text-muted" style="font-size: 0.7rem;">Prises / heure</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-secondary fs-2 mb-1">timer</span>
                    <h4 class="fw-bold text-dark mb-0"><?= e($duree_moyenne) ?></h4>
                    <small class="text-muted" style="font-size: 0.7rem;">Durée moyenne</small>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-kpi shadow-sm bg-white">
                    <span class="material-symbols-rounded text-danger fs-2 mb-1">sentiment_dissatisfied</span>
                    <h4 class="fw-bold text-dark mb-0"><?= (int) $taux_capot ?>%</h4>
                    <small class="text-muted" style="font-size: 0.7rem;">Sessions capot</small>
                </div>
            </div>
        </div>

        <!-- Bilan par Espèce -->
        <h6 class="fw-bold text-secondary text-uppercase mb-1 mt-4">Bilan par espèce</h6>
        <p class="small text-muted mb-3">Touche une espèce pour voir ses moyennes, ton record, ton meilleur leurre et sa saison.</p>
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden">
            <ul class="list-group list-group-flush">
                <?php if(empty($liste_especes)): ?>
                    <li class="list-group-item p-4 text-center text-muted">Aucune prise enregistrée.</li>
                <?php else: ?>
                    <?php foreach($liste_especes as $esp): 
                        $pourcentage = round(($esp['nb'] / $total_poissons) * 100);
                    ?>
                    <li class="list-group-item p-0">
                        <button type="button" class="ligne-espece" data-espece="<?= (int) $esp['ID_ESPECE'] ?>" aria-haspopup="dialog">
                            <span class="d-flex align-items-center min-w-0">
                                <?php if(!empty($esp['ICONE_CHEMIN'])): ?>
                                    <img loading="lazy" src="<?= e(icone_legere($esp['ICONE_CHEMIN'])) ?>" alt="" style="width: 35px; height: 35px; object-fit: contain;" class="me-3">
                                <?php endif; ?>
                                <span class="text-start min-w-0">
                                    <strong class="d-block text-dark"><?= e($esp['NOM_COM']) ?></strong>
                                    <small class="text-muted"><span class="fw-bold text-primary"><?= (int) $esp['nb'] ?></span> prise(s) (<?= (int) $pourcentage ?> %)</small>
                                </span>
                            </span>
                            <span class="d-flex align-items-center gap-2">
                                <?php if(!empty($esp['pb_cm'])): ?>
                                    <span class="badge bg-warning bg-opacity-25 text-dark border border-warning rounded-pill">
                                        <span class="material-symbols-rounded align-middle fs-6 text-warning" aria-hidden="true">emoji_events</span> <?= e($esp['pb_cm']) ?> cm
                                    </span>
                                <?php endif; ?>
                                <span class="material-symbols-rounded text-muted" aria-hidden="true">chevron_right</span>
                            </span>
                        </button>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Graphique : Moment de la journée -->
        <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-4">Tes heures de pêche</h6>
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-3">
            <?php if($total_poissons > 0): ?>
                <?php if ($meilleure_heure): ?>
                    <p class="resume-heures mb-2">
                        <strong><?= (int) $meilleure_heure['heure'] ?> h – <?= ((int) $meilleure_heure['heure'] + 1) % 24 ?> h</strong> est ton créneau le plus rentable :
                        <?= e(str_replace('.', ',', (string) round($meilleure_heure['rendement'], 1))) ?> poisson(s) par heure de pêche
                        (<?= (int) $meilleure_heure['poissons'] ?> poisson(s) en <?= e(str_replace('.', ',', (string) round($meilleure_heure['heures'], 1))) ?> h passées à cette heure-là).
                    </p>
                <?php endif; ?>
                <?php if ($heure_plus_de_poissons !== null): ?>
                    <p class="small text-muted mb-2">C'est entre <?= (int) $heure_plus_de_poissons ?> h et <?= ($heure_plus_de_poissons + 1) % 24 ?> h que tu as pris le plus de poissons (<?= e(str_replace('.', ',', (string) $repartition_heures[$heure_plus_de_poissons])) ?> % du total).</p>
                <?php endif; ?>
                <div class="chart-container-sm">
                    <canvas id="heuresChart" aria-label="Part de tes poissons et rendement, heure par heure"></canvas>
                </div>
                <p class="small text-muted mt-2 mb-0">Barres : part de tes poissons pris à chaque heure. Courbe : poissons par heure passée au bord de l'eau à cette heure-là (affichée à partir d'1 h de pêche cumulée sur le créneau).</p>
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

        <!-- PRESSION & MARÉE (nouveau) -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <h6 class="fw-bold text-secondary text-uppercase mb-2">Pression & Marée (Ratio Prise/Session)</h6>
            </div>
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3 h-100">
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center" style="font-size: 0.9rem;">
                        <span class="material-symbols-rounded text-warning me-2 fs-5">compress</span> Pression atmosphérique
                    </h6>
                    <?php if (!empty($data_press)): ?>
                        <div class="chart-container-sm"><canvas id="pressChart"></canvas></div>
                    <?php else: ?>
                        <p class="text-muted text-center small my-4">Pas encore assez de données.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3 h-100">
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center" style="font-size: 0.9rem;">
                        <span class="material-symbols-rounded text-info me-2 fs-5">waves</span> Marée
                    </h6>
                    <?php if ($a_donnees_maree): ?>
                        <div class="chart-container-sm"><canvas id="mareeChart"></canvas></div>
                        <?php if (!empty($data_coef)): ?>
                            <ul class="list-group list-group-flush small mt-3">
                                <?php foreach ($data_coef as $c): ?>
                                    <li class="list-group-item d-flex justify-content-between px-0 py-1">
                                        <span><?= e($c['tranche']) ?></span><span class="fw-bold text-primary"><?= e(round((float) $c['ratio'], 2)) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted text-center small my-4">Active la marée lors de tes sessions en mer pour voir cette analyse.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Saisonnalité (nouveau) -->
        <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-4">Saisonnalité (prises/session par mois)</h6>
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-3">
            <?php if ($total_sessions > 0): ?>
                <div class="chart-container-sm"><canvas id="saisonChart"></canvas></div>
            <?php else: ?>
                <p class="text-muted text-center small my-4">Pas encore assez de données.</p>
            <?php endif; ?>
        </div>

        <!-- Par type de pêche (nouveau) -->
        <h6 class="fw-bold text-secondary text-uppercase mb-3 mt-4">Par type de pêche</h6>
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden">
            <ul class="list-group list-group-flush">
                <?php foreach ($stats_types as $t): ?>
                    <li class="list-group-item p-3 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="fw-bold text-dark d-block"><?= e($t['NOM_TYPE_SESSION']) ?></span>
                            <small class="text-muted"><?= (int) $t['nb_sessions'] ?> session(s)</small>
                        </div>
                        <span class="badge bg-primary rounded-pill"><?= e(round((float) $t['ratio'], 1)) ?> prises/session</span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($stats_types)): ?><li class="list-group-item p-3 text-muted text-center">Aucune donnée</li><?php endif; ?>
            </ul>
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
                                        <?= e($spot['NOM_SPOT']) ?>
                                    </span>
                                    <small class="text-muted"><?= (int) $spot['nb_prises'] ?> prises en <?= (int) $spot['nb_sessions'] ?> session(s)</small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-primary rounded-pill mb-1"><?= e(round((float) $spot['ratio'], 1)) ?> prises/session</span>
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
                                <span class="text-truncate me-2"><?= e($tech['NOM_TECHNIQUE']) ?></span>
                                <span class="fw-bold text-primary"><?= (int) $tech['nb'] ?></span>
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
                                <span class="text-truncate me-2"><?= e($leurre['NOM_LEURRE']) ?></span>
                                <span class="fw-bold text-primary"><?= (int) $leurre['nb'] ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if(empty($top_leurres)) echo "<li class='list-group-item py-2 small text-muted'>Aucune donnée</li>"; ?>
                    </ul>
                </div>
            </div>
        </div>

    </main>

    <!-- Détail d'une espèce -->
    <div class="modal fade" id="modal-espece" tabindex="-1" aria-labelledby="modal-espece-titre" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <h2 class="modal-title h5 fw-bold d-flex align-items-center gap-2" id="modal-espece-titre"></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body" id="modal-espece-corps"></div>
            </div>
        </div>
    </div>

    <?php include './includes/navbar.php'; ?>

    <script>
        const STATS_ESPECES = <?= js((object) $stats_especes) ?>;
        const ESPECES_BILAN = <?= js((object) array_column(array_map(fn($e) => ['id' => (int) $e['ID_ESPECE'], 'nom' => $e['NOM_COM'], 'icone' => icone_legere($e['ICONE_CHEMIN'])], $liste_especes), null, 'id')) ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script/stats_espece.js?v=4"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Chart.defaults.font.family = "'Atkinson Hyperlegible', sans-serif";
            
            <?php if($total_poissons > 0): ?>
            
            // 1. Heures : répartition des prises (barres) et rendement réel (courbe)
            const ctxHeures = document.getElementById('heuresChart').getContext('2d');
            new Chart(ctxHeures, {
                data: {
                    labels: <?= js($labels_heures) ?>,
                    datasets: [{
                        type: 'bar',
                        label: 'Part de tes poissons (%)',
                        data: <?= js($repartition_heures) ?>,
                        backgroundColor: 'rgba(31, 90, 102, 0.75)',
                        borderRadius: 4,
                        yAxisID: 'y',
                        order: 2
                    }, {
                        type: 'line',
                        label: 'Poissons par heure de pêche',
                        data: <?= js($rendement_heures) ?>,
                        borderColor: '#c4430c',
                        backgroundColor: '#c4430c',
                        borderWidth: 3,
                        tension: 0.35,
                        spanGaps: false,
                        pointRadius: 3,
                        yAxisID: 'y1',
                        order: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                        tooltip: { callbacks: { label: c => c.raw === null ? `${c.dataset.label} : moins d'1 h de pêche` : `${c.dataset.label} : ${String(c.raw).replace('.', ',')}` } }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 12 } },
                        y: { beginAtZero: true, position: 'left', title: { display: true, text: '% des poissons', font: { size: 10 } }, ticks: { font: { size: 10 } } },
                        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'poissons / heure', font: { size: 10 } }, ticks: { font: { size: 10 } } }
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
                    labels: <?= js($points_cardinaux) ?>,
                    datasets: [{
                        label: 'Prises / Session',
                        data: <?= js(array_values($data_vent_finale)) ?>,
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

            // Graphiques simples réutilisables (nouvelles stats)
            const barreSimple = (id, labels, data, couleur) => {
                const el = document.getElementById(id);
                if (!el) return;
                new Chart(el.getContext('2d'), {
                    type: 'bar',
                    data: { labels, datasets: [{ label: 'Prises / Session', data, backgroundColor: couleur, borderRadius: 6 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true } }
                    }
                });
            };
            barreSimple('pressChart', <?= $labels_press ?>, <?= $valeurs_press ?>, '#f59e0b');
            barreSimple('mareeChart', <?= js(array_keys($ratio_etat)) ?>, <?= js(array_values($ratio_etat)) ?>, '#0dcaf0');
            barreSimple('saisonChart', ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'], <?= js(array_values($valeurs_saison)) ?>, '#198754');

            <?php if($total_sessions > 0): ?>
            // 5. Graphique Effort vs Prises
            const ctxEffort = document.getElementById('effortChart').getContext('2d');
            new Chart(ctxEffort, {
                type: 'bar',
                data: {
                    labels: <?= js($labels_mois) ?>,
                    datasets: [
                        {
                            type: 'line',
                            label: 'Prises',
                            data: <?= js($valeurs_prises) ?>,
                            borderColor: '#0d6efd',
                            backgroundColor: '#0d6efd',
                            borderWidth: 3,
                            tension: 0.3,
                            yAxisID: 'yPrises'
                        },
                        {
                            type: 'bar',
                            label: 'Temps (Heures)',
                            data: <?= js($valeurs_heures_peche) ?>,
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