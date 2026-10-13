<?php
require_once './includes/auth_bdd.php';

$id_session = id_ou_null($_GET['id'] ?? null);
if (!$id_session) {
    header('Location: profil.php');
    exit();
}
$id_user = (int) $_SESSION['user_id'];
$pdo = db();

// =========================================================================
// 0. LA SESSION DOIT APPARTENIR À L'UTILISATEUR (avant TOUTE action)
// =========================================================================
$stmtSess = $pdo->prepare("
    SELECT s.*, sp.NOM_SPOT, sp.LOCALISATION, ts.NOM_TYPE_SESSION
    FROM session_p s
    LEFT JOIN spot sp ON s.ID_SPOT = sp.ID_SPOT
    LEFT JOIN type_session ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
    WHERE s.ID_SESSION = :id_session AND s.ID_UTILISATEUR = :id_user
");
$stmtSess->execute(['id_session' => $id_session, 'id_user' => $id_user]);
$session = $stmtSess->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    header('Location: profil.php');
    exit();
}

// =========================================================================
// 1. ACTIONS (suppression session / prise, épinglage)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // A. Suppression de la Session complète (prises et photos comprises)
    if (isset($_POST['delete_session'])) {
        $stmtPhotos = $pdo->prepare("
            SELECT ph.CHEMIN FROM photo_prise ph JOIN prise p ON ph.ID_PRISE = p.ID_PRISE WHERE p.ID_SESSION = ?
        ");
        $stmtPhotos->execute([$id_session]);
        $photos = $stmtPhotos->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM prise WHERE ID_SESSION = ?")->execute([$id_session]); // photo_prise : ON DELETE CASCADE
        $pdo->prepare("DELETE FROM session_p WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?")->execute([$id_session, $id_user]);
        $pdo->commit();

        // Fichiers supprimés seulement une fois la base à jour
        foreach ($photos as $photo) {
            supprimer_fichier_utilisateur($photo, $id_user);
        }
        header('Location: profil.php');
        exit();
    }

    // B. Suppression d'une Prise individuelle (de CETTE session)
    if (isset($_POST['delete_prise'])) {
        $id_prise = id_ou_null($_POST['id_prise'] ?? null);
        $stmtCheck = $pdo->prepare("SELECT 1 FROM prise WHERE ID_PRISE = ? AND ID_SESSION = ?");
        $stmtCheck->execute([$id_prise, $id_session]);

        if ($stmtCheck->fetchColumn()) {
            $stmtPhotos = $pdo->prepare("SELECT CHEMIN FROM photo_prise WHERE ID_PRISE = ?");
            $stmtPhotos->execute([$id_prise]);
            $photos = $stmtPhotos->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare("DELETE FROM prise WHERE ID_PRISE = ?")->execute([$id_prise]);
            foreach ($photos as $photo) {
                supprimer_fichier_utilisateur($photo, $id_user);
            }
            $message_succes = "Prise supprimée avec succès.";
        }
    }

    // C. Épingler / désépingler la session sur le profil public
    if (isset($_POST['toggle_epingle'])) {
        if ($session['EPINGLEE']) {
            $pdo->prepare("UPDATE session_p SET EPINGLEE = 0, DATE_EPINGLE = NULL WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?")->execute([$id_session, $id_user]);
            $message_succes = "Session retirée de ton profil public.";
        } else {
            $stmtNb = $pdo->prepare("SELECT COUNT(*) FROM session_p WHERE ID_UTILISATEUR = ? AND EPINGLEE = 1");
            $stmtNb->execute([$id_user]);
            if ($stmtNb->fetchColumn() >= SESSIONS_EPINGLEES_MAX) {
                $message_erreur = "Tu as déjà " . SESSIONS_EPINGLEES_MAX . " sessions épinglées. Retires-en une d'abord.";
            } else {
                $pdo->prepare("UPDATE session_p SET EPINGLEE = 1, DATE_EPINGLE = NOW() WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?")->execute([$id_session, $id_user]);
                $message_succes = "Session épinglée sur ton profil : les autres membres voient ses prises et photos, jamais le lieu ni le GPS.";
            }
        }
        $session['EPINGLEE'] = $session['EPINGLEE'] ? 0 : (isset($message_erreur) ? 0 : 1);
    }
}

if (!empty($_GET['photos_refusees'])) {
    $message_erreur = (int) $_GET['photos_refusees'] . " photo(s) ignorée(s) : format non supporté, image trop lourde ou limite de " . PHOTOS_MAX_PAR_PRISE . " photos atteinte.";
}

// =========================================================================
// 2. RÉCUPÉRATION DES PRISES ET DE LEURS PHOTOS
// =========================================================================
$stmtPrises = $pdo->prepare("
    SELECT p.*, e.NOM_COM, e.ICONE_CHEMIN, l.NOM_LEURRE, a.NOM_APPAT, t.NOM_TECHNIQUE
    FROM prise p
    JOIN espece e ON p.ID_ESPECE = e.ID_ESPECE
    LEFT JOIN leurre l ON p.ID_LEURRE = l.ID_LEURRE
    LEFT JOIN appat a ON p.ID_APPAT = a.ID_APPAT
    LEFT JOIN technique t ON p.ID_TECHNIQUE = t.ID_TECHNIQUE
    WHERE p.ID_SESSION = :id_session
    ORDER BY p.DATE_HEURE ASC
");
$stmtPrises->execute(['id_session' => $id_session]);
$prises = $stmtPrises->fetchAll(PDO::FETCH_ASSOC);

$photos_par_prise = [];
$stmtPh = $pdo->prepare("
    SELECT ph.ID_PRISE, ph.CHEMIN, ph.STATUT_MODERATION FROM photo_prise ph
    JOIN prise p ON ph.ID_PRISE = p.ID_PRISE
    WHERE p.ID_SESSION = ? ORDER BY ph.ORDRE, ph.ID_PHOTO
");
$stmtPh->execute([$id_session]);
foreach ($stmtPh->fetchAll(PDO::FETCH_ASSOC) as $ph) {
    $photos_par_prise[$ph['ID_PRISE']][] = $ph;
}

// =========================================================================
// 3. MOTEUR DE STATISTIQUES PHP
// =========================================================================
$temps_de_peche = "Non précisé";
if (!empty($session['DATE_FIN'])) {
    $debut = new DateTime($session['DATE_DEBUT']);
    $fin = new DateTime($session['DATE_FIN']);
    if ($fin >= $debut) {
        $interval = $debut->diff($fin);
        $temps_de_peche = $interval->days > 0 ? $interval->format('%a j %h h %i m') : $interval->format('%h h %i min');
    }
}

$nb_prises = 0;
$plus_gros_cm = 0;
$meilleur_poisson = "Aucun";
$stats_materiel = [];

$lat_map = 46.603354;
$lng_map = 1.888334;
$zoom_map = 5;

foreach ($prises as $p) {
    $qte_actuelle = !empty($p['QUANTITE']) ? (int)$p['QUANTITE'] : 1;
    $nb_prises += $qte_actuelle;

    if (!empty($p['TAILLE_CM']) && $p['TAILLE_CM'] > $plus_gros_cm) {
        $plus_gros_cm = $p['TAILLE_CM'];
        $meilleur_poisson = $p['NOM_COM'] . " (" . $plus_gros_cm . " cm)";
    }

    $matos = !empty($p['NOM_LEURRE']) ? $p['NOM_LEURRE'] : (!empty($p['NOM_APPAT']) ? $p['NOM_APPAT'] : null);
    if ($matos) {
        if (!isset($stats_materiel[$matos])) $stats_materiel[$matos] = 0;
        $stats_materiel[$matos] += $qte_actuelle;
    }
}

$meilleur_materiel = "Non précisé";
if (!empty($stats_materiel)) {
    arsort($stats_materiel);
    $meilleur_materiel = array_key_first($stats_materiel);
}

// 0 est une valeur valide (0 °C, vent nul) : on teste NULL et non empty()
$meteo_temp = $session['TEMPERATURE'] !== null ? $session['TEMPERATURE'] : '--';
$meteo_press = $session['PRESSION_HPA'] !== null ? $session['PRESSION_HPA'] : '--';
$meteo_wind = $session['VITESSE_VENT'] !== null ? $session['VITESSE_VENT'] : '--';

if ($coords = lire_localisation($session['LOCALISATION'])) {
    [$lat_map, $lng_map] = $coords;
    $zoom_map = 14;
} elseif (count($prises) > 0 && !empty($prises[0]['LATITUDE']) && !empty($prises[0]['LONGITUDE'])) {
    $lat_map = (float) $prises[0]['LATITUDE'];
    $lng_map = (float) $prises[0]['LONGITUDE'];
    $zoom_map = 14;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail de Session - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <link href="css/profil.css" rel="stylesheet">
    <link rel="stylesheet" href="css/detail_session.css">
</head>
<body class="bg-light">

    <!-- BANNIÈRE RESPONSIVE -->
    <header class="profile-banner-responsive d-flex flex-column align-items-center justify-content-center position-relative" style="height: 180px;">
        
        <!-- Bouton Retour -->
        <a href="profil.php" class="settings-btn" style="left: 20px; right: auto;" title="Retour au profil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        
        <!-- Boutons d'édition et suppression de la session -->
        <div class="position-absolute d-flex gap-2" style="right: 20px; top: 20px; z-index: 10;">
            <form method="POST" class="m-0">
                <?= csrf_field() ?>
                <button type="submit" name="toggle_epingle" value="1" class="btn btn-sm <?= $session['EPINGLEE'] ? 'btn-warning' : 'btn-light text-warning' ?> rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" title="<?= $session['EPINGLEE'] ? 'Retirer de mon profil public' : 'Épingler sur mon profil public' ?>">
                    <span class="material-symbols-rounded" style="font-size: 20px;">push_pin</span>
                </button>
            </form>
            <a href="editer_session.php?id=<?= (int) $id_session ?>" class="btn btn-sm btn-light rounded-circle text-primary p-2 shadow-sm d-flex align-items-center justify-content-center" title="Modifier la session">
                <span class="material-symbols-rounded" style="font-size: 20px;">edit</span>
            </a>
            <form method="POST" class="m-0" onsubmit="return confirm('Es-tu sûr de vouloir supprimer cette session ? Toutes les prises et photos associées seront perdues.');">
                <?= csrf_field() ?>
                <button type="submit" name="delete_session" class="btn btn-sm btn-danger rounded-circle p-2 shadow-sm d-flex align-items-center justify-content-center" title="Supprimer la session">
                    <span class="material-symbols-rounded" style="font-size: 20px;">delete</span>
                </button>
            </form>
        </div>
        
        <h1 class="h3 fw-bold text-white mb-2 text-shadow text-center px-4">
            <?= !empty($session['NOM_SPOT']) ? e($session['NOM_SPOT']) : 'Spot non précisé' ?>
        </h1>
        <span class="badge bg-white text-primary rounded-pill px-3 py-2 shadow-sm">
            <span class="material-symbols-rounded align-middle fs-6 me-1">calendar_month</span>
            <?= date('d/m/Y à H:i', strtotime($session['DATE_DEBUT'])) ?>
        </span>
    </header>

    <main class="container pb-5 mb-5" style="margin-top: -20px; position: relative; z-index: 2;">
        
        <?php if(isset($message_succes)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm text-center mb-4" role="alert">
                <?= e($message_succes) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if(isset($message_erreur)): ?>
            <div class="alert alert-warning alert-dismissible fade show rounded-4 border-0 shadow-sm text-center mb-4" role="alert">
                <?= e($message_erreur) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($session['EPINGLEE']): ?>
            <div class="alert alert-warning bg-warning bg-opacity-10 border-0 rounded-4 small text-center mb-4">
                <span class="material-symbols-rounded align-middle fs-6 me-1">push_pin</span>
                Session épinglée sur <a href="profil.php?id=<?= $id_user ?>" class="fw-semibold">ton profil public</a> (lieu et GPS jamais affichés).
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-12 col-lg-10">

                <!-- TABLEAU DE BORD DES STATISTIQUES -->
                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                    <h5 class="fw-bold text-dark mb-4 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">query_stats</span> Bilan de la session
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
                                <h5 class="fw-bold text-dark mb-0 mt-2"><?= e($temps_de_peche) ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem;">Temps de pêche</small>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <span class="material-symbols-rounded stat-icon">emoji_events</span>
                                <h6 class="fw-bold text-dark mb-0 mt-2 text-truncate"><?= e($meilleur_poisson) ?></h6>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem;">Plus gros poisson</small>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="stat-card">
                                <span class="material-symbols-rounded stat-icon">set_meal</span>
                                <h6 class="fw-bold text-dark mb-0 mt-2 text-truncate"><?= e($meilleur_materiel) ?></h6>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.7rem;">Matériel fétiche</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARTE LEAFLET ET MÉTÉO -->
                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                    <h5 class="fw-bold text-dark mb-4 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">location_on</span> Position & Conditions Météo
                    </h5>
                    <div id="session-map" class="rounded-4 overflow-hidden shadow-sm border border-light-subtle mb-4" style="height: 250px; z-index: 1;"></div>
                    
                    <div class="row g-3">
                        <div class="col-4">
                            <div class="bg-light rounded-4 p-3 text-center border h-100 hover-card">
                                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">thermostat</span>
                                <h5 class="fw-bold text-dark mb-0"><?= e($meteo_temp) ?> <?= $meteo_temp !== '--' ? '°C' : '' ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">Température</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-light rounded-4 p-3 text-center border h-100 hover-card">
                                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">compress</span>
                                <h5 class="fw-bold text-dark mb-0"><?= e($meteo_press) ?> <?= $meteo_press !== '--' ? 'hPa' : '' ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">Pression</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-light rounded-4 p-3 text-center border h-100 hover-card">
                                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 32px;">air</span>
                                <h5 class="fw-bold text-dark mb-0"><?= e($meteo_wind) ?> <?= $meteo_wind !== '--' ? 'km/h' : '' ?></h5>
                                <small class="text-muted text-uppercase fw-semibold" style="font-size: 0.65rem;">Vent</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GALERIE DES PRISES -->
                <div class="d-flex justify-content-between align-items-center mb-4 mt-5">
                    <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                        <span class="material-symbols-rounded text-primary me-2">photo_library</span> Galerie des prises
                    </h5>
                    <a href="form_prise.php?action=add&id_session=<?= (int) $id_session ?>" class="btn btn-sm btn-primary rounded-pill fw-semibold px-3 shadow-sm d-flex align-items-center">
                        <span class="material-symbols-rounded fs-6 me-1">add</span> Ajouter
                    </a>
                </div>

                <?php if (empty($prises)): ?>
                    <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                        <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">sentiment_dissatisfied</span>
                        <p class="text-muted mb-0">Session capot ! Aucune prise n'a été enregistrée.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach($prises as $p): ?>
                            <div class="col-12 col-md-6">
                                <div class="card border-0 shadow-sm rounded-4 h-100 bg-white hover-card position-relative overflow-hidden">
                                    
                                    <!-- Boutons de modification et suppression de la prise -->
                                    <div class="position-absolute top-0 end-0 m-2 d-flex gap-2" style="z-index: 10;">
                                        <a href="form_prise.php?action=edit&id=<?= (int) $p['ID_PRISE'] ?>" class="btn btn-sm btn-light rounded-circle text-primary p-2 shadow-sm d-flex align-items-center justify-content-center" title="Modifier la prise">
                                            <span class="material-symbols-rounded" style="font-size: 18px;">edit</span>
                                        </a>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Es-tu sûr de vouloir supprimer cette prise et ses photos ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_prise" value="<?= (int) $p['ID_PRISE'] ?>">
                                            <button type="submit" name="delete_prise" class="btn btn-sm btn-light rounded-circle text-danger p-2 shadow-sm d-flex align-items-center justify-content-center" title="Supprimer la prise">
                                                <span class="material-symbols-rounded" style="font-size: 18px;">delete</span>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- GESTION DE L'IMAGE DE LA PRISE -->
                                    <?php $photos_p = $photos_par_prise[$p['ID_PRISE']] ?? []; ?>
                                    <?php if (!empty($photos_p)): ?>
                                        <?php if (count($photos_p) === 1): ?>
                                            <img src="<?= e($photos_p[0]['CHEMIN']) ?>" alt="Prise" class="catch-img">
                                        <?php else: ?>
                                            <div id="carousel-prise-<?= (int) $p['ID_PRISE'] ?>" class="carousel slide" data-bs-touch="true">
                                                <div class="carousel-inner">
                                                    <?php foreach ($photos_p as $k => $ph): ?>
                                                        <div class="carousel-item <?= $k === 0 ? 'active' : '' ?>">
                                                            <img src="<?= e($ph['CHEMIN']) ?>" alt="Photo <?= $k + 1 ?>" class="catch-img d-block w-100">
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <button class="carousel-control-prev" type="button" data-bs-target="#carousel-prise-<?= (int) $p['ID_PRISE'] ?>" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                                                <button class="carousel-control-next" type="button" data-bs-target="#carousel-prise-<?= (int) $p['ID_PRISE'] ?>" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
                                                <span class="badge bg-dark bg-opacity-75 position-absolute bottom-0 start-0 m-2"><?= count($photos_p) ?> photos</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (array_filter($photos_p, fn($ph) => $ph['STATUT_MODERATION'] !== 'visible')): ?>
                                            <div class="small text-danger px-4 pt-2"><span class="material-symbols-rounded align-middle fs-6">visibility_off</span> Une photo a été masquée par la modération.</div>
                                        <?php endif; ?>
                                    <?php elseif (!empty($p['ICONE_CHEMIN'])): ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center catch-img border-bottom p-4">
                                            <img src="<?= e($p['ICONE_CHEMIN']) ?>" alt="<?= e($p['NOM_COM']) ?>" style="width: 100%; height: 100%; object-fit: contain;">
                                        </div>
                                    <?php else: ?>
                                        <div class="bg-light d-flex align-items-center justify-content-center catch-img border-bottom">
                                            <span class="material-symbols-rounded text-muted" style="font-size: 60px;">no_photography</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-start mb-2 pe-5">
                                            <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                                                <?php if(!empty($p['ICONE_CHEMIN'])): ?>
                                                    <img src="<?= e($p['ICONE_CHEMIN']) ?>" alt="Icone" style="width: 25px; height: 25px; object-fit: contain;" class="me-2">
                                                <?php endif; ?>
                                                
                                                <?php 
                                                    $qte = !empty($p['QUANTITE']) ? (int)$p['QUANTITE'] : 1;
                                                    if ($qte > 1): 
                                                ?>
                                                    <span class="badge bg-primary text-white rounded-pill px-2 py-1 me-2" style="font-size: 0.8rem;">x<?= $qte ?></span>
                                                <?php endif; ?>

                                                <?= e($p['NOM_COM']) ?>
                                            </h5>
                                            <?php if ($p['RELACHE']): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success rounded-pill border border-success border-opacity-25 px-2 py-1">No-Kill</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="mb-3 text-muted small fw-medium">
                                            <?php if(!empty($p['TAILLE_CM'])) echo '<span class="me-3"><span class="material-symbols-rounded align-middle fs-6 me-1 text-primary">straighten</span>' . e($p['TAILLE_CM']) . ' cm</span>'; ?>
                                            <?php if(!empty($p['POIDS_KG'])) echo '<span><span class="material-symbols-rounded align-middle fs-6 me-1 text-primary">scale</span>' . e($p['POIDS_KG']) . ' kg</span>'; ?>
                                        </div>

                                        <ul class="list-group list-group-flush rounded-3 border">
                                            <?php if(!empty($p['NOM_LEURRE']) || !empty($p['NOM_APPAT'])): ?>
                                                <li class="list-group-item bg-transparent text-secondary small py-2 d-flex align-items-center">
                                                    <span class="material-symbols-rounded me-2 fs-6">phishing</span> 
                                                    <?= !empty($p['NOM_LEURRE']) ? e($p['NOM_LEURRE']) : e($p['NOM_APPAT']) ?>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($p['NOM_TECHNIQUE'])): ?>
                                                <li class="list-group-item bg-transparent text-secondary small py-2 d-flex align-items-center">
                                                    <span class="material-symbols-rounded me-2 fs-6">directions_run</span> 
                                                    <?= e($p['NOM_TECHNIQUE']) ?>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <li class="list-group-item bg-transparent text-muted small py-2 fst-italic d-flex align-items-center">
                                                <span class="material-symbols-rounded me-2 fs-6">schedule</span> 
                                                Pris à <?= $p['DATE_HEURE'] ? date('H:i', strtotime($p['DATE_HEURE'])) : '--:--' ?>
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

    <?php include './includes/navbar.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const lat = <?= js((float) $lat_map) ?>;
            const lng = <?= js((float) $lng_map) ?>;
            const zoom = <?= (int) $zoom_map ?>;

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