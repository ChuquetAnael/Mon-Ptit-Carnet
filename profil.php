<?php
require_once './includes/auth_bdd.php';
require_once './includes/profil.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];

// Profil affiché : le sien par défaut, ou celui d'un autre membre (vue publique).
// ?apercu=1 : l'auteur voit son profil exactement comme les autres membres.
$id_profil = id_ou_null($_GET['id'] ?? null) ?? $id_user;
$est_auteur = ($id_profil === $id_user);
$apercu = $est_auteur && isset($_GET['apercu']);
$vue_privee = $est_auteur && !$apercu;
$page = max(1, (int) ($_GET['page'] ?? 1));

// =========================================================================
// ACTIONS DE L'AUTEUR : masquer / afficher ses sessions (POST puis redirection)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $est_auteur) {
    $action = $_POST['action'] ?? '';
    $retour = 'ok';
    if ($action === 'visibilite' && ($id_session = id_ou_null($_POST['id_session'] ?? null))) {
        $masquer = ($_POST['masquer'] ?? '') === '1';
        changer_visibilite_session($pdo, $id_user, $id_session, $masquer);
        $retour = $masquer ? 'masquee' : 'visible';
    } elseif ($action === 'tout_masquer') {
        $pdo->prepare("UPDATE session_p SET MASQUEE = 1, EPINGLEE = 0, DATE_EPINGLE = NULL WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $retour = 'toutes_masquees';
    } elseif ($action === 'tout_afficher') {
        $pdo->prepare("UPDATE session_p SET MASQUEE = 0 WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        $retour = 'toutes_visibles';
    }
    header('Location: profil.php?page=' . $page . '&ok=' . $retour . '#sessions');
    exit();
}
$messages_ok = [
    'masquee' => "Session masquée : les autres membres ne la voient plus.",
    'visible' => "Session visible sur ton profil (sans le lieu ni le GPS).",
    'toutes_masquees' => "Toutes tes sessions sont masquées.",
    'toutes_visibles' => "Toutes tes sessions sont visibles sur ton profil (sans le lieu ni le GPS).",
];
$message_ok = $messages_ok[$_GET['ok'] ?? ''] ?? null;

// =========================================================================
// DONNÉES
// =========================================================================
$profil = profil_public($pdo, $id_profil);
if (!$profil) {
    header('Location: profil.php');
    exit();
}
$chiffres = chiffres_profil($pdo, $id_profil, $vue_privee);

$filtre_photo = $vue_privee ? '' : "AND ph.STATUT_MODERATION = 'visible'";
$colonnes_session = "
    s.ID_SESSION, s.DATE_DEBUT, s.EPINGLEE, s.MASQUEE, s.MILIEU, s.CODE_DEPARTEMENT, s.FACADE,
    ts.NOM_TYPE_SESSION, ts.TYPE_SES_ICONE_CHEMIN, " . ($vue_privee ? 'sp.NOM_SPOT' : 'NULL AS NOM_SPOT') . ",
    (SELECT COALESCE(SUM(p.QUANTITE), 0) FROM prise p WHERE p.ID_SESSION = s.ID_SESSION) AS nb_prises,
    (SELECT ph.CHEMIN FROM photo_prise ph JOIN prise p2 ON ph.ID_PRISE = p2.ID_PRISE
      WHERE p2.ID_SESSION = s.ID_SESSION $filtre_photo ORDER BY p2.DATE_HEURE, ph.ORDRE, ph.ID_PHOTO LIMIT 1) AS premiere_photo";

// 1. Sessions épinglées (en haut du profil)
$stmt = $pdo->prepare("
    SELECT $colonnes_session
    FROM session_p s
    LEFT JOIN type_session ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
    LEFT JOIN spot sp ON s.ID_SPOT = sp.ID_SPOT
    WHERE s.ID_UTILISATEUR = ? AND s.EPINGLEE = 1 AND s.MASQUEE = 0
    ORDER BY s.DATE_EPINGLE DESC
    LIMIT " . SESSIONS_EPINGLEES_MAX);
$stmt->execute([$id_profil]);
$sessions_epinglees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Toutes les sessions : l'auteur voit tout (avec l'interrupteur de visibilité), les autres les sessions visibles
$filtre_sessions = $vue_privee ? '' : 'AND s.MASQUEE = 0 AND s.EPINGLEE = 0';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM session_p s WHERE s.ID_UTILISATEUR = ? $filtre_sessions");
$stmt->execute([$id_profil]);
$nb_sessions_grille = (int) $stmt->fetchColumn();
$total_pages = max(1, (int) ceil($nb_sessions_grille / SESSIONS_PAR_PAGE_PROFIL));
$page = min($page, $total_pages);

$stmt = $pdo->prepare("
    SELECT $colonnes_session
    FROM session_p s
    LEFT JOIN type_session ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
    LEFT JOIN spot sp ON s.ID_SPOT = sp.ID_SPOT
    WHERE s.ID_UTILISATEUR = :id $filtre_sessions
    ORDER BY s.DATE_DEBUT DESC
    LIMIT :limite OFFSET :decalage");
$stmt->bindValue(':id', $id_profil, PDO::PARAM_INT);
$stmt->bindValue(':limite', SESSIONS_PAR_PAGE_PROFIL, PDO::PARAM_INT);
$stmt->bindValue(':decalage', ($page - 1) * SESSIONS_PAR_PAGE_PROFIL, PDO::PARAM_INT);
$stmt->execute();
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$nb_masquees = 0;
if ($vue_privee) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM session_p WHERE ID_UTILISATEUR = ? AND MASQUEE = 1");
    $stmt->execute([$id_user]);
    $nb_masquees = (int) $stmt->fetchColumn();
}

// 3. Records : la plus grande prise de chaque espèce (sessions visibles seulement pour les autres)
$stmt = $pdo->prepare("
    SELECT r.ID_PRISE, r.NOM_COM, r.ICONE_CHEMIN, r.TAILLE_CM, r.POIDS_KG, r.DATE_DEBUT FROM (
        SELECT p.ID_PRISE, e.NOM_COM, e.ICONE_CHEMIN, p.TAILLE_CM, p.POIDS_KG, s.DATE_DEBUT,
               ROW_NUMBER() OVER (PARTITION BY p.ID_ESPECE ORDER BY p.TAILLE_CM DESC, p.POIDS_KG DESC, s.DATE_DEBUT) AS rang
        FROM prise p
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        JOIN espece e ON p.ID_ESPECE = e.ID_ESPECE
        WHERE s.ID_UTILISATEUR = ? AND p.TAILLE_CM IS NOT NULL " . ($vue_privee ? '' : 'AND s.MASQUEE = 0') . "
    ) r WHERE r.rang = 1
    ORDER BY r.TAILLE_CM DESC");
$stmt->execute([$id_profil]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
$photos_records = [];
if ($records) {
    $ids = array_map('intval', array_column($records, 'ID_PRISE'));
    $stmt = $pdo->prepare("SELECT ID_PRISE, CHEMIN FROM photo_prise WHERE ID_PRISE IN (" . implode(',', array_fill(0, count($ids), '?')) . ") "
        . ($vue_privee ? '' : "AND STATUT_MODERATION = 'visible'") . " ORDER BY ORDRE, ID_PHOTO");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ph) $photos_records[(int) $ph['ID_PRISE']][] = $ph['CHEMIN'];
}

// 4. Photos pour la visionneuse (une liste par session)
$photos_sessions = photos_des_sessions($pdo, array_merge(array_column($sessions_epinglees, 'ID_SESSION'), array_column($sessions, 'ID_SESSION')), $vue_privee);

$annees = $profil['ANNEE_DEBUT'] ? max(0, (int) date('Y') - (int) $profil['ANNEE_DEBUT']) : null;
$url_retour = 'profil.php?id=' . $id_profil;

/** Carte compacte d'une session (grille 2 colonnes sur téléphone). */
function carte_session(array $s, bool $vue_privee, array $photos_sessions, int $page): void
{
    $lien = ($vue_privee ? 'detail_session.php' : 'session_publique.php') . '?id=' . (int) $s['ID_SESSION'];
    $img = $s['premiere_photo'] ?: icone_legere($s['TYPE_SES_ICONE_CHEMIN']);
    $photos = $photos_sessions[(int) $s['ID_SESSION']] ?? [];
    $lieu = $vue_privee ? ($s['NOM_SPOT'] ?: 'Spot non précisé') : (lieu_large($s['MILIEU'], $s['CODE_DEPARTEMENT'], $s['FACADE']) ?? 'Lieu privé');
    $nb = (int) $s['nb_prises'];
    ?>
    <article class="carte-session<?= $vue_privee && $s['MASQUEE'] ? ' est-masquee' : '' ?>">
        <a href="<?= e($lien) ?>" class="carte-session-lien">
            <div class="carte-session-image">
                <?php if ($img): ?>
                    <img src="<?= e($img) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="material-symbols-rounded" aria-hidden="true">no_photography</span>
                <?php endif; ?>
                <span class="carte-session-date"><?= e(date('d/m/y', strtotime($s['DATE_DEBUT']))) ?></span>
            </div>
            <div class="carte-session-corps">
                <strong><?= e($s['NOM_TYPE_SESSION'] ?? 'Session') ?></strong>
                <small><?= $nb ? $nb . ' poisson' . ($nb > 1 ? 's' : '') : 'Capot' ?></small>
                <small class="carte-session-lieu"><?= e($lieu) ?></small>
            </div>
        </a>
        <?php if ($s['EPINGLEE']): ?><span class="carte-session-epingle" title="Épinglée"><span class="material-symbols-rounded" aria-hidden="true">push_pin</span><span class="visually-hidden">Épinglée</span></span><?php endif; ?>
        <?php if ($photos): ?>
            <button type="button" class="carte-session-photos" data-visionneuse data-photos="<?= attr_visionneuse($photos) ?>" aria-label="Voir les <?= count($photos) ?> photo(s) en grand">
                <span class="material-symbols-rounded" aria-hidden="true">photo_library</span><?= count($photos) ?>
            </button>
        <?php endif; ?>
        <?php if ($vue_privee): ?>
            <form method="POST" action="profil.php?page=<?= (int) $page ?>" class="carte-session-visibilite">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="visibilite">
                <input type="hidden" name="id_session" value="<?= (int) $s['ID_SESSION'] ?>">
                <input type="hidden" name="masquer" value="<?= $s['MASQUEE'] ? '0' : '1' ?>">
                <button type="submit" class="<?= $s['MASQUEE'] ? 'masquee' : '' ?>" aria-pressed="<?= $s['MASQUEE'] ? 'false' : 'true' ?>"
                        title="<?= $s['MASQUEE'] ? 'Masquée : touche pour la rendre visible' : 'Visible : touche pour la masquer' ?>">
                    <span class="material-symbols-rounded" aria-hidden="true"><?= $s['MASQUEE'] ? 'visibility_off' : 'visibility' ?></span>
                    <span><?= $s['MASQUEE'] ? 'Masquée' : 'Visible' ?></span>
                </button>
            </form>
        <?php endif; ?>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $vue_privee ? 'Mon profil' : 'Profil de ' . e($profil['PSEUDO']) ?> - Mon P'tit Carnet</title>
    <?php include './includes/head.php'; ?>
    <link href="css/profil.css" rel="stylesheet">
</head>
<body class="bg-light">

    <header class="profile-banner-responsive" <?php if (!empty($profil['BANNIERE_CHEMIN'])) echo 'style="background-image: url(\'' . e($profil['BANNIERE_CHEMIN']) . '\');"'; ?>>
        <?php if ($vue_privee): ?>
            <a href="parametre.php" class="settings-btn" title="Paramètres" aria-label="Paramètres"><span class="material-symbols-rounded">settings</span></a>
        <?php else: ?>
            <a href="<?= $apercu ? 'profil.php' : 'javascript:history.back()' ?>" class="settings-btn" style="left: 20px; right: auto;" title="Retour" aria-label="Retour">
                <span class="material-symbols-rounded">arrow_back_ios_new</span>
            </a>
            <?php if (!$est_auteur): ?>
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
        <?php endif; ?>
        <div class="avatar-wrapper">
            <?php if (!empty($profil['PDP_CHEMIN'])): ?>
                <img src="<?= e($profil['PDP_CHEMIN']) ?>" alt="Photo de profil" class="avatar-circle">
            <?php else: ?>
                <div class="avatar-circle"><span class="material-symbols-rounded">person</span></div>
            <?php endif; ?>
        </div>
    </header>

    <main class="container pb-5 mb-5 main-profile-content">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">

                <?php if ($apercu): ?>
                    <div class="bandeau-apercu mb-3" role="status">
                        <span class="material-symbols-rounded" aria-hidden="true">visibility</span>
                        <span>Aperçu : voici ton profil tel que le voient les autres membres.</span>
                        <a href="profil.php" class="fw-bold">Revenir</a>
                    </div>
                <?php endif; ?>

                <!-- IDENTITÉ -->
                <section class="text-center mb-3">
                    <h1 class="h2 fw-bold mb-1"><?= e($profil['PSEUDO']) ?></h1>
                    <p class="text-muted small mb-0">
                        <?php if ($profil['NIVEAU_EXPERIENCE']): ?><span class="etiquette-niveau"><?= e(NIVEAUX_EXPERIENCE[$profil['NIVEAU_EXPERIENCE']] ?? '') ?></span><?php endif; ?>
                        Membre depuis <?= e(date('m/Y', strtotime($profil['DATE_CREATION']))) ?>
                    </p>
                    <?php if (isset($_GET['signale'])): ?>
                        <div class="alert alert-success rounded-4 border-0 small mt-3">Merci, ton signalement a été transmis à la modération.</div>
                    <?php endif; ?>
                    <?php if (!empty($profil['DESCRIPTION'])): ?>
                        <p class="profil-bio mt-3 mx-auto"><?= nl2br(e($profil['DESCRIPTION'])) ?></p>
                    <?php endif; ?>
                </section>

                <!-- CHIFFRES -->
                <ul class="profil-chiffres" aria-label="En chiffres">
                    <li><strong><?= (int) $chiffres['sessions'] ?></strong><span>session<?= $chiffres['sessions'] > 1 ? 's' : '' ?><?= $vue_privee ? '' : ' visibles' ?></span></li>
                    <li><strong><?= (int) $chiffres['poissons'] ?></strong><span>poisson<?= $chiffres['poissons'] > 1 ? 's' : '' ?></span></li>
                    <li><strong><?= (int) $chiffres['especes'] ?></strong><span>espèce<?= $chiffres['especes'] > 1 ? 's' : '' ?></span></li>
                    <li><strong><?= $chiffres['nokill'] !== null ? (int) $chiffres['nokill'] . ' %' : '–' ?></strong><span>no-kill</span></li>
                </ul>

                <!-- FICHE DU PÊCHEUR -->
                <?php
                $a_fiche = $profil['ANNEE_DEBUT'] || $profil['CODE_DEPARTEMENT'] || $profil['MILIEU_PREFERE'] || $profil['ESPECE_FETICHE'] || $profil['techniques'] || $profil['types'];
                ?>
                <?php if ($a_fiche): ?>
                <section class="fiche-pecheur card border-0 shadow-sm rounded-4 p-3 mb-4" aria-label="Fiche du pêcheur">
                    <dl class="mb-0">
                        <?php if ($profil['ANNEE_DEBUT']): ?>
                            <div><dt><span class="material-symbols-rounded" aria-hidden="true">history</span>Pêche depuis</dt><dd><?= (int) $profil['ANNEE_DEBUT'] ?><?= $annees ? ' (' . $annees . ' an' . ($annees > 1 ? 's' : '') . ')' : '' ?></dd></div>
                        <?php endif; ?>
                        <?php if ($profil['CODE_DEPARTEMENT']): ?>
                            <div><dt><span class="material-symbols-rounded" aria-hidden="true">map</span>Région</dt><dd><?= e(nom_departement($profil['CODE_DEPARTEMENT'])) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($profil['MILIEU_PREFERE']): ?>
                            <div><dt><span class="material-symbols-rounded" aria-hidden="true">water</span>Milieu</dt><dd><?= e(MILIEUX_PREFERES[$profil['MILIEU_PREFERE']] ?? '') ?></dd></div>
                        <?php endif; ?>
                        <?php if ($profil['ESPECE_FETICHE']): ?>
                            <div><dt><span class="material-symbols-rounded" aria-hidden="true">favorite</span>Espèce de cœur</dt>
                                <dd class="d-flex align-items-center gap-2"><?php if ($profil['ICONE_FETICHE']): ?><img src="<?= e(icone_legere($profil['ICONE_FETICHE'])) ?>" alt="" width="32" height="32" style="object-fit:contain" loading="lazy"><?php endif; ?><?= e($profil['ESPECE_FETICHE']) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($profil['types']): ?>
                            <div class="fiche-large"><dt><span class="material-symbols-rounded" aria-hidden="true">phishing</span>Pêches pratiquées</dt>
                                <dd class="liste-etiquettes"><?php foreach ($profil['types'] as $t): ?><span><?= e($t['NOM_TYPE_SESSION']) ?></span><?php endforeach; ?></dd></div>
                        <?php endif; ?>
                        <?php if ($profil['techniques']): ?>
                            <div class="fiche-large"><dt><span class="material-symbols-rounded" aria-hidden="true">tune</span>Techniques favorites</dt>
                                <dd class="liste-etiquettes"><?php foreach ($profil['techniques'] as $t): ?><span><?= e($t['NOM_TECHNIQUE']) ?></span><?php endforeach; ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </section>
                <?php endif; ?>

                <?php if ($vue_privee): ?>
                    <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
                        <a href="modifier_profil.php" class="btn btn-outline-primary rounded-pill px-4 fw-bold border-2">Modifier mon profil</a>
                        <a href="profil.php?id=<?= $id_user ?>&amp;apercu=1" class="btn btn-light rounded-pill px-4">Voir comme les autres</a>
                    </div>
                    <?php if (!$a_fiche && empty($profil['DESCRIPTION'])): ?>
                        <p class="text-center small text-muted">Ajoute ton niveau, ta région, ton espèce de cœur et tes techniques pour un profil qui te ressemble.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($message_ok): ?>
                    <div class="alert alert-success rounded-4 border-0 small" role="status"><?= e($message_ok) ?></div>
                <?php endif; ?>

                <!-- SESSIONS ÉPINGLÉES -->
                <?php if ($sessions_epinglees): ?>
                    <h2 class="titre-section"><span class="material-symbols-rounded" aria-hidden="true">push_pin</span> Sessions épinglées</h2>
                    <div class="row g-2 g-md-3 mb-4">
                        <?php foreach ($sessions_epinglees as $s): ?>
                            <div class="col-6 col-md-4"><?php carte_session($s, $vue_privee, $photos_sessions, $page); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- ONGLETS : SESSIONS / RECORDS -->
                <ul class="nav nav-pills mb-3 nav-fill custom-tabs gap-2" id="profil-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill fw-bold py-2" id="sessions-tab" data-bs-toggle="pill" data-bs-target="#sessions" type="button" role="tab">
                            <?= $vue_privee ? 'Mes sessions' : 'Sessions' ?>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill fw-bold py-2" id="pb-tab" data-bs-toggle="pill" data-bs-target="#records" type="button" role="tab"><?= $vue_privee ? 'Mes records' : 'Records' ?></button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="sessions" role="tabpanel">
                        <?php if ($vue_privee): ?>
                            <div class="bandeau-visibilite mb-3">
                                <p class="mb-2 small">
                                    <span class="material-symbols-rounded align-middle" aria-hidden="true">visibility</span>
                                    Les autres membres voient tes sessions visibles (prises et photos), jamais le nom du spot ni le GPS.
                                    <?= $nb_masquees ? '<strong>' . $nb_masquees . ' masquée' . ($nb_masquees > 1 ? 's' : '') . '.</strong>' : '' ?>
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    <form method="POST" action="profil.php?page=<?= (int) $page ?>"><?= csrf_field() ?><input type="hidden" name="action" value="tout_masquer"><button class="btn btn-sm btn-light rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1" aria-hidden="true">visibility_off</span>Tout masquer</button></form>
                                    <form method="POST" action="profil.php?page=<?= (int) $page ?>"><?= csrf_field() ?><input type="hidden" name="action" value="tout_afficher"><button class="btn btn-sm btn-light rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1" aria-hidden="true">visibility</span>Tout afficher</button></form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!$sessions): ?>
                            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;" aria-hidden="true">history</span>
                                <p class="text-muted mb-0"><?= $vue_privee ? "Tu n'as pas encore enregistré de session." : ($sessions_epinglees ? "Pas d'autre session visible." : "Ce pêcheur n'a encore partagé aucune session.") ?></p>
                                <?php if ($vue_privee): ?><a href="nouvelle_session.php" class="btn btn-primary rounded-pill mt-3">Ajouter une session</a><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="row g-2 g-md-3 grille-sessions">
                                <?php foreach ($sessions as $s): ?>
                                    <div class="col-6 col-md-4"><?php carte_session($s, $vue_privee, $photos_sessions, $page); ?></div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($total_pages > 1):
                                $base = 'profil.php?' . ($est_auteur && !$apercu ? '' : 'id=' . $id_profil . '&' . ($apercu ? 'apercu=1&' : '')); ?>
                                <nav aria-label="Pages des sessions" class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link rounded-pill px-4 me-2 border-0 shadow-sm" href="<?= e($base . 'page=' . ($page - 1)) ?>#sessions">Précédent</a></li>
                                        <li class="page-item disabled"><span class="page-link border-0 bg-transparent fw-bold px-3"><?= $page ?> / <?= $total_pages ?></span></li>
                                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link rounded-pill px-4 ms-2 border-0 shadow-sm" href="<?= e($base . 'page=' . ($page + 1)) ?>#sessions">Suivant</a></li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="records" role="tabpanel">
                        <?php if (!$records): ?>
                            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;" aria-hidden="true">emoji_events</span>
                                <p class="text-muted mb-0">Aucun record pour l'instant : les records se construisent avec la taille des prises.</p>
                            </div>
                        <?php else: ?>
                            <div class="row g-2 g-md-3">
                                <?php foreach ($records as $r):
                                    $ph = array_map(fn($c) => ['src' => $c, 'titre' => titre_prise($r['NOM_COM'], $r['TAILLE_CM'], $r['POIDS_KG'])], $photos_records[(int) $r['ID_PRISE']] ?? []); ?>
                                    <div class="col-12 col-sm-6">
                                        <?php if ($ph): ?><button type="button" class="carte-record" data-visionneuse data-photos="<?= attr_visionneuse($ph) ?>"><?php else: ?><div class="carte-record"><?php endif; ?>
                                            <span class="carte-record-icone">
                                                <?php if ($ph): ?><img src="<?= e($ph[0]['src']) ?>" alt="" loading="lazy" class="photo"><?php elseif ($r['ICONE_CHEMIN']): ?><img src="<?= e(icone_legere($r['ICONE_CHEMIN'])) ?>" alt="" loading="lazy"><?php else: ?><span class="material-symbols-rounded" aria-hidden="true">set_meal</span><?php endif; ?>
                                            </span>
                                            <span class="flex-grow-1 text-start">
                                                <strong class="d-block"><?= e($r['NOM_COM']) ?></strong>
                                                <span class="carte-record-valeur"><?= (int) $r['TAILLE_CM'] ?> cm<?= $r['POIDS_KG'] !== null && (float) $r['POIDS_KG'] > 0 ? ' · ' . e(str_replace('.', ',', (float) $r['POIDS_KG'])) . ' kg' : '' ?></span>
                                                <small class="d-block text-muted"><?= e(date('d/m/Y', strtotime($r['DATE_DEBUT']))) ?><?= $ph ? ' · ' . count($ph) . ' photo' . (count($ph) > 1 ? 's' : '') : '' ?></small>
                                            </span>
                                            <span class="material-symbols-rounded text-warning" aria-hidden="true">emoji_events</span>
                                        <?= $ph ? '</button>' : '</div>' ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php if (!$est_auteur) include './includes/modal_signalement.php'; ?>
    <?php include './includes/navbar.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Retour sur le bon onglet (pagination, records)
        document.addEventListener('DOMContentLoaded', function () {
            const bouton = location.hash && document.querySelector('button[data-bs-target="' + location.hash + '"]');
            if (bouton) new bootstrap.Tab(bouton).show();
        });
    </script>
</body>
</html>
