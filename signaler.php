<?php
// Réception d'un signalement d'image (modération a posteriori prévue par les CGU)
require_once './includes/auth_bdd.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: accueil.php');
    exit();
}

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$type = $_POST['type'] ?? '';
$motif = in_array($_POST['motif'] ?? '', MOTIFS_SIGNALEMENT, true) ? $_POST['motif'] : 'Autre';
$commentaire = mb_substr(trim((string) ($_POST['commentaire'] ?? '')), 0, 255) ?: null;

// Retour uniquement vers une page interne connue (pas de redirection ouverte)
$retour = (string) ($_POST['retour'] ?? '');
if (!preg_match('/^(profil|session_publique)\.php\?id=\d+$/', $retour)) $retour = 'accueil.php';

$id_photo = null;
$id_cible = null;

if ($type === 'photo_prise') {
    // On ne peut signaler qu'une photo qu'on peut voir : session épinglée d'un AUTRE membre
    $id_photo = id_ou_null($_POST['id_photo'] ?? null);
    $stmt = $pdo->prepare("
        SELECT s.ID_UTILISATEUR FROM photo_prise ph
        JOIN prise p ON ph.ID_PRISE = p.ID_PRISE
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        WHERE ph.ID_PHOTO = ? AND s.EPINGLEE = 1 AND s.ID_UTILISATEUR <> ?
    ");
    $stmt->execute([$id_photo, $id_user]);
    $id_cible = $stmt->fetchColumn();
} elseif ($type === 'photo_profil' || $type === 'banniere') {
    $colonne = $type === 'photo_profil' ? 'PDP_CHEMIN' : 'BANNIERE_CHEMIN';
    $id = id_ou_null($_POST['id_cible'] ?? null);
    $stmt = $pdo->prepare("SELECT ID_UTILISATEUR FROM utilisateur WHERE ID_UTILISATEUR = ? AND ID_UTILISATEUR <> ? AND $colonne IS NOT NULL");
    $stmt->execute([$id, $id_user]);
    $id_cible = $stmt->fetchColumn();
}

if (!$id_cible) {
    app_page_message('Signalement impossible', "Cette image n'existe plus ou ne peut pas être signalée.", 404);
}

// Un même membre ne signale pas deux fois le même contenu
$stmtDoublon = $pdo->prepare("
    SELECT 1 FROM signalement
    WHERE ID_SIGNALEUR = ? AND TYPE_CONTENU = ? AND STATUT = 'ouvert'
      AND ID_UTILISATEUR_CIBLE = ? AND (ID_PHOTO <=> ?)
");
$stmtDoublon->execute([$id_user, $type, $id_cible, $id_photo]);

if (!$stmtDoublon->fetchColumn()) {
    $pdo->prepare("
        INSERT INTO signalement (ID_SIGNALEUR, TYPE_CONTENU, ID_PHOTO, ID_UTILISATEUR_CIBLE, MOTIF, COMMENTAIRE)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$id_user, $type, $id_photo, $id_cible, $motif, $commentaire]);

    // Masquage préventif d'une photo de prise dès le seuil atteint, en attendant l'administrateur
    if ($id_photo) {
        $stmtNb = $pdo->prepare("SELECT COUNT(*) FROM signalement WHERE ID_PHOTO = ? AND STATUT = 'ouvert'");
        $stmtNb->execute([$id_photo]);
        if ($stmtNb->fetchColumn() >= SEUIL_SIGNALEMENTS_MASQUAGE) {
            $pdo->prepare("UPDATE photo_prise SET STATUT_MODERATION = 'signalee' WHERE ID_PHOTO = ? AND STATUT_MODERATION = 'visible'")->execute([$id_photo]);
        }
    }
}

header('Location: ' . $retour . (strpos($retour, '?') === false ? '?' : '&') . 'signale=1');
exit();
