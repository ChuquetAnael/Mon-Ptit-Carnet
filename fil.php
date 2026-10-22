<?php
// Fil d'actualité de la communauté : dernières prises partagées (sans lieu précis ni GPS).
// ?partiel=1&avant=… : renvoie la suite du fil en JSON (bouton « Voir plus »).
require_once './includes/auth_bdd.php';
require_once './includes/communaute.php';

$pdo = db();
$avant = isset($_GET['avant']) ? (string) $_GET['avant'] : null;
$fil = fil_actualite($pdo, FIL_PAR_PAGE, $avant);

if (isset($_GET['partiel'])) {
    ob_start();
    foreach ($fil['prises'] as $p) carte_fil($p);
    json_out(['html' => ob_get_clean(), 'suivant' => $fil['suivant']]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fil de la communauté - Mon P'tit Carnet</title>
    <?php include './includes/head.php'; ?>
</head>
<body>
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="accueil.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;" aria-label="Retour à l'accueil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Au bord de l'eau</h1>
        <small class="text-white-50">Les dernières prises de la communauté</small>
    </header>

    <main class="container pb-5 mb-5" style="max-width: 640px;">
        <a href="classements.php" class="lien-classements mb-3">
            <span class="material-symbols-rounded" aria-hidden="true">military_tech</span>
            <span class="flex-grow-1"><strong class="d-block">Classements du mois</strong><small>Plus gros poissons, assiduité, polyvalence, no-kill</small></span>
            <span class="material-symbols-rounded" aria-hidden="true">chevron_right</span>
        </a>

        <?php if (!$fil['prises']): ?>
            <div class="card border-0 shadow-sm rounded-4 p-5 text-center">
                <span class="material-symbols-rounded text-muted mb-2" style="font-size: 48px;" aria-hidden="true">photo_camera</span>
                <p class="text-muted mb-0">Aucune prise partagée pour l'instant. Les prises avec photo de sessions visibles apparaissent ici.</p>
            </div>
        <?php else: ?>
            <div class="fil" id="fil">
                <?php foreach ($fil['prises'] as $p) carte_fil($p); ?>
            </div>
            <?php if ($fil['suivant']): ?>
                <div class="d-grid mt-3">
                    <a href="fil.php?avant=<?= e(urlencode($fil['suivant'])) ?>" class="btn btn-light rounded-pill py-3 fw-bold" id="fil-plus" data-suivant="<?= e($fil['suivant']) ?>">Voir plus de prises</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <p class="small text-muted mt-4 mb-0">
            <span class="material-symbols-rounded align-middle fs-6" aria-hidden="true">lock</span>
            Jamais de spot ni de GPS : seuls le milieu et le département sont indiqués. Tu peux retirer tes prises du fil dans
            <a href="parametre.php">Paramètres</a> ou masquer une session depuis ton profil.
        </p>
    </main>

    <?php include './includes/navbar.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script/fil.js?v=4"></script>
</body>
</html>
