<?php
// Vue publique (lecture seule) d'une session ÉPINGLÉE par un membre.
// Confidentialité (CGU) : ni nom du spot, ni carte, ni coordonnées GPS ne sont affichés.
require_once './includes/auth_bdd.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$id_session = id_ou_null($_GET['id'] ?? null);

$stmt = $pdo->prepare("
    SELECT s.ID_SESSION, s.ID_UTILISATEUR, s.DATE_DEBUT, s.DATE_FIN, s.TEMPERATURE, s.PRESSION_HPA, s.VITESSE_VENT,
           s.DIREC_VENT, s.DESCRIP_CIEL, s.COEFF_MAREE, s.DESC_MAREE, ts.NOM_TYPE_SESSION, u.PSEUDO, u.PDP_CHEMIN
    FROM session_p s
    JOIN utilisateur u ON s.ID_UTILISATEUR = u.ID_UTILISATEUR
    LEFT JOIN type_session ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
    WHERE s.ID_SESSION = ? AND s.EPINGLEE = 1
");
$stmt->execute([$id_session]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    app_page_message('Session introuvable', "Cette session n'existe pas ou n'est plus épinglée par son auteur.", 404);
}
$est_moi = ((int) $session['ID_UTILISATEUR'] === $id_user);

$stmtPrises = $pdo->prepare("
    SELECT p.ID_PRISE, p.TAILLE_CM, p.POIDS_KG, p.QUANTITE, p.RELACHE, p.DATE_HEURE,
           e.NOM_COM, e.ICONE_CHEMIN, l.NOM_LEURRE, a.NOM_APPAT, t.NOM_TECHNIQUE
    FROM prise p
    JOIN espece e ON p.ID_ESPECE = e.ID_ESPECE
    LEFT JOIN leurre l ON p.ID_LEURRE = l.ID_LEURRE
    LEFT JOIN appat a ON p.ID_APPAT = a.ID_APPAT
    LEFT JOIN technique t ON p.ID_TECHNIQUE = t.ID_TECHNIQUE
    WHERE p.ID_SESSION = ?
    ORDER BY p.DATE_HEURE
");
$stmtPrises->execute([$id_session]);
$prises = $stmtPrises->fetchAll(PDO::FETCH_ASSOC);

// Photos : les autres membres ne voient que les photos non masquées par la modération
$stmtPh = $pdo->prepare("
    SELECT ph.ID_PHOTO, ph.ID_PRISE, ph.CHEMIN, ph.STATUT_MODERATION FROM photo_prise ph
    JOIN prise p ON ph.ID_PRISE = p.ID_PRISE
    WHERE p.ID_SESSION = ? " . ($est_moi ? '' : "AND ph.STATUT_MODERATION = 'visible'") . "
    ORDER BY ph.ORDRE, ph.ID_PHOTO
");
$stmtPh->execute([$id_session]);
$photos_par_prise = [];
foreach ($stmtPh->fetchAll(PDO::FETCH_ASSOC) as $ph) {
    $photos_par_prise[$ph['ID_PRISE']][] = $ph;
}

$nb_poissons = array_sum(array_map(fn($p) => max(1, (int) $p['QUANTITE']), $prises));
$url_retour = 'session_publique.php?id=' . (int) $id_session;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session de <?= e($session['PSEUDO']) ?> - Mon P'tit Carnet</title>
    <?php include './includes/head.php'; ?>
</head>
<body class="bg-light">

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="profil.php?id=<?= (int) $session['ID_UTILISATEUR'] ?>" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;" aria-label="Retour au profil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h5 mb-1 fw-semibold"><?= e($session['NOM_TYPE_SESSION'] ?? 'Session de pêche') ?></h1>
        <small class="text-white-50"><?= e(date('d/m/Y', strtotime($session['DATE_DEBUT']))) ?> • par <?= e($session['PSEUDO']) ?></small>
    </header>

    <main class="container pb-5 mb-5" style="max-width: 900px;">

        <?php if (isset($_GET['signale'])): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm small text-center">Merci, ton signalement a été transmis à la modération.</div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
            <div class="d-flex flex-wrap gap-2 justify-content-center">
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2"><?= $nb_poissons ?> poisson(s)</span>
                <?php if ($session['DESCRIP_CIEL']): ?><span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= e($session['DESCRIP_CIEL']) ?></span><?php endif; ?>
                <?php if ($session['TEMPERATURE'] !== null): ?><span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= e($session['TEMPERATURE']) ?> °C</span><?php endif; ?>
                <?php if ($session['PRESSION_HPA'] !== null): ?><span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= e(round((float) $session['PRESSION_HPA'])) ?> hPa</span><?php endif; ?>
                <?php if ($session['VITESSE_VENT'] !== null): ?><span class="badge bg-light text-dark rounded-pill px-3 py-2"><?= e($session['VITESSE_VENT']) ?> km/h <?= e($session['DIREC_VENT']) ?></span><?php endif; ?>
                <?php if ($session['DESC_MAREE']): ?><span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-2">Marée <?= e(mb_strtolower($session['DESC_MAREE'])) ?><?= $session['COEFF_MAREE'] ? ' • coef ' . e($session['COEFF_MAREE']) : '' ?></span><?php endif; ?>
            </div>
            <p class="small text-muted text-center mt-3 mb-0"><span class="material-symbols-rounded align-middle fs-6">lock</span> Le lieu de pêche reste privé.</p>
        </div>

        <?php if (empty($prises)): ?>
            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;">sentiment_dissatisfied</span>
                <p class="text-muted mb-0">Session capot : aucune prise ce jour-là.</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($prises as $p): ?>
                    <?php $photos_p = $photos_par_prise[$p['ID_PRISE']] ?? []; ?>
                    <div class="col-12 col-md-6">
                        <div class="card border-0 shadow-sm rounded-4 h-100 bg-white overflow-hidden">
                            <?php foreach ($photos_p as $k => $ph): ?>
                                <div class="position-relative <?= $k > 0 ? 'd-none photo-supp-' . (int) $p['ID_PRISE'] : '' ?>">
                                    <img src="<?= e($ph['CHEMIN']) ?>" alt="Photo de <?= e($p['NOM_COM']) ?>" class="w-100" style="height: 240px; object-fit: cover;">
                                    <?php if ($est_moi && $ph['STATUT_MODERATION'] !== 'visible'): ?>
                                        <span class="badge bg-danger position-absolute top-0 start-0 m-2">Masquée par la modération</span>
                                    <?php elseif (!$est_moi): ?>
                                        <button type="button" class="btn btn-sm btn-dark bg-opacity-50 rounded-pill position-absolute top-0 end-0 m-2" data-bs-toggle="modal" data-bs-target="#modalSignalement" data-type="photo_prise" data-photo="<?= (int) $ph['ID_PHOTO'] ?>" title="Signaler cette photo">
                                            <span class="material-symbols-rounded align-middle" style="font-size:16px;">flag</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($photos_p) > 1): ?>
                                <button type="button" class="btn btn-sm btn-light rounded-0 w-100" onclick="document.querySelectorAll('.photo-supp-<?= (int) $p['ID_PRISE'] ?>').forEach(el => el.classList.toggle('d-none'));">
                                    Voir les <?= count($photos_p) ?> photos
                                </button>
                            <?php endif; ?>
                            <div class="card-body p-4">
                                <h2 class="h5 fw-bold text-dark mb-2 d-flex align-items-center">
                                    <?php if ($p['ICONE_CHEMIN']): ?><img src="<?= e($p['ICONE_CHEMIN']) ?>" alt="" style="width: 28px; height: 28px; object-fit: contain;" class="me-2"><?php endif; ?>
                                    <?php if ((int) $p['QUANTITE'] > 1): ?><span class="badge bg-primary rounded-pill me-2">x<?= (int) $p['QUANTITE'] ?></span><?php endif; ?>
                                    <?= e($p['NOM_COM']) ?>
                                    <?php if ($p['RELACHE']): ?><span class="badge bg-success bg-opacity-10 text-success rounded-pill ms-auto">No-Kill</span><?php endif; ?>
                                </h2>
                                <div class="text-muted small">
                                    <?php if ($p['TAILLE_CM']): ?><span class="me-3"><?= e($p['TAILLE_CM']) ?> cm</span><?php endif; ?>
                                    <?php if ($p['POIDS_KG']): ?><span class="me-3"><?= e($p['POIDS_KG']) ?> kg</span><?php endif; ?>
                                    <?php if ($p['NOM_LEURRE'] || $p['NOM_APPAT']): ?><span class="me-3"><?= e($p['NOM_LEURRE'] ?: $p['NOM_APPAT']) ?></span><?php endif; ?>
                                    <?php if ($p['NOM_TECHNIQUE']): ?><span><?= e($p['NOM_TECHNIQUE']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php include './includes/navbar.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if (!$est_moi) include './includes/modal_signalement.php'; ?>
</body>
</html>
