<?php
require_once './includes/auth_bdd.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];

// Profil affiché : le sien par défaut, ou celui d'un autre membre (vue publique)
$id_profil = id_ou_null($_GET['id'] ?? null) ?? $id_user;
$est_moi = ($id_profil === $id_user);

// 1. Informations de l'utilisateur (le mail n'est jamais affiché aux autres)
$stmt = $pdo->prepare("SELECT ID_UTILISATEUR, PSEUDO, DATE_CREATION, DESCRIPTION, PDP_CHEMIN, BANNIERE_CHEMIN FROM utilisateur WHERE ID_UTILISATEUR = :id");
$stmt->execute(['id' => $id_profil]);
$profil = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profil) {
    header('Location: profil.php');
    exit();
}

// 2. Sessions épinglées (visibles par tous les membres)
// Pour les autres : seules les photos non masquées par la modération sont proposées
$filtre_photo = $est_moi ? '' : "AND ph.STATUT_MODERATION = 'visible'";
$stmt_epinglees = $pdo->prepare("
    SELECT s.ID_SESSION, s.DATE_DEBUT, ts.NOM_TYPE_SESSION, ts.TYPE_SES_ICONE_CHEMIN,
           (SELECT COALESCE(SUM(p.QUANTITE), 0) FROM prise p WHERE p.ID_SESSION = s.ID_SESSION) AS nb_prises,
           (SELECT ph.CHEMIN FROM photo_prise ph JOIN prise p2 ON ph.ID_PRISE = p2.ID_PRISE
             WHERE p2.ID_SESSION = s.ID_SESSION $filtre_photo ORDER BY ph.ORDRE, ph.ID_PHOTO LIMIT 1) AS premiere_photo
    FROM session_p s
    LEFT JOIN type_session ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
    WHERE s.ID_UTILISATEUR = ? AND s.EPINGLEE = 1
    ORDER BY s.DATE_EPINGLE DESC
    LIMIT " . SESSIONS_EPINGLEES_MAX);
$stmt_epinglees->execute([$id_profil]);
$sessions_epinglees = $stmt_epinglees->fetchAll(PDO::FETCH_ASSOC);

$dernieres_sessions = [];
$records_personnels = [];
$total_pages = 0;
$page = 1;

if ($est_moi) {
    // 3. Pagination de MES sessions
    $limit = 10;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $limit;

    $stmt_count = $pdo->prepare("SELECT COUNT(ID_SESSION) FROM session_p WHERE ID_UTILISATEUR = :id");
    $stmt_count->execute(['id' => $id_user]);
    $total_pages = (int) ceil($stmt_count->fetchColumn() / $limit);

    $stmt_sessions = $pdo->prepare("
        SELECT 
            s.ID_SESSION, 
            s.DATE_DEBUT, 
            s.EPINGLEE,
            ts.NOM_TYPE_SESSION, 
            ts.TYPE_SES_ICONE_CHEMIN,
            sp.NOM_SPOT,
            (SELECT COALESCE(SUM(p.QUANTITE), 0) FROM prise p WHERE p.ID_SESSION = s.ID_SESSION) AS nb_prises,
            (SELECT ph.CHEMIN FROM photo_prise ph JOIN prise p2 ON ph.ID_PRISE = p2.ID_PRISE
              WHERE p2.ID_SESSION = s.ID_SESSION ORDER BY ph.ORDRE, ph.ID_PHOTO LIMIT 1) AS premiere_photo
        FROM session_p s
        LEFT JOIN type_session ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
        LEFT JOIN spot sp ON s.ID_SPOT = sp.ID_SPOT
        WHERE s.ID_UTILISATEUR = :id
        ORDER BY s.DATE_DEBUT DESC
        LIMIT :limit OFFSET :offset
    ");
    // bindValue obligatoire pour injecter des entiers propres dans LIMIT et OFFSET
    $stmt_sessions->bindValue(':id', $id_user, PDO::PARAM_INT);
    $stmt_sessions->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt_sessions->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt_sessions->execute();
    $dernieres_sessions = $stmt_sessions->fetchAll(PDO::FETCH_ASSOC);

    // 4. Records Personnels (PB)
    $stmt_pb = $pdo->prepare("
        SELECT e.NOM_COM, e.ICONE_CHEMIN, MAX(p.TAILLE_CM) AS record_taille
        FROM prise p
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        JOIN espece e ON p.ID_ESPECE = e.ID_ESPECE
        WHERE s.ID_UTILISATEUR = :id AND p.TAILLE_CM IS NOT NULL
        GROUP BY e.ID_ESPECE, e.NOM_COM, e.ICONE_CHEMIN
        ORDER BY record_taille DESC
    ");
    $stmt_pb->execute(['id' => $id_user]);
    $records_personnels = $stmt_pb->fetchAll(PDO::FETCH_ASSOC);
}

$url_retour = 'profil.php?id=' . $id_profil;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    <link href="css/profil.css" rel="stylesheet">

</head>
<body class="bg-light">

    <!-- BANNIÈRE RESPONSIVE -->
    <header class="profile-banner-responsive" <?php if(!empty($profil['BANNIERE_CHEMIN'])) echo 'style="background-image: url(\'' . e($profil['BANNIERE_CHEMIN']) . '\');"'; ?>>
        
        <?php if ($est_moi): ?>
            <!-- BOUTON PARAMÈTRES (Roue crantée) -->
            <a href="parametre.php" class="settings-btn" title="Paramètres">
                <span class="material-symbols-rounded">settings</span>
            </a>
        <?php else: ?>
            <a href="javascript:history.back()" class="settings-btn" style="left: 20px; right: auto;" title="Retour">
                <span class="material-symbols-rounded">arrow_back_ios_new</span>
            </a>
            <!-- Signalement de la photo de profil / bannière -->
            <div class="dropdown position-absolute" style="top: 20px; right: 20px; z-index: 10;">
                <button class="settings-btn position-static" type="button" data-bs-toggle="dropdown" aria-label="Signaler" title="Signaler une image">
                    <span class="material-symbols-rounded">flag</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end rounded-4 shadow border-0">
                    <?php if (!empty($profil['PDP_CHEMIN'])): ?>
                        <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#modalSignalement" data-type="photo_profil" data-cible="<?= (int) $id_profil ?>">Signaler la photo de profil</button></li>
                    <?php endif; ?>
                    <?php if (!empty($profil['BANNIERE_CHEMIN'])): ?>
                        <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#modalSignalement" data-type="banniere" data-cible="<?= (int) $id_profil ?>">Signaler la bannière</button></li>
                    <?php endif; ?>
                    <?php if (empty($profil['PDP_CHEMIN']) && empty($profil['BANNIERE_CHEMIN'])): ?>
                        <li><span class="dropdown-item-text small text-muted">Aucune image à signaler</span></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- PHOTO DE PROFIL -->
        <div class="avatar-wrapper">
            <?php if(!empty($profil['PDP_CHEMIN'])): ?>
                <img src="<?= e($profil['PDP_CHEMIN']) ?>" alt="Photo de profil" class="avatar-circle">
            <?php else: ?>
                <div class="avatar-circle">
                    <span class="material-symbols-rounded">person</span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container pb-5 mb-5 main-profile-content">
        
        <!-- GRILLE CENTRALE -->
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">
                
                <!-- INFOS UTILISATEUR & DESCRIPTION -->
                <div class="text-center mb-4">
                    <h1 class="h2 fw-bold text-dark mb-1"><?= e($profil['PSEUDO']) ?></h1>
                    <?php if (isset($_GET['signale'])): ?>
                        <div class="alert alert-success rounded-4 border-0 small mt-3">Merci, ton signalement a été transmis à la modération.</div>
                    <?php endif; ?>
                    
                    <?php if(!empty($profil['DESCRIPTION'])): ?>
                        <p class="text-secondary bg-white p-3 rounded-4 shadow-sm mt-3 mx-auto" style="font-size: 1rem; max-width: 600px;">
                            "<?= e($profil['DESCRIPTION']) ?>"
                        </p>
                    <?php endif; ?>

                    <?php if ($est_moi): ?>
                        <!-- BOUTON MODIFIER -->
                        <a href="modifier_profil.php" class="btn btn-outline-primary rounded-pill px-4 py-2 mt-3 fw-bold border-2 shadow-sm">
                            Modifier mon profil
                        </a>
                        <a href="profil.php?id=<?= $id_user ?>" class="btn btn-link text-secondary mt-3 small d-block mx-auto">Voir mon profil comme les autres membres</a>
                    <?php else: ?>
                        <p class="text-muted small mt-2 mb-0">Membre depuis le <?= e(date('d/m/Y', strtotime($profil['DATE_CREATION']))) ?></p>
                    <?php endif; ?>
                </div>

                <!-- SESSIONS ÉPINGLÉES (partie publique du profil) -->
                <?php if (!empty($sessions_epinglees) || !$est_moi): ?>
                    <h2 class="h6 fw-bold text-secondary text-uppercase mt-5 mb-3 d-flex align-items-center">
                        <span class="material-symbols-rounded text-warning me-2">push_pin</span> Sessions épinglées
                    </h2>
                    <?php if (empty($sessions_epinglees)): ?>
                        <p class="text-muted small">Ce pêcheur n'a encore épinglé aucune session.</p>
                    <?php else: ?>
                        <div class="row g-3">
                            <?php foreach ($sessions_epinglees as $sess): ?>
                                <div class="col-12 col-md-4">
                                    <a href="<?= $est_moi ? 'detail_session.php' : 'session_publique.php' ?>?id=<?= (int) $sess['ID_SESSION'] ?>" class="text-decoration-none text-dark d-block h-100 hover-card">
                                        <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden h-100">
                                            <?php $img = $sess['premiere_photo'] ?: $sess['TYPE_SES_ICONE_CHEMIN']; ?>
                                            <?php if ($img): ?>
                                                <img src="<?= e($img) ?>" class="card-img-top w-100" style="height: 150px; object-fit: cover;" alt="Session épinglée">
                                            <?php endif; ?>
                                            <div class="card-body p-3">
                                                <h6 class="fw-bold mb-1"><?= e($sess['NOM_TYPE_SESSION'] ?? 'Session') ?></h6>
                                                <small class="text-muted"><?= e(date('d/m/Y', strtotime($sess['DATE_DEBUT']))) ?> • <?= (int) $sess['nb_prises'] ?> prise(s)</small>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($est_moi): ?>
                <hr class="my-5 text-muted opacity-25">

                <!-- ONGLETS -->
                <ul class="nav nav-pills mb-4 nav-fill custom-tabs gap-2" id="profil-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill fw-bold py-2" id="sessions-tab" data-bs-toggle="pill" data-bs-target="#tab-sessions" type="button" role="tab">Dernière sortie</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill fw-bold py-2" id="pb-tab" data-bs-toggle="pill" data-bs-target="#tab-pb" type="button" role="tab">Mes PB</button>
                    </li>
                </ul>

                <div class="tab-content" id="profil-tabsContent">
                    
                    <!-- ================= CONTENU : DERNIÈRES SORTIES AVEC PAGINATION ================= -->
                    <div class="tab-pane fade show active" id="tab-sessions" role="tabpanel">
                        <?php if(empty($dernieres_sessions)): ?>
                            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white mt-4">
                                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">history</span>
                                <p class="text-muted mb-0">Vous n'avez pas encore enregistré de session.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-4 mt-2">
                                <?php foreach($dernieres_sessions as $sess): ?>
                                    <div class="col-12 col-md-6">
                                        <a href="detail_session.php?id=<?= (int) $sess['ID_SESSION'] ?>" class="text-decoration-none text-dark d-block h-100 hover-card">
                                            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden h-100 d-flex flex-column">
                                                
                                                <div class="position-relative">
                                                    <?php if(!empty($sess['premiere_photo'])): ?>
                                                        <img src="<?= e($sess['premiere_photo']) ?>" class="card-img-top w-100" style="height: 220px; object-fit: cover;" alt="Photo session">
                                                    <?php elseif(!empty($sess['TYPE_SES_ICONE_CHEMIN'])): ?>
                                                        <img src="<?= e($sess['TYPE_SES_ICONE_CHEMIN']) ?>" class="card-img-top w-100" style="height: 220px; object-fit: cover;" alt="Illustration session">
                                                    <?php else: ?>
                                                        <div class="bg-light d-flex align-items-center justify-content-center w-100" style="height: 220px;">
                                                            <span class="material-symbols-rounded text-muted" style="font-size: 60px;">no_photography</span>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <span class="badge bg-dark bg-opacity-75 text-white position-absolute top-0 end-0 m-3 px-3 py-2 rounded-pill shadow-sm fs-6">
                                                        <?= date('d/m/Y', strtotime($sess['DATE_DEBUT'])) ?>
                                                    </span>
                                                    <?php if ($sess['EPINGLEE']): ?>
                                                        <span class="badge bg-warning text-dark position-absolute top-0 start-0 m-3 rounded-pill shadow-sm"><span class="material-symbols-rounded align-middle" style="font-size:16px;">push_pin</span></span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="card-body p-4 d-flex flex-column justify-content-between">
                                                    <div>
                                                        <h5 class="fw-bold mb-1 text-dark">
                                                            <?= !empty($sess['NOM_SPOT']) ? e($sess['NOM_SPOT']) : 'Spot non précisé' ?>
                                                        </h5>
                                                        <p class="text-muted mb-3 d-flex align-items-center">
                                                            <span class="material-symbols-rounded me-2 text-primary" style="font-size: 20px;">water_drop</span>
                                                            <?= !empty($sess['NOM_TYPE_SESSION']) ? e($sess['NOM_TYPE_SESSION']) : 'Milieu non défini' ?>
                                                        </p>
                                                    </div>
                                                    <div class="text-end border-top pt-3 mt-2">
                                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill fs-6 fw-bold">
                                                            <?= (int) $sess['nb_prises'] ?> prise(s) <span class="material-symbols-rounded align-middle ms-1" style="font-size: 18px;">chevron_right</span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- PAGINATION DES SESSIONS -->
                            <?php if ($total_pages > 1): ?>
                                <nav aria-label="Pagination des sessions" class="mt-5">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                            <a class="page-link rounded-pill px-4 me-2 border-0 shadow-sm fw-medium" href="?page=<?= $page - 1 ?>#tab-sessions">Précédent</a>
                                        </li>
                                        <li class="page-item disabled">
                                            <span class="page-link border-0 bg-transparent text-dark fw-bold px-3"><?= $page ?> / <?= $total_pages ?></span>
                                        </li>
                                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                            <a class="page-link rounded-pill px-4 ms-2 border-0 shadow-sm fw-medium" href="?page=<?= $page + 1 ?>#tab-sessions">Suivant</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                        <?php endif; ?>
                    </div>

                    <!-- ================= CONTENU : MES PB (Records) ================= -->
                    <div class="tab-pane fade" id="tab-pb" role="tabpanel">
                        <?php if(empty($records_personnels)): ?>
                            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white mt-4">
                                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">emoji_events</span>
                                <p class="text-muted mb-0">Aucun record enregistré. Renseignez la taille lors de vos prises !</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mt-2">
                                <?php foreach($records_personnels as $rec): ?>
                                    <div class="col-12 col-md-6 col-lg-4">
                                        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center bg-white hover-card">
                                            <div class="bg-light rounded-3 p-2 me-3 d-flex justify-content-center align-items-center" style="width: 70px; height: 70px;">
                                                <?php if(!empty($rec['ICONE_CHEMIN'])): ?>
                                                    <img src="<?= e($rec['ICONE_CHEMIN']) ?>" alt="Icone" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                                <?php else: ?>
                                                    <span class="material-symbols-rounded text-secondary" style="font-size: 40px;">set_meal</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="fw-bold mb-1 text-dark fs-5">
                                                    <?= e($rec['NOM_COM']) ?> <span class="text-primary">: <?= e($rec['record_taille']) ?> cm</span>
                                                </h6>
                                            </div>
                                            <span class="material-symbols-rounded text-warning fs-3 opacity-50 ms-2">emoji_events</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
                <?php endif; /* fin de la partie privée */ ?>
            </div>
        </div>
    </main>

    <?php if (!$est_moi) include './includes/modal_signalement.php'; ?>

    <nav class="navbar fixed-bottom bg-white custom-navbar border-0 shadow-lg">
        <div class="container-fluid d-flex justify-content-around align-items-end px-2">
            <a href="accueil.php" class="nav-item d-flex flex-column align-items-center text-secondary text-decoration-none">
                <span class="material-symbols-rounded">home</span>
                <span class="menu-text" style="font-size: 0.75rem;">Accueil</span>
            </a>
            <a href="nouvelle_session.php" class="btn-add-catch">
                <span class="material-symbols-rounded text-white" style="font-size: 36px;">phishing</span>
            </a>
            <a href="profil.php" class="nav-item <?= $est_moi ? 'active text-primary' : 'text-secondary' ?> d-flex flex-column align-items-center text-decoration-none">
                <span class="material-symbols-rounded">person</span>
                <span class="menu-text" style="font-size: 0.75rem;">Profil</span>
            </a>
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Script pour restaurer le bon onglet après un changement de page de pagination -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if(window.location.hash) {
                var hash = window.location.hash;
                var tabBtn = document.querySelector('button[data-bs-target="' + hash + '"]');
                if(tabBtn) {
                    var tab = new bootstrap.Tab(tabBtn);
                    tab.show();
                }
            }
        });
    </script>
</body>
</html>