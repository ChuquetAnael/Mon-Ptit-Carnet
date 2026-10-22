<?php
// Classements du mois : par espèce, assiduité, polyvalence, no-kill / total.
// Seuls les membres qui partagent leurs prises (Paramètres) et leurs sessions visibles comptent.
require_once './includes/auth_bdd.php';
require_once './includes/communaute.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$periode = periode_classement($_GET['mois'] ?? null);
$vues = ['especes' => ['Espèces', 'set_meal'], 'assiduite' => ['Assiduité', 'schedule'], 'polyvalence' => ['Polyvalence', 'diversity_3'], 'nokill' => ['No-kill', 'eco']];
$vue = array_key_exists($_GET['vue'] ?? '', $vues) ? $_GET['vue'] : 'especes';

$stmt = $pdo->prepare("SELECT PARTAGE_COMMUNAUTE FROM utilisateur WHERE ID_UTILISATEUR = ?");
$stmt->execute([$id_user]);
$je_partage = (bool) $stmt->fetchColumn();

$donnees = null;
$especes_mois = [];
$espece_choisie = null;
if ($vue === 'especes') {
    $especes_mois = records_especes_mois($pdo, $periode);
    $id_espece = id_ou_null($_GET['espece'] ?? null) ?? (int) ($especes_mois[0]['ID_ESPECE'] ?? 0);
    foreach ($especes_mois as $e) if ((int) $e['ID_ESPECE'] === $id_espece) $espece_choisie = $e;
    if ($espece_choisie) $donnees = classement_espece($pdo, $periode, $id_espece, $id_user);
} elseif ($vue === 'assiduite') {
    $donnees = classement_assiduite($pdo, $periode, $id_user);
} elseif ($vue === 'polyvalence') {
    $donnees = classement_polyvalence($pdo, $periode, $id_user);
} else {
    $donnees = classement_nokill($pdo, $periode, $id_user);
}

function url_classement(array $periode, string $vue, array $extra = []): string
{
    return 'classements.php?' . http_build_query(array_merge(['vue' => $vue, 'mois' => $periode['ym']], $extra));
}

/** Liste classée : $valeur(ligne) donne le texte principal, $detail(ligne) le texte secondaire. */
function liste_classement(array $c, int $id_user, callable $valeur, ?callable $detail = null, string $vide = "Personne n'est encore classé ce mois-ci."): void
{
    if (!$c['top']) { echo '<p class="classement-vide">' . e($vide) . '</p>'; return; }
    echo '<ol class="classement">';
    $ligne = function (array $l, bool $separee = false) use ($id_user, $valeur, $detail) {
        $moi = (int) $l['ID_UTILISATEUR'] === $id_user;
        echo '<li class="' . ($moi ? 'moi ' : '') . ($separee ? 'separee ' : '') . 'rang-' . min(4, (int) $l['rang']) . '">';
        echo '<span class="classement-rang" aria-label="' . (int) $l['rang'] . 'e">' . (int) $l['rang'] . '</span>';
        echo '<a class="classement-pecheur" href="profil.php?id=' . (int) $l['ID_UTILISATEUR'] . '">';
        echo $l['PDP_CHEMIN'] ? '<img src="' . e($l['PDP_CHEMIN']) . '" alt="" loading="lazy">' : '<span class="classement-avatar"><span class="material-symbols-rounded" aria-hidden="true">person</span></span>';
        echo '<span class="min-w-0"><strong>' . e($l['PSEUDO']) . ($moi ? ' <small>(toi)</small>' : '') . '</strong>';
        if ($detail) echo '<small>' . e($detail($l)) . '</small>';
        echo '</span></a>';
        echo '<span class="classement-valeur">' . e($valeur($l)) . '</span>';
        echo '</li>';
    };
    foreach ($c['top'] as $l) $ligne($l);
    if ($c['moi']) $ligne($c['moi'], true);
    echo '</ol>';
    if ($c['participants'] > count($c['top'])) echo '<p class="small text-muted mt-2 mb-0">' . (int) $c['participants'] . ' pêcheurs classés ce mois-ci.</p>';
}
$nombre_fr = fn($v) => str_replace('.', ',', (string) (float) $v);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classements du mois - Mon P'tit Carnet</title>
    <?php include './includes/head.php'; ?>
</head>
<body class="avec-selecteur">
    <header class="custom-header text-white text-center py-4 shadow-sm mb-3 position-relative">
        <a href="accueil.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;" aria-label="Retour à l'accueil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Classements du mois</h1>
    </header>

    <main class="container pb-5 mb-5" style="max-width: 640px;">
        <nav class="mois-navigation mb-3" aria-label="Choisir le mois">
            <?php if ($periode['precedent']): ?>
                <a href="<?= e(url_classement(['ym' => $periode['precedent']] + $periode, $vue)) ?>" aria-label="Mois précédent"><span class="material-symbols-rounded">chevron_left</span></a>
            <?php else: ?><span></span><?php endif; ?>
            <strong><?= e(ucfirst($periode['libelle'])) ?><?= $periode['en_cours'] ? ' <small>(en cours)</small>' : '' ?></strong>
            <?php if ($periode['suivant']): ?>
                <a href="<?= e(url_classement(['ym' => $periode['suivant']] + $periode, $vue)) ?>" aria-label="Mois suivant"><span class="material-symbols-rounded">chevron_right</span></a>
            <?php else: ?><span></span><?php endif; ?>
        </nav>

        <?php if (!$je_partage): ?>
            <div class="alert alert-light border rounded-4 small">Tu n'apparais pas dans les classements : le partage communautaire est désactivé dans <a href="parametre.php">Paramètres</a>.</div>
        <?php endif; ?>

        <?php if ($vue === 'especes'): ?>
            <h2 class="titre-section"><span class="material-symbols-rounded" aria-hidden="true">set_meal</span> Par espèce</h2>
            <?php if (!$especes_mois): ?>
                <p class="classement-vide">Aucune prise partagée ce mois-ci.</p>
            <?php else: ?>
                <div class="especes-mois" role="list" aria-label="Espèces pêchées ce mois-ci">
                    <?php foreach ($especes_mois as $e): $actif = $espece_choisie && (int) $e['ID_ESPECE'] === (int) $espece_choisie['ID_ESPECE']; ?>
                        <a role="listitem" href="<?= e(url_classement($periode, 'especes', ['espece' => (int) $e['ID_ESPECE']])) ?>" class="espece-mois<?= $actif ? ' actif' : '' ?>"<?= $actif ? ' aria-current="true"' : '' ?>>
                            <?php if ($e['ICONE_CHEMIN']): ?><img src="<?= e(icone_legere($e['ICONE_CHEMIN'])) ?>" alt="" loading="lazy"><?php endif; ?>
                            <span><strong><?= e($e['NOM_COM']) ?></strong>
                                <small><?= (int) $e['nb_poissons'] ?> poisson<?= $e['nb_poissons'] > 1 ? 's' : '' ?><?= $e['record'] ? ' · record ' . (int) $e['record']['TAILLE_CM'] . ' cm' : '' ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($espece_choisie && $donnees): ?>
                    <section class="card border-0 shadow-sm rounded-4 p-3 mb-3">
                        <h3 class="h6 fw-bold mb-3">Plus gros <?= e(mb_strtolower($espece_choisie['NOM_COM'])) ?> du mois</h3>
                        <?php liste_classement($donnees['plus_gros'], $id_user,
                            fn($l) => (int) $l['TAILLE_CM'] . ' cm',
                            fn($l) => $l['POIDS_KG'] !== null && (float) $l['POIDS_KG'] > 0 ? $nombre_fr($l['POIDS_KG']) . ' kg' : 'poids non précisé',
                            "Aucune prise mesurée pour cette espèce ce mois-ci."); ?>
                    </section>
                    <section class="card border-0 shadow-sm rounded-4 p-3 mb-3">
                        <h3 class="h6 fw-bold mb-3">Le plus de prises</h3>
                        <?php liste_classement($donnees['nombre'], $id_user, fn($l) => (int) $l['nb'] . ' poisson' . ($l['nb'] > 1 ? 's' : '')); ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>

        <?php elseif ($vue === 'assiduite'): ?>
            <h2 class="titre-section"><span class="material-symbols-rounded" aria-hidden="true">schedule</span> Temps passé au bord de l'eau</h2>
            <section class="card border-0 shadow-sm rounded-4 p-3 mb-3">
                <?php liste_classement($donnees, $id_user, fn($l) => duree_lisible((int) $l['minutes']), fn($l) => $l['sorties'] . ' sortie' . ($l['sorties'] > 1 ? 's' : '')); ?>
            </section>
            <p class="small text-muted">Seules les sessions avec une heure de fin comptent. Deux sessions qui se chevauchent ne comptent qu'une fois, et une session compte au plus <?= CLASSEMENT_DUREE_MAX_H ?> h.</p>

        <?php elseif ($vue === 'polyvalence'): ?>
            <h2 class="titre-section"><span class="material-symbols-rounded" aria-hidden="true">diversity_3</span> Espèces différentes du codex</h2>
            <section class="card border-0 shadow-sm rounded-4 p-3 mb-3">
                <?php liste_classement($donnees, $id_user, fn($l) => (int) $l['especes'] . ' espèce' . ($l['especes'] > 1 ? 's' : ''), fn($l) => (int) $l['poissons'] . ' poisson' . ($l['poissons'] > 1 ? 's' : '')); ?>
            </section>

        <?php else: ?>
            <h2 class="titre-section"><span class="material-symbols-rounded" aria-hidden="true">eco</span> Poissons relâchés</h2>
            <section class="card border-0 shadow-sm rounded-4 p-3 mb-3">
                <?php liste_classement($donnees['nokill'], $id_user, fn($l) => (int) $l['relaches'] . ' relâché' . ($l['relaches'] > 1 ? 's' : ''), fn($l) => $l['taux'] . ' % de no-kill sur ' . (int) $l['total'] . ' poisson' . ($l['total'] > 1 ? 's' : ''), "Aucun poisson relâché ce mois-ci."); ?>
            </section>
            <h2 class="titre-section"><span class="material-symbols-rounded" aria-hidden="true">phishing</span> Total des prises</h2>
            <section class="card border-0 shadow-sm rounded-4 p-3 mb-3">
                <?php liste_classement($donnees['total'], $id_user, fn($l) => (int) $l['total'] . ' poisson' . ($l['total'] > 1 ? 's' : ''), fn($l) => (int) $l['relaches'] . ' relâché' . ($l['relaches'] > 1 ? 's' : '')); ?>
            </section>
        <?php endif; ?>

        <p class="small text-muted mt-4">
            Sont classés les membres qui partagent leurs prises, sur leurs sessions visibles du mois. Les tailles et poids impossibles pour l'espèce sont écartés, et une ligne de prise compte au plus pour <?= CLASSEMENT_QUANTITE_MAX_PAR_LIGNE ?> poissons.
        </p>
    </main>

    <nav class="selecteur-vue" aria-label="Choisir le classement">
        <?php foreach ($vues as $cle => [$nom, $icone]): ?>
            <a href="<?= e(url_classement($periode, $cle)) ?>"<?= $cle === $vue ? ' aria-current="page"' : '' ?>><span class="material-symbols-rounded" aria-hidden="true"><?= $icone ?></span><?= e($nom) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php include './includes/navbar.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
