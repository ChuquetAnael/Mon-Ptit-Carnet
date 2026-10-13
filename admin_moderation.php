<?php
// Modération des images publiées (signalements + revue des photos publiques récentes)
require_once './includes/bootstrap.php';
$id_admin = exiger_admin();
$pdo = db();
$message_succes = '';

/** Clôt les signalements ouverts d'un contenu. */
function cloturerSignalements(PDO $pdo, string $type, ?int $id_photo, int $id_cible): void
{
    $pdo->prepare("
        UPDATE signalement SET STATUT = 'traite'
        WHERE STATUT = 'ouvert' AND TYPE_CONTENU = ? AND ID_UTILISATEUR_CIBLE = ? AND (ID_PHOTO <=> ?)
    ")->execute([$type, $id_cible, $id_photo]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id_photo = id_ou_null($_POST['id_photo'] ?? null);
    $id_cible = id_ou_null($_POST['id_cible'] ?? null);

    if ($id_photo && in_array($action, ['conserver', 'masquer', 'supprimer'], true)) {
        $stmt = $pdo->prepare("
            SELECT ph.CHEMIN, s.ID_UTILISATEUR FROM photo_prise ph
            JOIN prise p ON ph.ID_PRISE = p.ID_PRISE JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
            WHERE ph.ID_PHOTO = ?
        ");
        $stmt->execute([$id_photo]);
        if ($photo = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $proprietaire = (int) $photo['ID_UTILISATEUR'];
            if ($action === 'conserver') {
                $pdo->prepare("UPDATE photo_prise SET STATUT_MODERATION = 'visible' WHERE ID_PHOTO = ?")->execute([$id_photo]);
                cloturerSignalements($pdo, 'photo_prise', $id_photo, $proprietaire);
                $message_succes = "Photo conservée, signalements clos.";
            } elseif ($action === 'masquer') {
                $pdo->prepare("UPDATE photo_prise SET STATUT_MODERATION = 'masquee' WHERE ID_PHOTO = ?")->execute([$id_photo]);
                cloturerSignalements($pdo, 'photo_prise', $id_photo, $proprietaire);
                $message_succes = "Photo masquée aux autres membres (son auteur la voit encore).";
            } else {
                $pdo->prepare("DELETE FROM photo_prise WHERE ID_PHOTO = ?")->execute([$id_photo]); // signalements : ON DELETE CASCADE
                supprimer_fichier_utilisateur($photo['CHEMIN'], $proprietaire);
                $message_succes = "Photo supprimée définitivement.";
            }
        }
    } elseif ($id_cible && in_array($action, ['conserver_profil', 'supprimer_profil'], true)) {
        $type = ($_POST['type'] ?? '') === 'banniere' ? 'banniere' : 'photo_profil';
        $colonne = $type === 'banniere' ? 'BANNIERE_CHEMIN' : 'PDP_CHEMIN';
        if ($action === 'supprimer_profil') {
            $stmt = $pdo->prepare("SELECT $colonne FROM utilisateur WHERE ID_UTILISATEUR = ?");
            $stmt->execute([$id_cible]);
            supprimer_fichier_utilisateur($stmt->fetchColumn() ?: null, $id_cible);
            $pdo->prepare("UPDATE utilisateur SET $colonne = NULL WHERE ID_UTILISATEUR = ?")->execute([$id_cible]);
            $message_succes = "Image de profil supprimée.";
        } else {
            $message_succes = "Image conservée, signalements clos.";
        }
        cloturerSignalements($pdo, $type, null, $id_cible);
    }
}

// 1. Signalements ouverts, regroupés par contenu
$signalements = $pdo->query("
    SELECT sg.TYPE_CONTENU, sg.ID_PHOTO, sg.ID_UTILISATEUR_CIBLE, u.PSEUDO,
           COUNT(*) AS nb, GROUP_CONCAT(DISTINCT sg.MOTIF SEPARATOR ' • ') AS motifs,
           GROUP_CONCAT(sg.COMMENTAIRE SEPARATOR ' | ') AS commentaires, MAX(sg.DATE_SIGNALEMENT) AS dernier,
           CASE sg.TYPE_CONTENU WHEN 'photo_prise' THEN ph.CHEMIN WHEN 'photo_profil' THEN u.PDP_CHEMIN ELSE u.BANNIERE_CHEMIN END AS image
    FROM signalement sg
    JOIN utilisateur u ON sg.ID_UTILISATEUR_CIBLE = u.ID_UTILISATEUR
    LEFT JOIN photo_prise ph ON sg.ID_PHOTO = ph.ID_PHOTO
    WHERE sg.STATUT = 'ouvert'
    GROUP BY sg.TYPE_CONTENU, sg.ID_PHOTO, sg.ID_UTILISATEUR_CIBLE, u.PSEUDO, image
    ORDER BY nb DESC, dernier DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Photos rendues publiques récemment (sessions épinglées) : revue proactive
$photos_publiques = $pdo->query("
    SELECT ph.ID_PHOTO, ph.CHEMIN, ph.STATUT_MODERATION, ph.DATE_AJOUT, u.PSEUDO, s.ID_SESSION
    FROM photo_prise ph
    JOIN prise p ON ph.ID_PRISE = p.ID_PRISE
    JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
    JOIN utilisateur u ON s.ID_UTILISATEUR = u.ID_UTILISATEUR
    WHERE s.EPINGLEE = 1
    ORDER BY ph.DATE_AJOUT DESC
    LIMIT 48
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Photos de profil / bannières des derniers inscrits
$profils_recents = $pdo->query("
    SELECT ID_UTILISATEUR, PSEUDO, PDP_CHEMIN, BANNIERE_CHEMIN FROM utilisateur
    WHERE PDP_CHEMIN IS NOT NULL OR BANNIERE_CHEMIN IS NOT NULL
    ORDER BY ID_UTILISATEUR DESC LIMIT 24
")->fetchAll(PDO::FETCH_ASSOC);

function libelleType(string $type): string
{
    return ['photo_prise' => 'Photo de prise', 'photo_profil' => 'Photo de profil', 'banniere' => 'Bannière'][$type] ?? $type;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Modération des images</title>
    <?php include './includes/head.php'; ?>
    <style>
        .thumb { width: 100%; height: 160px; object-fit: cover; }
    </style>
</head>
<body class="bg-light pb-5">

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="admin.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;" aria-label="Retour">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center">
            <span class="material-symbols-rounded me-2">shield</span> Modération
        </h1>
    </header>

    <main class="container">
        <?php if ($message_succes): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm text-center"><?= e($message_succes) ?></div>
        <?php endif; ?>

        <ul class="nav nav-pills nav-fill mb-4 bg-white rounded-pill shadow-sm p-1" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill fw-bold" data-bs-toggle="tab" data-bs-target="#tab-signalements" type="button" role="tab">
                    Signalements <span class="badge bg-danger ms-1 rounded-pill"><?= count($signalements) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill fw-bold" data-bs-toggle="tab" data-bs-target="#tab-publiques" type="button" role="tab">Photos publiques</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill fw-bold" data-bs-toggle="tab" data-bs-target="#tab-profils" type="button" role="tab">Profils</button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- SIGNALEMENTS -->
            <div class="tab-pane fade show active" id="tab-signalements" role="tabpanel">
                <?php if (empty($signalements)): ?>
                    <div class="text-center text-muted mt-5">
                        <span class="material-symbols-rounded mb-2" style="font-size: 60px;">verified_user</span>
                        <p>Aucun signalement en attente.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($signalements as $sg): ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                                    <?php if ($sg['image']): ?>
                                        <img src="<?= e($sg['image']) ?>" class="thumb" alt="Image signalée">
                                    <?php else: ?>
                                        <div class="thumb bg-light d-flex align-items-center justify-content-center text-muted">Image déjà supprimée</div>
                                    <?php endif; ?>
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="badge bg-secondary"><?= e(libelleType($sg['TYPE_CONTENU'])) ?></span>
                                            <span class="badge bg-danger"><?= (int) $sg['nb'] ?> signalement(s)</span>
                                        </div>
                                        <p class="small fw-bold mb-1">Auteur : <?= e($sg['PSEUDO']) ?></p>
                                        <p class="small text-danger mb-1"><?= e($sg['motifs']) ?></p>
                                        <?php if ($sg['commentaires']): ?><p class="small text-muted fst-italic mb-2"><?= e($sg['commentaires']) ?></p><?php endif; ?>

                                        <form method="POST" class="d-flex gap-2 mt-2">
                                            <?= csrf_field() ?>
                                            <?php if ($sg['TYPE_CONTENU'] === 'photo_prise'): ?>
                                                <input type="hidden" name="id_photo" value="<?= (int) $sg['ID_PHOTO'] ?>">
                                                <button name="action" value="conserver" class="btn btn-sm btn-outline-success rounded-pill flex-fill">Conserver</button>
                                                <button name="action" value="masquer" class="btn btn-sm btn-outline-warning rounded-pill flex-fill">Masquer</button>
                                                <button name="action" value="supprimer" class="btn btn-sm btn-danger rounded-pill flex-fill" onclick="return confirm('Supprimer définitivement cette photo ?');">Supprimer</button>
                                            <?php else: ?>
                                                <input type="hidden" name="id_cible" value="<?= (int) $sg['ID_UTILISATEUR_CIBLE'] ?>">
                                                <input type="hidden" name="type" value="<?= e($sg['TYPE_CONTENU']) ?>">
                                                <button name="action" value="conserver_profil" class="btn btn-sm btn-outline-success rounded-pill flex-fill">Conserver</button>
                                                <button name="action" value="supprimer_profil" class="btn btn-sm btn-danger rounded-pill flex-fill" onclick="return confirm('Supprimer cette image de profil ?');">Supprimer</button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PHOTOS PUBLIQUES RÉCENTES -->
            <div class="tab-pane fade" id="tab-publiques" role="tabpanel">
                <p class="small text-muted">Photos des sessions épinglées (visibles par tous les membres), les plus récentes d'abord.</p>
                <div class="row g-2">
                    <?php foreach ($photos_publiques as $ph): ?>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                                <img src="<?= e($ph['CHEMIN']) ?>" class="thumb" alt="Photo publique">
                                <div class="card-body p-2">
                                    <small class="d-block fw-bold"><?= e($ph['PSEUDO']) ?></small>
                                    <small class="d-block text-muted"><?= e(date('d/m H:i', strtotime($ph['DATE_AJOUT']))) ?></small>
                                    <?php if ($ph['STATUT_MODERATION'] !== 'visible'): ?><span class="badge bg-warning text-dark"><?= e($ph['STATUT_MODERATION']) ?></span><?php endif; ?>
                                    <form method="POST" class="d-flex gap-1 mt-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id_photo" value="<?= (int) $ph['ID_PHOTO'] ?>">
                                        <?php if ($ph['STATUT_MODERATION'] === 'visible'): ?>
                                            <button name="action" value="masquer" class="btn btn-sm btn-outline-warning rounded-pill flex-fill">Masquer</button>
                                        <?php else: ?>
                                            <button name="action" value="conserver" class="btn btn-sm btn-outline-success rounded-pill flex-fill">Rétablir</button>
                                        <?php endif; ?>
                                        <button name="action" value="supprimer" class="btn btn-sm btn-danger rounded-pill" onclick="return confirm('Supprimer définitivement cette photo ?');" aria-label="Supprimer"><span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($photos_publiques)): ?><p class="text-muted text-center my-4">Aucune photo publique pour le moment.</p><?php endif; ?>
                </div>
            </div>

            <!-- PROFILS -->
            <div class="tab-pane fade" id="tab-profils" role="tabpanel">
                <div class="row g-2">
                    <?php foreach ($profils_recents as $u): ?>
                        <?php foreach (['photo_profil' => $u['PDP_CHEMIN'], 'banniere' => $u['BANNIERE_CHEMIN']] as $type => $chemin): ?>
                            <?php if (!$chemin) continue; ?>
                            <div class="col-6 col-md-3">
                                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                                    <img src="<?= e($chemin) ?>" class="thumb" alt="<?= e(libelleType($type)) ?>">
                                    <div class="card-body p-2">
                                        <small class="d-block fw-bold"><?= e($u['PSEUDO']) ?></small>
                                        <small class="d-block text-muted"><?= e(libelleType($type)) ?></small>
                                        <form method="POST" class="mt-2">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_cible" value="<?= (int) $u['ID_UTILISATEUR'] ?>">
                                            <input type="hidden" name="type" value="<?= e($type) ?>">
                                            <button name="action" value="supprimer_profil" class="btn btn-sm btn-danger rounded-pill w-100" onclick="return confirm('Supprimer cette image ?');">Supprimer</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
